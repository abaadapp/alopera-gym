<?php

namespace Tests\Feature;

use App\Core\Backup\RestoreFiles;
use App\Core\Backup\TenantBackupService;
use App\Core\Backup\TenantTables;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * The backup and the restore when the process really stops (SIGKILL: no catch, no finally, no shutdown — what a power
 * cut or a killed PHP leaves) and when the club is used meanwhile, on real PostgreSQL in processes of their own
 * (RunsConcurrently: a database of its own, committed rows). After each stop, what the next run finds is checked
 * row by row and file by file, then finished by recovery: back to the club as it was, or forward to the archive.
 */
class BackupConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function service(): TenantBackupService
    {
        return app(TenantBackupService::class);
    }

    private function pdf(string $marker, int $kb = 2): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n{$marker}-".bin2hex(random_bytes($kb * 512))."\n%%EOF\n");

        return new UploadedFile($path, $marker.'.pdf', 'application/pdf', null, true);
    }

    private function document(Tenant $club, User $owner, Member $member, string $marker): void
    {
        $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'contract', 'title' => $marker, 'file' => $this->pdf($marker)])->assertSessionHasNoErrors();
    }

    /** Every row of every club table, and every file of the club with its SHA-256. */
    private function state(Tenant $club): array
    {
        $rows = [];
        foreach (TenantTables::ORDER as $table) {
            $query = isset(TenantTables::PIVOTS[$table])
                ? DB::table($table)->whereIn(TenantTables::PIVOTS[$table][0], DB::table(TenantTables::PIVOTS[$table][1])->where('tenant_id', $club->id)->select('id'))
                : DB::table($table)->where('tenant_id', $club->id)->orderBy('id');
            $rows[$table] = md5((string) json_encode($query->get()));
        }
        $files = [];
        foreach (['local', 'public'] as $disk) {
            foreach (Storage::disk($disk)->allFiles('tenants/'.$club->id) as $file) {
                $files[$disk.':'.$file] = hash('sha256', (string) Storage::disk($disk)->get($file));
            }
        }
        ksort($files);

        return ['rows' => $rows, 'files' => $files];
    }

    /**
     * The archive taken in state A, then state B: her name changed, her contract replaced at the same path, the site
     * image changed, a second document added.
     *
     * @return array{0: Tenant, 1: string, 2: array, 3: array}
     */
    private function archivedThenChanged(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['full_name' => 'سارة']);
        $this->document($club, $owner, $member, 'A-CONTRACT');
        $this->document($club, $owner, $member, 'A-ID');
        $contract = $this->asTenant($club, fn () => MemberDocument::orderBy('id')->first());
        Storage::disk('public')->put('tenants/'.$club->id.'/site/hero.png', $this->png(200));

        $archive = $this->service()->disk()->path($this->service()->directory($club).'/'.$this->service()->create($club));
        $a = $this->state($club);

        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        Storage::disk('local')->put($contract->path, '%PDF-1.4 B-CONTRACT %%EOF');
        Storage::disk('public')->put('tenants/'.$club->id.'/site/hero.png', $this->png(40));
        $this->document($club, $owner, $member, 'B-NEW');

        return [$club, $archive, $a, $this->state($club)];
    }

    private function png(int $shade): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, 10, 10));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Runs $work in a process of its own, which kills itself (SIGKILL) when $crash->at says. */
    private function killedDuring(callable $work, CrashingRestoreFiles $crash, ?callable $arm = null): void
    {
        [$result] = $this->concurrently([function () use ($work, $crash, $arm) {
            app()->instance(RestoreFiles::class, $crash);
            $arm && $arm();
            $work();

            return 'finished';
        }]);
        $this->assertSame('killed before it finished', $result['error'], 'the process was killed where asked: '.json_encode($result));
    }

    private function staging(): array
    {
        return Storage::disk('local')->allFiles(TenantBackupService::STAGING);
    }

    public static function stops(): array
    {
        return [
            // where the process dies => [what it is killed at, the rows when it dies, the files when it dies]
            'staging, the second file' => [['put' => 2], 'B', 'B'],
            'before the first move' => [['move' => [1, 'before']], 'B', 'B'],
            'after the first move' => [['move' => [1, 'after']], 'B', 'changed'],
            'after the last move' => [['move' => [-1, 'after']], 'B', 'changed'],
        ];
    }

    /**
     * Killed anywhere before the commit: the database has rolled the rows back by itself; the files are as they were
     * or half moved. Recovery puts every file back: the club as it was, no staging left, no archive lost.
     */
    #[DataProvider('stops')]
    public function test_a_restore_killed_before_its_commit_is_put_back_whole(array $at, string $rows, string $files): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $archives = $this->service()->list($club);

        $this->killedDuring(fn () => $this->service()->restore($club, $archive), new CrashingRestoreFiles($at));

        $stopped = $this->state($club);
        $this->assertSame($b['rows'], $stopped['rows'], 'the rows rolled back by the database itself');
        $files === 'B' ? $this->assertSame($b['files'], $stopped['files']) : $this->assertNotSame($b['files'], $stopped['files'], 'half moved');
        $this->assertNotEmpty($this->staging(), 'the run is on disk for the next one');
        $this->assertSame(0, AuditLog::where('event', 'backup.restored')->count(), 'never recorded as restored');

        $this->service()->recover($club);
        $this->assertSame($b, $this->state($club), 'recovered: every row and file as before the restore');
        $this->assertSame([], $this->staging());
        $this->assertEquals($archives, $this->service()->list($club), 'no archive lost or added');
    }

    public static function statements(): array
    {
        return ['half-way through the rows' => ['insert into "gym_member_documents"'], 'right after its commit marker' => ['insert into "audit_logs"']];
    }

    /** Killed inside the rows' transaction: the database rolls the rows back by itself; recovery puts the files back. */
    #[DataProvider('statements')]
    public function test_a_restore_killed_inside_the_database_transaction_is_put_back_whole(string $statement): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $this->killedDuring(fn () => $this->service()->restore($club, $archive), new CrashingRestoreFiles([]), function () use ($statement) {
            DB::listen(function ($query) use ($statement) {
                if (str_starts_with($query->sql, $statement) && ($statement !== 'insert into "audit_logs"' || in_array('backup.restore_committed', $query->bindings, true))) {
                    posix_kill(getmypid(), SIGKILL);
                }
            });
        });
        $this->assertSame($b['rows'], $this->state($club)['rows'], 'rolled back');
        $this->service()->recover($club);
        $this->assertSame($b, $this->state($club), 'recovered');
        $this->assertSame([], $this->staging());
    }

    /** Killed right after the commit, before the clean-up: the rows are the archive's; recovery finishes forward. */
    public function test_a_restore_killed_after_its_commit_is_finished_forward(): void
    {
        [$club, $archive, $a] = $this->archivedThenChanged();
        $this->killedDuring(fn () => $this->service()->restore($club, $archive), new CrashingRestoreFiles([]), function () {
            DB::listen(function ($query) {
                if (str_starts_with($query->sql, 'insert into "audit_logs"') && in_array('backup.restore_committed', $query->bindings, true)) {
                    DB::afterCommit(fn () => posix_kill(getmypid(), SIGKILL));
                }
            });
        });
        $this->assertSame($a['rows'], $this->state($club)['rows'], 'committed');
        $this->assertNotEmpty($this->staging());

        $this->service()->recover($club);
        $after = $this->state($club);
        $this->assertSame($a['rows'], $after['rows']);
        $this->assertSame($a['files'], array_intersect_key($after['files'], $a['files']), 'every file of the archive in place');
        $this->assertSame([], $this->staging());
        $this->assertSame('forward', AuditLog::where('event', 'backup.restore_recovered')->latest('id')->value('properties')['direction']);
    }

    /** Recovery itself killed half-way: run again, it ends where it should; then the restore asked again goes through. */
    public function test_a_recovery_killed_half_way_is_finished_by_the_next_and_the_restore_then_goes_through(): void
    {
        [$club, $archive, $a, $b] = $this->archivedThenChanged();
        $this->killedDuring(fn () => $this->service()->restore($club, $archive), new CrashingRestoreFiles(['move' => [-1, 'after']]));
        // Recovery killed once every file is back, before its clean-up: among them her ID, unchanged since the archive,
        // whose bytes are the archive's. The next recovery must know it was hers before, not the restore's.
        $this->killedDuring(fn () => $this->service()->recover($club), new CrashingRestoreFiles(['move' => [3, 'after']]));
        $this->assertNotEmpty($this->staging(), 'killed before its clean-up');

        $this->service()->recover($club);
        $this->assertSame($b, $this->state($club));

        // The lock of the killed processes went with them: nothing waits for it.
        $this->service()->restoreWithSafetyCopy($club, $archive);
        $this->assertSame($a['rows'], $this->state($club)['rows']);
        $this->assertSame([], $this->staging());
    }

    /** A save while the backup reads the rows: the archive is the moment it started (one snapshot), and restores. */
    public function test_a_save_during_the_backup_leaves_an_archive_that_restores(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['full_name' => 'سارة']);
        $this->document($club, $owner, $member, 'A');

        $results = $this->concurrently([
            function (string $dir) use ($club) {
                self::pauseAfter('select * from "gym_members"', $dir, 1.5);

                return $this->service()->create($club);
            },
            function (string $dir) use ($club, $owner) {
                self::waitFor("$dir/inside");
                $late = $this->member($club, ['full_name' => 'عضوة جديدة']);
                $this->asTenant($club, fn () => MemberDocument::create(['member_id' => $late->id, 'type' => 'id', 'title' => 'هوية', 'path' => 'x', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $owner->id]));

                return 'saved';
            },
        ]);
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertSame('saved', $results[1]['value']);

        $file = $this->service()->disk()->path($this->service()->directory($club).'/'.$results[0]['value']);
        $this->service()->verifyArchive($file);
        $this->asTenant($club, fn () => $this->assertSame([2, 2], [Member::count(), MemberDocument::count()]), 'both saved');
        $this->service()->restore($club, $file);
        $this->asTenant($club, fn () => $this->assertSame([1, 1], [Member::count(), MemberDocument::count()]), 'the archive: the club when the backup began');
    }

    /** Killed while writing the archive: no archive lost, none recorded; the next backup clears what was left. */
    public function test_a_backup_killed_while_writing_loses_nothing_and_the_next_one_clears_up(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $this->document($club, $owner, $member, 'A');
        $first = $this->service()->create($club);
        // Enough bytes that writing the archive takes a moment to be caught in.
        Storage::disk('local')->put('tenants/'.$club->id.'/members/'.$member->id.'/big.pdf', random_bytes(96 * 1024 * 1024));
        $dir = $this->service()->disk()->path($this->service()->directory($club));

        $results = $this->concurrently([
            function (string $signals) use ($club) {
                file_put_contents("$signals/pid", (string) getmypid());

                return $this->service()->create($club);
            },
            function (string $signals) use ($dir) {
                self::waitFor("$signals/pid");
                $until = microtime(true) + 20;
                while (microtime(true) < $until && ! glob($dir.'/'.TenantBackupService::BUILDING.'*')) {
                    usleep(1000);
                }
                posix_kill((int) file_get_contents("$signals/pid"), SIGKILL);

                return glob($dir.'/'.TenantBackupService::BUILDING.'*') ? 'killed while building' : 'not caught';
            },
        ]);
        $this->assertSame('killed while building', $results[1]['value'], json_encode($results));
        $this->assertSame('killed before it finished', $results[0]['error']);

        $this->assertSame([$first], array_column($this->service()->list($club), 'name'), 'the kept archive only');
        $this->assertSame(1, AuditLog::where('event', 'backup.created')->count(), 'the killed one is not recorded');
        $this->service()->verifyArchive($dir.'/'.$first);

        $second = $this->service()->create($club);
        $this->assertSame([], glob($dir.'/'.TenantBackupService::BUILDING.'*'), 'what was left is cleared');
        $this->assertEqualsCanonicalizing([$first, $second], array_column($this->service()->list($club), 'name'));
        $this->service()->verifyArchive($dir.'/'.$second);
    }
}

/**
 * RestoreFiles that kill their process (SIGKILL) at a step: ['put' => 2] while staging the second file;
 * ['move' => [n, 'before'|'after']] at the n-th move (-1: the last one the journal announces).
 */
final class CrashingRestoreFiles extends RestoreFiles
{
    private array $calls = [];

    private int $moves = 0;

    public function __construct(private readonly array $at) {}

    private function step(string $op, string $when): void
    {
        if ($when === 'before') {
            $this->calls[$op] = ($this->calls[$op] ?? 0) + 1;
        }
        $rule = $this->at[$op] ?? null;
        if ($rule === null) {
            return;
        }
        [$n, $at] = is_array($rule) ? $rule : [$rule, 'before'];
        if ($at === $when && $this->calls[$op] === ($n === -1 ? $this->moves : $n)) {
            posix_kill(getmypid(), SIGKILL);
        }
    }

    public function putStream(string $disk, string $path, $stream, string $sha256): void
    {
        $this->step('put', 'before');
        parent::putStream($disk, $path, $stream, $sha256);
    }

    public function putString(string $disk, string $path, string $contents): void
    {
        if (! str_ends_with($path, '/journal.json')) {
            parent::putString($disk, $path, $contents);

            return;
        }
        $journal = json_decode($contents, true);
        $this->moves = count($journal['items'] ?? []) + count(array_filter($journal['items'] ?? [], fn ($i) => Storage::disk($i['disk'])->exists($i['path'])));
        parent::putString($disk, $path, $contents);
    }

    public function move(string $fromDisk, string $from, string $toDisk, string $to): void
    {
        $this->step('move', 'before');
        parent::move($fromDisk, $from, $toDisk, $to);
        $this->step('move', 'after');
    }
}
