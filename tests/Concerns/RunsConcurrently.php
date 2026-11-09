<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DatabaseIsolation;
use Throwable;

/**
 * Requests that really run at the same time, on real PostgreSQL: each task runs in a process of its own (pcntl_fork)
 * with its own database connection, as two requests do on the server. They use a database of their own, named after
 * the test database (alopera_gym_testing_concurrency; made and migrated on first use, emptied before each test),
 * because the test database's per-test transaction would hide one process's rows from the others. Named after it, not
 * fixed: two sessions testing at once on their own test databases do not empty each other's. The cache is the database store, as on the
 * server, so a cache lock taken in one process is seen by the others.
 *
 * Tasks get a folder they share ($dir) to signal each other ("I am inside", a process id) when a test needs an
 * order: one request slowed down inside its critical section while another arrives.
 */
trait RunsConcurrently
{
    private static bool $concurrencyDatabaseReady = false;

    protected function setUpConcurrency(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are needed to run requests at the same time.');
        }

        // Named after the test database, which passed Tests\TestCase's checks.
        $name = config('database.connections.pgsql.database').'_concurrency';
        if (! DB::selectOne('select 1 as found from pg_database where datname = ?', [$name])) {
            DB::statement('create database '.$name);
        }
        config(['database.connections.pgsql.database' => $name, 'cache.default' => 'database']);
        DB::purge('pgsql');
        // The same checks as every test's database, on this one, before it is migrated or emptied.
        DatabaseIsolation::assert(app(), ['testing'], 'The concurrency tests did not start, nothing was touched: ');

        if (! self::$concurrencyDatabaseReady) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$concurrencyDatabaseReady = true;
        } else {
            $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'"))
                ->map(fn ($t) => '"'.$t->tablename.'"')->implode(', ');
            DB::statement('truncate '.$tables.' restart identity cascade');
        }
    }

    /**
     * Run the tasks at the same moment, each in its own process; returns what each returned or threw, in order.
     *
     * @param  list<Closure(string): mixed>  $tasks  each gets the shared folder
     * @return list<array{ok: bool, value: mixed, error: ?string}>
     */
    protected function concurrently(array $tasks): array
    {
        $dir = sys_get_temp_dir().'/alopera-concurrency-'.getmypid().'-'.uniqid();
        mkdir($dir);
        $start = microtime(true) + 0.5;
        DB::disconnect(); // no process may write on another one's connection

        $pids = [];
        foreach (array_values($tasks) as $i => $task) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('could not start a process');
            }
            if ($pid === 0) {
                $result = ['ok' => false, 'value' => null, 'error' => null];
                try {
                    while (microtime(true) < $start) {
                        usleep(500);
                    }
                    $result['value'] = $task($dir);
                    $result['ok'] = true;
                } catch (Throwable $e) {
                    $result['error'] = $e::class.': '.$e->getMessage();
                }
                file_put_contents("$dir/result-$i.json", json_encode($result));
                // Leave at once: no shutdown handlers of the test runner in a copy of it.
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[$i] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($pids) as $i) {
            $file = "$dir/result-$i.json";
            $results[$i] = is_file($file) ? json_decode((string) file_get_contents($file), true)
                : ['ok' => false, 'value' => null, 'error' => 'killed before it finished'];
        }
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);

        return $results;
    }

    /** Inside a task: wait until another task has written this signal file (at most ten seconds). */
    protected static function waitFor(string $file): void
    {
        $until = microtime(true) + 10;
        while (! is_file($file) && microtime(true) < $until) {
            usleep(5000);
        }
    }

    /** Inside a task: slow the request down right after a statement that starts with $sql, and say so in $dir/inside. */
    protected static function pauseAfter(string $sql, string $dir, float $seconds): void
    {
        DB::listen(function ($query) use ($sql, $dir, $seconds) {
            if (str_starts_with($query->sql, $sql) && ! is_file("$dir/inside")) {
                file_put_contents("$dir/inside", (string) getmypid());
                usleep((int) ($seconds * 1_000_000));
            }
        });
    }
}
