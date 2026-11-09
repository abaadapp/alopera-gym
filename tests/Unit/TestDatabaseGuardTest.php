<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\TestCase as AppTestCase;

/**
 * The tests empty their database: they start only on a test database (Tests\TestCase), and the browser checks serve
 * the app only on one (scripts/serve-isolated.sh). Never the dev data in .env, never production.
 */
class TestDatabaseGuardTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string, string, ?string}> */
    public static function refused(): iterable
    {
        yield 'the dev database' => ['testing', false, 'pgsql', 'alopera_gym', 'alopera_gym'];
        yield 'production by name' => ['testing', false, 'pgsql', 'alopera_gym_production', null];
        yield 'not the testing environment' => ['local', false, 'pgsql', 'alopera_gym_testing', 'alopera_gym'];
        yield 'production environment' => ['production', false, 'pgsql', 'alopera_gym_testing', null];
        yield 'a cached configuration' => ['testing', true, 'pgsql', 'alopera_gym_testing', 'alopera_gym'];
        yield 'a .env database whose name says test' => ['testing', false, 'pgsql', 'gym_test', 'gym_test'];
    }

    #[DataProvider('refused')]
    public function test_the_tests_refuse_a_database_that_is_not_a_test_one(string $env, bool $cached, string $driver, string $database, ?string $dev): void
    {
        $this->assertNotNull(AppTestCase::databaseRefusal($env, $cached, $driver, $database, $dev));
    }

    public function test_test_databases_are_allowed(): void
    {
        foreach (['alopera_gym_testing', 'alopera_gym_testing_site', 'alopera_gym_testing_concurrency', 'alopera_gym_ui_testing'] as $database) {
            $this->assertNull(AppTestCase::databaseRefusal('testing', false, 'pgsql', $database, 'alopera_gym'), $database);
        }
        $this->assertNull(AppTestCase::databaseRefusal('testing', false, 'sqlite', ':memory:', 'alopera_gym'));
    }

    public function test_a_test_run_pointed_at_another_database_stops_before_touching_it(): void
    {
        // A name that exists nowhere: were the check missing, migrate:fresh would fail to connect, not empty anything.
        $run = new Process(
            [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Feature/PanelChromeTest.php', '--filter', 'test_the_bell_is_only_in_the_club_panel', '--no-coverage'],
            dirname(__DIR__, 2),
            ['DB_DATABASE' => 'alopera_gym_guard_probe'],
        );
        $run->run();

        $this->assertNotSame(0, $run->getExitCode());
        $this->assertStringContainsString('The tests did not start, nothing was touched', $run->getOutput().$run->getErrorOutput());
        $this->assertMatchesRegularExpression('/alopera_gym_guard_probe\\\\?" is not a test database/', $run->getOutput().$run->getErrorOutput());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedByTheBrowserServer(): iterable
    {
        yield 'a name without test' => ['alopera_gym', 'is not a test database'];
        yield 'characters that are not a plain name' => ['x_test;drop', 'lower case letters, digits and _ only'];
        yield 'no name' => ['', 'usage:'];
    }

    #[DataProvider('refusedByTheBrowserServer')]
    public function test_the_browser_check_server_starts_only_on_a_test_database(string $database, string $message): void
    {
        $run = new Process(['bash', 'scripts/serve-isolated.sh', $database, '8799'], dirname(__DIR__, 2));
        $run->setTimeout(30);
        $run->run();

        $this->assertSame(1, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $this->assertStringContainsString($message, $run->getErrorOutput());
        $this->assertStringNotContainsString('▸', $run->getOutput(), 'nothing made, migrated or served');
    }
}
