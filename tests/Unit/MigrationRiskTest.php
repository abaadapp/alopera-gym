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

    public function test_the_deploy_stops_before_pulling_a_destructive_migration(): void
    {
        $git = function (string $cwd, string ...$args): string {
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

        $deploy = new Process(['bash', dirname(__DIR__, 2).'/scripts/deploy.sh'], $app, [
            'DEPLOY_DETACHED' => '1', 'APP_DIR' => $app, 'STATE_DIR' => $this->dir.'/state', 'PHP_BIN' => PHP_BINARY,
        ]);
        $deploy->run();

        $this->assertSame(1, $deploy->getExitCode(), $deploy->getOutput().$deploy->getErrorOutput());
        $this->assertStringContainsString('NOT REVIEWED', $deploy->getOutput());
        $this->assertStringContainsString('nothing was changed on the server', $deploy->getErrorOutput());
        $this->assertSame($deployed, $git($app, 'rev-parse', 'HEAD'), 'not pulled');
        $this->assertFileDoesNotExist($app.'/database/migrations/2030_01_01_000000_drop_old.php');
    }
}
