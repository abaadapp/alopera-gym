<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * The log files are written by two users on the server: PHP-FPM (www-data), and cron, the scheduler, the queue worker
 * and deploy (deploy, in the www-data group). Each file channel makes its file 0660 whoever creates it (a new file, a
 * new day's file), and puts a file back to 0660 when its owner opens it. Before, the file took its creator's umask
 * (0644 under PHP-FPM) and deploy's log lines threw instead of being written (#197). The two real users: LogTwoUsersTest.
 */
class LogFilePermissionsTest extends TestCase
{
    private string $dir;

    private int $umask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/logperm-'.Str::random(8);
        mkdir($this->dir);
        $this->umask = umask(0022); // PHP-FPM's on the server
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function channel(string $name): LoggerInterface
    {
        return Log::build(['path' => $this->dir.'/laravel.log'] + config("logging.channels.$name"));
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 07777;
    }

    public function test_every_file_channel_makes_its_file_the_group_s_and_nobody_else_s(): void
    {
        foreach (['single', 'daily', 'monthly'] as $name) {
            $this->assertSame(0660, config("logging.channels.$name.permission"), $name);
        }

        $this->channel('single')->error('one');
        $this->assertSame(0660, $this->mode($this->dir.'/laravel.log'), 'a new file, under umask 0022');

        $this->channel('daily')->error('one');
        $daily = glob($this->dir.'/laravel-*.log');
        $this->assertCount(1, $daily);
        $this->assertSame(0660, $this->mode($daily[0]), 'each new day\'s file too (rotation)');
    }

    public function test_a_file_made_0644_before_is_put_right_the_next_time_its_owner_logs_and_nothing_is_lost(): void
    {
        $path = $this->dir.'/laravel.log';
        file_put_contents($path, "old line\n");
        chmod($path, 0644); // as on the server: made by PHP-FPM, the group can only read it

        $this->channel('single')->error('next line');

        $this->assertSame(0660, $this->mode($path));
        $this->assertStringStartsWith("old line\n", file_get_contents($path), 'nothing emptied');
        $this->assertStringContainsString('next line', file_get_contents($path));
    }

    public function test_the_cause_a_log_file_this_user_cannot_write_throws_instead_of_logging(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes any file');
        }
        $path = $this->dir.'/laravel.log';
        file_put_contents($path, "old line\n");
        chmod($path, 0444); // deploy before the fix: www-data's file, 0644, the group may only read it

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/could not be opened in append mode/');
        $this->channel('single')->error('lost');
    }
}
