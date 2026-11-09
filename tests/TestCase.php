<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tests empty their database (RefreshDatabase runs migrate:fresh, the concurrency tests truncate theirs), so
     * they refuse to start on one that is not a test database, before anything touches it. phpunit.xml names
     * alopera_gym_testing; a cached configuration (php artisan config:cache: phpunit.xml is then ignored) or a
     * DB_DATABASE set by hand could point them at the dev data or production.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];
        $connection = (string) $config->get('database.default');

        $refusal = self::databaseRefusal(
            (string) $app->environment(),
            is_file($app->getCachedConfigPath()),
            (string) $config->get("database.connections.$connection.driver"),
            (string) $config->get("database.connections.$connection.database"),
            self::devDatabase($app->environmentFilePath()),
        );
        if ($refusal !== null) {
            throw new RuntimeException("The tests did not start, nothing was touched: $refusal");
        }

        return $app;
    }

    /** Why the tests may not run on this database, or null when it is a test database. */
    public static function databaseRefusal(string $environment, bool $configCached, string $driver, string $database, ?string $devDatabase): ?string
    {
        if ($environment !== 'testing') {
            return "APP_ENV is \"$environment\", not \"testing\".";
        }
        if ($configCached) {
            return 'the configuration is cached (bootstrap/cache/config.php), so phpunit.xml\'s database is ignored: run php artisan config:clear.';
        }
        if ($driver === 'sqlite' && $database === ':memory:') {
            return null;
        }
        if (stripos($database, 'test') === false) {
            return "the database \"$database\" is not a test database: its name must contain \"test\" (phpunit.xml: alopera_gym_testing).";
        }
        if ($devDatabase !== null && strcasecmp($database, $devDatabase) === 0) {
            return "the database \"$database\" is the one in .env, the app's own data.";
        }

        return null;
    }

    /** The database the app itself uses (DB_DATABASE in .env), read from the file: the tests' environment overrides it. */
    private static function devDatabase(string $envFile): ?string
    {
        $lines = is_file($envFile) ? (file($envFile, FILE_IGNORE_NEW_LINES) ?: []) : [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*DB_DATABASE\s*=\s*"?([^"#\s]*)"?/', $line, $m)) {
                return $m[1] === '' ? null : $m[1];
            }
        }

        return null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }
}
