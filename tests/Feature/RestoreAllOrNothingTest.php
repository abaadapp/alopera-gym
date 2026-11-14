<?php

namespace Tests\Feature;

use App\Core\Backup\DiskSpace;
use App\Core\Backup\RestoreFiles;
use App\Core\Backup\TenantBackupService;
use App\Core\Backup\TenantTables;
use App\Core\Support\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Modules\Gym\Models\MemberDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\Support\FailingDisk;
use Tests\TestCase;
use ZipArchive;

/**
 * A restore is all or nothing (TenantBackupService::restore): a failure injected at each of its steps — reading the
 * archive, staging the files, writing the journal, replacing the rows, putting each file in place, the commit marker,
 * the clean-up after — leaves the club exactly as it was (every row of every club table, every file and its bytes), or,
 * after the commit, exactly as the archive. A run stopped half-way (putting the files back failed too: what a killed
 * process leaves) is finished by the next restore or recoverInterrupted(). Isolated test database and faked disks only.
 */
class RestoreAllOrNothingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private FaultyRestoreFiles $files;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->files = new FaultyRestoreFiles;
        $this->app->instance(RestoreFiles::class, $this->files);
    }

    private function service(): TenantBackupService
    {
        return app(TenantBackupService::class);
    }

    private function pdf(string $marker): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n{$marker}-".bin2hex(random_bytes(1024))."\n%%EOF\n");

        return new UploadedFile($path, $marker.'.pdf', 'application/pdf', null, true);
    }

    private function png(int $shade): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, 10, 10));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * The archive taken in state A, then the club moved on to state B: her name changed, her contract's file replaced
     * at the same path, the website image changed, a second document added.
     *
     * @return array{0: Tenant, 1: string, 2: array, 3: array, 4: MemberDocument} club, archive, state A, state B, contract
     */
    private function archivedThenChanged(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['full_name' => 'سارة']);
        $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'contract', 'title' => 'العقد', 'file' => $this->pdf('A-CONTRACT')])->assertSessionHasNoErrors();
        $contract = $this->asTenant($club, fn () => MemberDocument::sole());
        $image = 'tenants/'.$club->id.'/site/hero.png';
        Storage::disk('public')->put($image, $this->png(200));

        $archive = $this->service()->disk()->path($this->service()->directory($club).'/'.$this->service()->create($club));
        $a = $this->state($club);

        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        Storage::disk('local')->put($contract->path, '%PDF-1.4 B-CONTRACT %%EOF');
        Storage::disk('public')->put($image, $this->png(40));
        $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'id', 'title' => 'الهوية', 'file' => $this->pdf('B-ID')])->assertSessionHasNoErrors();

        return [$club, $archive, $a, $this->state($club), $contract];
    }

    /** Every row of every club table, and every file of the club on both disks with its SHA-256. */
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

    private function assertUnchanged(array $before, Tenant $club, string $why): void
    {
        $after = $this->state($club);
        $this->assertSame($before['rows'], $after['rows'], "$why: every row as it was");
        $this->assertSame($before['files'], $after['files'], "$why: every file as it was");
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING), "$why: no staging left");
        $this->assertSame(0, AuditLog::where('event', 'backup.restored')->count(), "$why: not recorded as restored");
    }

    /** @return BusinessRuleException the refusal the restore ended with */
    private function restoreFails(Tenant $club, string $archive): BusinessRuleException
    {
        try {
            $this->service()->restore($club, $archive);
        } catch (BusinessRuleException $e) {
            return $e;
        }
        $this->fail('the restore went through');
    }

    public function test_a_restore_that_works_brings_back_every_row_and_file_of_the_archive(): void
    {
        [$club, $archive, $a, , $contract] = $this->archivedThenChanged();

        $this->service()->restore($club, $archive);
        $after = $this->state($club);
        $this->assertSame($a['rows'], $after['rows']);
        // Every file of the archive is back with its bytes; the one added after it stays (a restore removes no file).
        $this->assertSame($a['files'], array_intersect_key($after['files'], $a['files']));
        $this->assertCount(count($a['files']) + 1, $after['files']);
        $this->assertStringContainsString('A-CONTRACT', (string) Storage::disk('local')->get($contract->path));
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
        $this->assertSame(count($a['files']), AuditLog::where('event', 'backup.restored')->latest('id')->value('properties')['files']['written'], 'the files really written');
    }

    public function test_a_damaged_file_in_the_archive_changes_nothing(): void
    {
        [$club, $archive, , $b, $contract] = $this->archivedThenChanged();
        $zip = new ZipArchive;
        $zip->open($archive);
        $entry = 'files/private/'.$contract->path;
        $zip->addFromString($entry, (string) $zip->getFromName($entry));
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        $zip->close();
        $raw = (string) file_get_contents($archive);
        $at = strpos($raw, 'A-CONTRACT-') + 14;
        $raw[$at] = $raw[$at] === 'a' ? 'b' : 'a';
        file_put_contents($archive, $raw);

        $this->assertSame('backup.invalid', $this->restoreFails($club, $archive)->rule);
        $this->assertUnchanged($b, $club, 'a damaged entry');
    }

    public static function failures(): array
    {
        return [
            'staging: the second file cannot be written' => [['put' => [2]]],
            'staging: the journal cannot be written' => [['journal' => [1]]],
            'placing: the second move fails' => [['move' => [2]]],
            'placing: the last move fails' => [['move' => [-1]]],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_file_step_that_fails_changes_nothing(array $fail): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $this->files->fail = $fail;

        $this->assertSame('backup.restore_failed', $this->restoreFails($club, $archive)->rule);
        $this->files->fail = [];
        $this->assertUnchanged($b, $club, json_encode($fail));
    }

    public function test_a_disk_that_keeps_only_part_of_each_file_changes_nothing(): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $disk = FailingDisk::install('local', 'short');

        $this->assertSame('backup.restore_failed', $this->restoreFails($club, $archive)->rule);
        $disk->mode('ok');
        $this->assertUnchanged($b, $club, 'cut writes');
    }

    public function test_the_database_failing_half_way_through_the_rows_changes_nothing(): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "gym_member_documents"')) {
                throw new RuntimeException('database down');
            }
        });

        $this->assertSame('backup.restore_failed', $this->restoreFails($club, $archive)->rule);
        $fail = false;
        $this->assertUnchanged($b, $club, 'rows');
    }

    public function test_the_commit_marker_failing_after_every_file_was_placed_changes_nothing(): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "audit_logs"') && in_array('backup.restore_committed', $query->bindings, true)) {
                throw new RuntimeException('database down');
            }
        });

        $this->assertSame('backup.restore_failed', $this->restoreFails($club, $archive)->rule);
        $fail = false;
        $this->assertUnchanged($b, $club, 'the marker');
    }

    /**
     * The disk dies half-way through placing the files: the rows roll back, but putting the files back fails too. This
     * is what a killed process leaves (the database rolls back by itself). The journal stays; once the disk works, the
     * next restore first puts every file back, then restores.
     */
    public function test_a_run_stopped_half_way_is_put_back_before_anything_else(): void
    {
        [$club, $archive, $a, $b] = $this->archivedThenChanged();
        $this->files->fail = ['move' => [2, 'and after']];

        $this->assertSame('backup.restore_interrupted', $this->restoreFails($club, $archive)->rule);
        $this->assertSame($b['rows'], $this->state($club)['rows'], 'the rows rolled back');
        $this->assertNotSame($b['files'], $this->state($club)['files'], 'one file is still aside: the half-way state');
        $this->assertNotEmpty(Storage::disk('local')->allFiles(TenantBackupService::STAGING), 'the journal stays');

        // The disk works again: recovery puts every file back as it was.
        $this->files->fail = [];
        $this->service()->recover($club);
        $this->assertUnchanged($b, $club, 'recovered');
        $this->assertSame('back', AuditLog::where('event', 'backup.restore_recovered')->latest('id')->value('properties')['direction']);

        // And the restore asked again goes through whole.
        $this->service()->restore($club, $archive);
        $this->assertSame($a['rows'], $this->state($club)['rows']);
    }

    /** After the commit the club is the archive's; a staging that cannot be removed then is removed by the next run. */
    public function test_a_clean_up_that_fails_after_the_commit_leaves_the_restore_whole(): void
    {
        [$club, $archive, $a] = $this->archivedThenChanged();
        $this->files->fail = ['cleanup' => [1]];

        $this->service()->restore($club, $archive);
        $this->assertSame($a['rows'], $this->state($club)['rows']);
        $this->assertSame($a['files'], array_intersect_key($this->state($club)['files'], $a['files']));
        $this->assertNotEmpty(Storage::disk('local')->allFiles(TenantBackupService::STAGING));

        $this->files->fail = [];
        $this->service()->recover($club);
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
        $this->assertSame($a['rows'], $this->state($club)['rows'], 'forward: still the archive');
        $this->assertSame('forward', AuditLog::where('event', 'backup.restore_recovered')->latest('id')->value('properties')['direction']);
    }

    public function test_the_owner_restoring_from_the_panel_sees_an_error_not_a_success_and_nothing_changed(): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $upload = tempnam(sys_get_temp_dir(), 'up').'.zip';
        copy($archive, $upload);
        $before = $this->state($club);
        $this->files->fail = ['move' => [2]];

        $this->actingAs($this->owner($club))->post(route('club.backups.restore'), ['archive' => new UploadedFile($upload, 'backup.zip', 'application/zip', null, true), 'confirm' => $club->slug])
            ->assertSessionHasErrors('error')->assertSessionMissing('success');
        $this->files->fail = [];
        $this->assertSame($before['rows'], $this->state($club)['rows']);
        $this->assertSame($b['files'], $this->state($club)['files']);
    }

    /** The database commits, then PHP sees an error (the connection lost on the way back): the restore ends whole, forward. */
    /**
     * The safety copy taken before a restore cannot be made (here: no room for its archive): the restore stops before
     * anything is replaced, no new or half-built archive is left, the archive kept before stays sound, nothing "restored".
     */
    public function test_a_safety_copy_that_cannot_be_made_stops_the_restore_before_anything_is_replaced(): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        $this->app->instance(DiskSpace::class, new class extends DiskSpace
        {
            public function ensure(string $path, int $needed, string $rule, string $what): void
            {
                if ($what === 'لإنشاء النسخة الاحتياطية') {
                    throw new BusinessRuleException($rule, 'no room for the archive');
                }
            }
        });

        try {
            $this->service()->restoreWithSafetyCopy($club, $archive);
            $this->fail('the restore went through without its safety copy');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.restore_failed', $e->rule);
        }
        $this->assertUnchanged($b, $club, 'safety copy refused');
        $this->assertSame([basename($archive)], array_map('basename', Storage::disk('local')->files($this->service()->directory($club))), 'no new or half-built archive');
        $this->assertSame(1, AuditLog::where('event', 'backup.created')->count(), 'only the archive taken before');
        $this->assertSame(1, AuditLog::where('event', 'backup.failed')->count());
        $this->service()->verifyArchive($archive);
    }

    public function test_an_error_after_the_database_committed_finishes_the_restore_forward(): void
    {
        [$club, $archive, $a] = $this->archivedThenChanged();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into "audit_logs"') && in_array('backup.restore_committed', $query->bindings, true)) {
                DB::afterCommit(fn () => throw new RuntimeException('connection lost after the commit'));
            }
        });

        $this->service()->restore($club, $archive);
        $after = $this->state($club);
        $this->assertSame($a['rows'], $after['rows'], 'the rows are the archive\'s');
        $this->assertSame($a['files'], array_intersect_key($after['files'], $a['files']), 'and so is every file: never put back under committed rows');
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
    }

    public function test_a_second_restore_of_the_same_club_waits_for_the_first(): void
    {
        [$club, $archive, , $b] = $this->archivedThenChanged();
        // The first one, in another session: it holds the club's lock (a PostgreSQL session lock) while it runs.
        config(['database.connections.other' => config('database.connections.pgsql')]);
        DB::connection('other')->select('select pg_advisory_lock(?, ?)', [TenantBackupService::LOCKS, $club->id]);

        $this->assertSame('backup.busy', $this->restoreFails($club, $archive)->rule);
        try {
            $this->service()->create($club);
            $this->fail('a backup went through while a restore ran');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.busy', $e->rule);
        }
        DB::connection('other')->select('select pg_advisory_unlock(?, ?)', [TenantBackupService::LOCKS, $club->id]);
        DB::purge('other');
        $this->assertUnchanged($b, $club, 'while another runs');
    }
}

/** RestoreFiles whose steps fail on demand: ['move' => [2]] fails the second move; [2, 'and after'] every one from it. */
final class FaultyRestoreFiles extends RestoreFiles
{
    /** @var array<string, array{0: int, 1?: string}> */
    public array $fail = [];

    /** @var array<string, int> */
    private array $calls = [];

    private int $moves = 0;

    private function step(string $op): void
    {
        $n = $this->calls[$op] = ($this->calls[$op] ?? 0) + 1;
        $rule = $this->fail[$op] ?? null;
        if ($rule === null) {
            return;
        }
        $at = $rule[0] === -1 ? $this->moves : $rule[0];
        if ($n === $at || (isset($rule[1]) && $n >= $at)) {
            throw new RuntimeException("injected failure: {$op} #{$n}");
        }
    }

    public function putStream(string $disk, string $path, $stream, string $sha256): void
    {
        $this->step('put');
        parent::putStream($disk, $path, $stream, $sha256);
    }

    public function putString(string $disk, string $path, string $contents): void
    {
        if (! str_ends_with($path, '/journal.json')) {
            parent::putString($disk, $path, $contents); // a mark that a file was there before (…/had/n)

            return;
        }
        $this->step('journal');
        // The journal lists the moves to come: one in per file, one aside per file already there.
        $journal = json_decode($contents, true);
        $this->moves = count($journal['items'] ?? []) + count(array_filter($journal['items'] ?? [], fn ($i) => Storage::disk($i['disk'])->exists($i['path'])));
        parent::putString($disk, $path, $contents);
    }

    public function move(string $fromDisk, string $from, string $toDisk, string $to): void
    {
        $this->step('move');
        parent::move($fromDisk, $from, $toDisk, $to);
    }

    public function deleteDirectory(string $disk, string $path): void
    {
        $this->step('cleanup');
        parent::deleteDirectory($disk, $path);
    }
}
