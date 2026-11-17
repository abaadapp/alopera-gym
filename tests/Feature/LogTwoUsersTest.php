<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The server's two log writers, for real: `sudo -u www-data` plays PHP-FPM, and a user in the www-data group plays deploy
 * (cron, scheduler, queue worker). Both write through Monolog's own StreamHandler with this application's file permission,
 * in a folder like storage/logs (group www-data, setgid), under PHP-FPM's umask 0022. Runs where a passwordless sudo and a
 * www-data user exist (CI); skipped elsewhere, saying why.
 */
class LogTwoUsersTest extends TestCase
{
    private const DEPLOY = 'alopera-ci-deploy';

    private string $root;

    private string $lib;

    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $sudo = function_exists('posix_geteuid') && posix_geteuid() !== 0 && function_exists('posix_getpwnam') && posix_getpwnam('www-data')
            && trim((string) shell_exec('sudo -n -u www-data true 2>/dev/null && echo ok')) === 'ok';
        if (! $sudo) {
            $this->markTestSkipped('needs a passwordless sudo to the www-data user (CI): two real users');
        }
        // deploy's stand-in: its own group, and a member of www-data, as on the server (id deploy).
        exec('sudo -n sh -c '.escapeshellarg('id '.self::DEPLOY.' >/dev/null 2>&1 || useradd -M -U -G www-data -s /usr/sbin/nologin '.self::DEPLOY), $o, $code);
        $this->assertSame(0, $code, 'the deploy stand-in exists');

        $base = sys_get_temp_dir().'/log2u-'.Str::random(8);
        $this->root = $base.'-logs';
        mkdir($this->root);
        exec('sudo -n chgrp www-data '.escapeshellarg($this->root).' && sudo -n chmod 2775 '.escapeshellarg($this->root), $o, $code);
        $this->assertSame(0, $code, 'the logs folder is the shared group\'s');

        // Monolog itself, where both users can read it (the checkout may not be open to them).
        $this->lib = $base.'-lib';
        mkdir($this->lib);
        exec('cp -R '.escapeshellarg(base_path('vendor/monolog/monolog/src/Monolog')).' '.escapeshellarg($this->lib.'/monolog')
            .' && cp -R '.escapeshellarg(base_path('vendor/psr/log/src')).' '.escapeshellarg($this->lib.'/psr-log')
            .' && chmod -R a+rX '.escapeshellarg($this->lib), $o, $code);
        $this->assertSame(0, $code);

        $this->probe = $base.'-probe.php';
        file_put_contents($this->probe, <<<'PHP'
        <?php
        // One log line, as this user, the way the application writes it (Monolog's StreamHandler, with the given permission).
        [$lib, $file, $perm, $message] = [$argv[1], $argv[2], $argv[3] === 'none' ? null : octdec($argv[3]), $argv[4]];
        spl_autoload_register(function (string $class) use ($lib) {
            foreach (['Monolog\\' => "$lib/monolog/", 'Psr\\Log\\' => "$lib/psr-log/"] as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    require $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                    return;
                }
            }
        });
        umask(0022);
        try {
            $log = new Monolog\Logger('probe');
            $log->pushHandler(new Monolog\Handler\StreamHandler($file, Monolog\Level::Debug, true, $perm));
            $log->error($message);
            echo 'ok';
        } catch (Throwable $e) {
            echo 'failed: '.get_class($e);
        }
        PHP);
        chmod($this->probe, 0644);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('sudo -n rm -rf '.escapeshellarg($this->root).' '.escapeshellarg($this->lib).' '.escapeshellarg($this->probe));
        }
        parent::tearDown();
    }

    private function log(string $user, string $file, string $message, ?int $permission): string
    {
        $perm = $permission === null ? 'none' : decoct($permission);

        return trim((string) shell_exec('sudo -n -u '.escapeshellarg($user).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->probe).' '
            .escapeshellarg($this->lib).' '.escapeshellarg($this->root.'/'.$file).' '.$perm.' '.escapeshellarg($message).' 2>&1'));
    }

    /** @return array{mode: int, owner: string, group: string, text: string} */
    private function file(string $file): array
    {
        $path = $this->root.'/'.$file;
        clearstatcache(true, $path);
        $stat = stat($path);

        return [
            'mode' => $stat['mode'] & 07777,
            'owner' => posix_getpwuid($stat['uid'])['name'],
            'group' => posix_getgrgid($stat['gid'])['name'],
            'text' => (string) shell_exec('sudo -n cat '.escapeshellarg($path)),
        ];
    }

    public function test_before_the_fix_deploy_cannot_log_in_the_file_php_fpm_made(): void
    {
        $this->assertSame('ok', $this->log('www-data', 'laravel.log', 'web line', null));
        $this->assertSame(0644, $this->file('laravel.log')['mode'], 'as on the server');
        $this->assertSame('failed: UnexpectedValueException', $this->log(self::DEPLOY, 'laravel.log', 'nightly line', null), 'reproduced: deploy\'s line is lost');
    }

    public function test_with_the_fix_both_users_log_in_one_file_whoever_made_it_and_nobody_else_reads_it(): void
    {
        $permission = config('logging.channels.single.permission');
        $this->assertSame(0660, $permission);

        // PHP-FPM makes the file, deploy writes after it.
        $this->assertSame('ok', $this->log('www-data', 'laravel.log', 'web line', $permission));
        $this->assertSame('ok', $this->log(self::DEPLOY, 'laravel.log', 'nightly line', $permission));
        $web = $this->file('laravel.log');
        $this->assertSame([0660, 'www-data', 'www-data'], [$web['mode'], $web['owner'], $web['group']]);
        $this->assertStringContainsString('web line', $web['text']);
        $this->assertStringContainsString('nightly line', $web['text']);

        // A new file (rotated, or the first of a day) made by deploy: the folder gives it the shared group.
        $this->assertSame('ok', $this->log(self::DEPLOY, 'laravel-2026-11-18.log', 'nightly line', $permission));
        $this->assertSame('ok', $this->log('www-data', 'laravel-2026-11-18.log', 'web line', $permission));
        $cli = $this->file('laravel-2026-11-18.log');
        $this->assertSame([0660, self::DEPLOY, 'www-data'], [$cli['mode'], $cli['owner'], $cli['group']]);
        $this->assertStringContainsString('web line', $cli['text']);

        // Made by root (php artisan run as root): still the two users' file, through the folder's group.
        $this->assertSame('ok', $this->log('root', 'root-made.log', 'root line', $permission));
        $this->assertSame('ok', $this->log('www-data', 'root-made.log', 'web line', $permission));
        $this->assertSame('ok', $this->log(self::DEPLOY, 'root-made.log', 'nightly line', $permission));
        $root = $this->file('root-made.log');
        $this->assertSame([0660, 'root', 'www-data'], [$root['mode'], $root['owner'], $root['group']]);

        // Nobody outside the two.
        foreach (['laravel.log', 'laravel-2026-11-18.log', 'root-made.log'] as $file) {
            $this->assertSame('no', trim((string) shell_exec('sudo -n -u nobody test -r '.escapeshellarg($this->root.'/'.$file).' && echo yes || echo no')), $file);
        }
    }

    public function test_the_file_on_the_server_today_is_put_right_by_php_fpm_s_next_line_with_nothing_lost(): void
    {
        $this->assertSame('ok', $this->log('www-data', 'laravel.log', 'old line', null)); // 0644, as today
        $this->assertSame('ok', $this->log('www-data', 'laravel.log', 'web line after the deploy', config('logging.channels.single.permission')));
        $this->assertSame('ok', $this->log(self::DEPLOY, 'laravel.log', 'nightly line', config('logging.channels.single.permission')));

        $file = $this->file('laravel.log');
        $this->assertSame(0660, $file['mode']);
        $this->assertSame(3, substr_count($file['text'], 'probe.ERROR'), 'every line kept');
    }
}
