<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\DatabaseIsolation;

/**
 * The tests empty their database: they start only on a test database on this computer (Tests\TestCase), and the
 * browser checks serve the app only on one (scripts/serve-isolated.sh). Not the name alone: the server, the
 * environments, the configuration cache, the connection, and PostgreSQL's own answer (Tests\Support\DatabaseIsolation).
 * Every wrong setting here is refused before connecting: no real database is touched (the hosts are .invalid).
 */
class TestDatabaseGuardTest extends TestCase
{
    private const SAFE = [
        'environment' => 'testing', 'env_file_environment' => 'local', 'env_file_database' => 'alopera_gym', 'config_cached' => false,
        'production_server' => false, 'connection' => 'pgsql', 'driver' => 'pgsql', 'url' => '', 'host' => '127.0.0.1', 'port' => '5432',
        'database' => 'alopera_gym_testing',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'the production server' => [['production_server' => true], 'production server'];
        yield '.env says production' => [['env_file_environment' => 'production'], 'APP_ENV=production'];
        yield 'a cached configuration' => [['config_cached' => true], 'cached'];
        yield 'APP_ENV local' => [['environment' => 'local'], 'APP_ENV is "local"'];
        yield 'APP_ENV production' => [['environment' => 'production'], 'APP_ENV is "production"'];
        yield 'not PostgreSQL' => [['driver' => 'mysql'], 'not PostgreSQL'];
        yield 'DB_URL set' => [['url' => 'pgsql://u:p@127.0.0.1/alopera_gym_testing'], 'DB_URL'];
        yield 'a remote host' => [['host' => 'db.example.com'], 'not this computer'];
        yield 'a private address' => [['host' => '10.0.0.5'], 'not this computer'];
        yield 'a managed database' => [['host' => 'private-db-postgresql-fra1-123.b.db.ondigitalocean.com'], 'not this computer'];
        yield 'an empty host' => [['host' => ''], 'not this computer'];
        yield 'a name without test' => [['database' => 'alopera_gym'], 'not a test database'];
        yield 'the .env database' => [['database' => 'gym_test', 'env_file_database' => 'gym_test'], 'the one in .env'];
    }

    #[DataProvider('refused')]
    public function test_a_database_that_is_not_a_local_test_one_is_refused(array $change, string $why): void
    {
        $this->assertStringContainsString($why, (string) DatabaseIsolation::refusal($change + self::SAFE, ['testing']));
    }

    public function test_local_test_databases_are_allowed(): void
    {
        foreach (['127.0.0.1', 'localhost', '::1', '[::1]', '/var/run/postgresql', '127.0.0.2'] as $host) {
            $this->assertNull(DatabaseIsolation::refusal(['host' => $host] + self::SAFE, ['testing']), $host);
        }
        foreach (['alopera_gym_testing_site', 'alopera_gym_testing_concurrency', 'alopera_gym_ui_testing'] as $database) {
            $this->assertNull(DatabaseIsolation::refusal(['database' => $database] + self::SAFE, ['testing']), $database);
        }
        $this->assertNull(DatabaseIsolation::refusal(['driver' => 'sqlite', 'database' => ':memory:', 'host' => ''] + self::SAFE, ['testing']));
        $this->assertNull(DatabaseIsolation::refusal(['environment' => 'local'] + self::SAFE, ['local', 'testing']), 'a browser check runs in local');
    }

    public function test_postgresql_s_own_answer_must_be_the_database_asked_for_on_this_computer(): void
    {
        $this->assertNull(DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym_testing', null), 'a socket');
        $this->assertNull(DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym_testing', '127.0.0.1'));
        $this->assertNull(DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym_testing', '::1'));
        // A pooler or an alias that lands somewhere else than the settings say.
        $this->assertStringContainsString('reached the database "alopera_gym"', (string) DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym', '127.0.0.1'));
        // PostgreSQL in a container (CI): connected to 127.0.0.1, it answers from the container's own address.
        $this->assertNull(DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym_testing', '172.18.0.2'));
        // A port on 127.0.0.1 forwarded to a database across the internet: the server answering is at a public address.
        $this->assertStringContainsString('public address 165.245.218.225', (string) DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym_testing', '165.245.218.225'));
        $this->assertStringContainsString('public address 2a03:b0c0:3:d0::1', (string) DatabaseIsolation::liveRefusal('alopera_gym_testing', 'alopera_gym_testing', '2a03:b0c0:3:d0::1'));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function wrongTestRuns(): iterable
    {
        yield 'a name without test' => [['DB_DATABASE' => 'alopera_gym_guard_probe'], '"alopera_gym_guard_probe\\\\?" is not a test database'];
        yield 'a remote host' => [['DB_HOST' => 'db.example.invalid'], 'host \\\\?"db.example.invalid\\\\?" is not this computer'];
        yield 'DB_URL' => [['DB_URL' => 'pgsql://u:p@db.example.invalid:5432/alopera_gym_testing'], 'DB_URL is set'];
        yield 'not PostgreSQL' => [['DB_CONNECTION' => 'mysql'], 'not PostgreSQL'];
    }

    #[DataProvider('wrongTestRuns')]
    public function test_a_test_run_with_wrong_settings_stops_before_connecting(array $env, string $why): void
    {
        $out = $this->phpunit($env);

        $this->assertStringContainsString('The tests did not start, nothing was touched', $out);
        $this->assertMatchesRegularExpression('/'.str_replace('/', '\/', $why).'/', $out);
    }

    public function test_a_test_run_on_a_cached_configuration_stops_before_connecting(): void
    {
        // The real danger: config:cache run with the dev settings; phpunit.xml's database is then ignored. Cached into a
        // file of its own (APP_CONFIG_CACHE), never bootstrap/cache, and naming a database that exists nowhere.
        $cache = sys_get_temp_dir().'/alopera-config-'.uniqid().'.php';
        try {
            $make = new Process([PHP_BINARY, 'artisan', 'config:cache'], self::root(), [
                'APP_CONFIG_CACHE' => $cache, 'APP_ENV' => 'local', 'DB_DATABASE' => 'alopera_gym_guard_probe_dev',
            ]);
            $make->mustRun();
            $this->assertFileExists($cache);
            $this->assertFileDoesNotExist(self::root().'/bootstrap/cache/config.php');

            $out = $this->phpunit(['APP_CONFIG_CACHE' => $cache]);

            $this->assertStringContainsString('The tests did not start, nothing was touched', $out);
            $this->assertStringContainsString('configuration is cached', $out);
        } finally {
            @unlink($cache);
        }
    }

    /** One small feature test run in a process of its own with these settings; what it printed. */
    private function phpunit(array $env): string
    {
        $run = new Process(
            [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Feature/PanelChromeTest.php', '--filter', 'test_the_bell_is_only_in_the_club_panel', '--no-coverage'],
            self::root(),
            $env,
        );
        $run->setTimeout(60);
        $run->run();
        $this->assertNotSame(0, $run->getExitCode(), $run->getOutput());

        return $run->getOutput().$run->getErrorOutput();
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function refusedByTheBrowserServer(): iterable
    {
        yield 'a name without test' => ['alopera_gym', [], 'is not a test database'];
        yield 'characters that are not a plain name' => ['x_test;drop', [], 'lower case letters, digits and _ only'];
        yield 'no name' => ['', [], 'usage:'];
        yield 'the production server' => ['alopera_gym_ui_testing', ['ALOPERA_PRODUCTION_ROOT' => sys_get_temp_dir()], 'this is the production server'];
        yield 'a remote host' => ['alopera_gym_ui_testing', ['DB_HOST' => 'db.example.invalid'], 'is not this computer'];
        yield 'DB_URL' => ['alopera_gym_ui_testing', ['DB_URL' => 'pgsql://u:p@db.example.invalid:5432/alopera_gym_ui_testing'], 'DB_URL is set'];
        yield 'not PostgreSQL' => ['alopera_gym_ui_testing', ['DB_CONNECTION' => 'mysql'], 'not PostgreSQL'];
        yield 'APP_ENV production' => ['alopera_gym_ui_testing', ['APP_ENV' => 'production'], 'APP_ENV is "production"'];
    }

    #[DataProvider('refusedByTheBrowserServer')]
    public function test_the_browser_check_server_starts_only_on_a_local_test_database(string $database, array $env, string $why): void
    {
        $run = new Process(['bash', 'scripts/serve-isolated.sh', $database, '8799'], self::root(), $env);
        $run->setTimeout(60);
        $run->run();

        $this->assertSame(1, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $this->assertStringContainsString($why, $run->getErrorOutput());
        $this->assertStringNotContainsString('▸', $run->getOutput(), 'nothing made, migrated or served');
    }
}
