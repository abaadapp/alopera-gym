<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The deploy's check of new migrations (scripts/migration-risk.php, run by scripts/deploy.sh before it pulls): what
 * cannot be undone by putting the old code back stops the deploy before anything changes on the server.
 */
class MigrationRiskTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/alopera-migration-risk-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    private static function migration(string $up, string $down = '', string $doc = ''): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\n{$doc}\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n{$up}\n    }\n\n    public function down(): void\n    {\n{$down}\n    }\n};\n";
    }

    /** @return array{0: int, 1: string} */
    private function scan(string $source): array
    {
        file_put_contents($file = $this->dir.'/'.uniqid().'_migration.php', $source);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/migration-risk.php', $file]);
        $process->run();

        return [$process->getExitCode(), $process->getOutput()];
    }

    public function test_a_migration_that_only_adds_passes(): void
    {
        [$code, $out] = $this->scan(self::migration(
            "        Schema::create('t', function (Blueprint \$table) { \$table->id(); \$table->string('name')->index(); });\n        Schema::table('u', fn (Blueprint \$table) => \$table->string('x')->nullable());",
            "        Schema::dropIfExists('t');\n        Schema::table('u', fn (Blueprint \$table) => \$table->dropColumn('x'));",
            '/** Drops nothing; a comment saying dropColumn() is not code. */',
        ));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('adds only', $out);
    }

    /** @return iterable<string, array{string}> */
    public static function destructive(): iterable
    {
        yield 'drop a table' => ["        Schema::drop('t');"];
        yield 'drop a column' => ["        Schema::table('t', function (Blueprint \$table) { \$table->dropColumn(['a', 'b']); });"];
        yield 'rename a column' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->renameColumn('a', 'b'));"];
        yield 'change a column' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->integer('a')->change());"];
        yield 'delete rows' => ["        DB::table('t')->where('x', 1)->delete();"];
        yield 'raw drop' => ["        DB::statement('ALTER TABLE t DROP COLUMN a');"];
        yield 'raw type change' => ['        DB::statement("ALTER TABLE t ALTER COLUMN a TYPE bigint");'];
        yield 'drop columns through Schema' => ["        Schema::dropColumns('t', ['a', 'b']);"];
        yield 'rename a table from its blueprint' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->rename('u'));"];
        yield 'raw drop in a heredoc' => ["        DB::statement(<<<'SQL'\n            ALTER TABLE t DROP COLUMN a\n            SQL);"];
        yield 'raw drop put together' => ["        DB::statement('ALTER TABLE t '.'DROP '.'COLUMN a');"];
        yield 'drop a table from its blueprint' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->drop());"];
        yield 'drop the database' => ["        Schema::dropDatabaseIfExists('alopera');"];
        yield 'drop a foreign id column for a model' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->dropConstrainedForeignIdFor(\\App\\Models\\User::class));"];
        yield 'drop a unique key' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->dropUnique(['a', 'b']));"];
        yield 'drop a foreign key' => ["        Schema::table('t', fn (Blueprint \$table) => \$table->dropForeign(['a']));"];
        yield 'destroy models' => ['        \\App\\Models\\User::destroy([1, 2]);'];
        yield 'raw type change without COLUMN' => ["        DB::statement('ALTER TABLE t ALTER a TYPE bigint');"];
        yield 'raw drop kept in a variable' => ["        \$sql = \"ALTER TABLE t DROP COLUMN {\$this->column}\";\n        DB::statement(\$sql);"];
    }

    /** @return iterable<string, array{string}> */
    public static function rewrites(): iterable
    {
        yield 'update()' => ["        DB::table('t')->whereNotNull('a')->update(['b' => DB::raw('a')]);"];
        yield 'UPDATE in a heredoc' => ["        DB::statement(<<<'SQL'\n            UPDATE t SET b = a\n            SQL);"];
    }

    #[DataProvider('rewrites')]
    public function test_rewriting_rows_is_reported_without_stopping(string $up): void
    {
        [$code, $out] = $this->scan(self::migration($up));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('rewrites rows', $out);
        $this->assertStringNotContainsString('adds only', $out);
    }

    public function test_words_in_data_are_not_read_as_sql(): void
    {
        [$code, $out] = $this->scan(self::migration(
            "        DB::table('programs')->insert(['name' => 'Drop-in', 'note' => 'drop', 'permission' => 'members.delete']);\n        DB::statement('ALTER TABLE t ADD COLUMN type varchar(20)');",
        ));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('adds only', $out);
    }

    #[DataProvider('destructive')]
    public function test_what_cannot_be_undone_stops_unless_marked_as_reviewed(string $up): void
    {
        [$code, $out] = $this->scan(self::migration($up));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('NOT REVIEWED', $out);

        [$code, $out] = $this->scan(self::migration($up, doc: '/** Moves the data first, then drops the old column. @deploy-destructive-reviewed */'));
        $this->assertSame(0, $code, $out);
    }

    /** @var \Closure(string, string...): string */
    private \Closure $git;

    /**
     * A remote main with one commit deployed, then a commit adding a migration that drops a column.
     *
     * @return array{0: string, 1: string, 2: string} the server's tree, the deployed commit, main's new commit
     */
    private function serverAndMain(): array
    {
        $this->git = $git = function (string $cwd, string ...$args): string {
            $p = new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t.test', '-c', 'init.defaultBranch=main', ...$args], $cwd);
            $p->mustRun();

            return trim($p->getOutput());
        };
        $origin = $this->dir.'/origin.git';
        $work = $this->dir.'/work';
        $app = $this->dir.'/app';
        $git($this->dir, 'init', '--bare', '-q', $origin);
        $git($this->dir, 'clone', '-q', $origin, $work);
        mkdir($work.'/database/migrations', 0777, true);
        mkdir($work.'/scripts');
        file_put_contents($work.'/README', "x\n");
        $git($work, 'add', '.');
        $git($work, 'commit', '-q', '-m', 'first');
        $git($work, 'push', '-q', 'origin', 'HEAD:main');
        $git($this->dir, 'clone', '-q', '-b', 'main', $origin, $app);
        $deployed = $git($app, 'rev-parse', 'HEAD');

        copy(dirname(__DIR__, 2).'/scripts/migration-risk.php', $work.'/scripts/migration-risk.php');
        file_put_contents($work.'/database/migrations/2030_01_01_000000_drop_old.php', self::migration("        Schema::table('members', fn (Blueprint \$table) => \$table->dropColumn('phone'));"));
        $git($work, 'add', '.');
        $git($work, 'commit', '-q', '-m', 'drop a column');
        $git($work, 'push', '-q', 'origin', 'HEAD:main');

        return [$app, $deployed, $git($work, 'rev-parse', 'HEAD')];
    }

    private function deploy(string $app): Process
    {
        $deploy = new Process(['bash', dirname(__DIR__, 2).'/scripts/deploy.sh'], $app, [
            'DEPLOY_DETACHED' => '1', 'APP_DIR' => $app, 'STATE_DIR' => $this->dir.'/state', 'PHP_BIN' => PHP_BINARY,
        ]);
        $deploy->run();

        return $deploy;
    }

    public function test_the_deploy_stops_before_pulling_a_destructive_migration(): void
    {
        [$app, $deployed] = $this->serverAndMain();

        $deploy = $this->deploy($app);

        $this->assertSame(1, $deploy->getExitCode(), $deploy->getOutput().$deploy->getErrorOutput());
        $this->assertStringContainsString('NOT REVIEWED', $deploy->getOutput());
        $this->assertStringContainsString('nothing was changed on the server', $deploy->getErrorOutput());
        $this->assertSame($deployed, ($this->git)($app, 'rev-parse', 'HEAD'), 'not pulled');
        $this->assertFileDoesNotExist($app.'/database/migrations/2030_01_01_000000_drop_old.php');
    }

    public function test_a_deploy_that_pulled_then_failed_before_migrating_does_not_let_the_next_one_skip_the_check(): void
    {
        [$app, $deployed] = $this->serverAndMain();
        // The last deploy that finished, then one that pulled main (by hand, or an old deploy script) and failed
        // before migrating: the server's tree is main already, its migration has not run.
        mkdir($this->dir.'/state');
        file_put_contents($this->dir.'/state/DEPLOYED.txt', "v0.1\n{$deployed}\n2030-01-01T00:00:00+00:00\n");
        ($this->git)($app, 'pull', '-q', '--ff-only', 'origin', 'main');

        $deploy = $this->deploy($app);

        $this->assertSame(1, $deploy->getExitCode(), $deploy->getOutput().$deploy->getErrorOutput());
        $this->assertStringContainsString('NOT REVIEWED', $deploy->getOutput());
        $this->assertStringNotContainsString('nothing new', $deploy->getOutput(), 'stopped at the check, before going on');
    }

    public function test_an_unreadable_migrated_mark_falls_back_to_the_last_deploy(): void
    {
        [$app, $deployed] = $this->serverAndMain();
        mkdir($this->dir.'/state');
        file_put_contents($this->dir.'/state/MIGRATED.txt', "not a commit\n");
        file_put_contents($this->dir.'/state/DEPLOYED.txt', "v0.1\n{$deployed}\n2030-01-01T00:00:00+00:00\n");
        ($this->git)($app, 'pull', '-q', '--ff-only', 'origin', 'main');

        $deploy = $this->deploy($app);

        $this->assertSame(1, $deploy->getExitCode(), $deploy->getOutput().$deploy->getErrorOutput());
        $this->assertStringContainsString('NOT REVIEWED', $deploy->getOutput());
    }

    public function test_migrations_that_have_run_are_not_checked_again(): void
    {
        [$app, $deployed, $main] = $this->serverAndMain();
        // A deploy that migrated main, then failed later (a cache, the site check): MIGRATED.txt says so. The code was
        // then put back by hand (rollback-code.sh writes the older commit in DEPLOYED.txt): its migrations stay run.
        mkdir($this->dir.'/state');
        file_put_contents($this->dir.'/state/MIGRATED.txt', $main."\n");
        file_put_contents($this->dir.'/state/DEPLOYED.txt', "rollback\n{$deployed}\n2030-01-01T00:00:00+00:00\n");
        ($this->git)($app, 'pull', '-q', '--ff-only', 'origin', 'main');

        $deploy = $this->deploy($app);

        $this->assertStringNotContainsString('NOT REVIEWED', $deploy->getOutput(), $deploy->getErrorOutput());
        $this->assertStringContainsString('nothing new', $deploy->getOutput());
    }
}
