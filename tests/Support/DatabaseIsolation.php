<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Where the tests and the browser checks may write: a test database on this computer, never the dev data in .env, never
 * production. Not the database's name alone. Before connecting: not the production server, the app's environment and
 * the .env file's, the configuration cache, the connection's driver, DB_URL and host, then the name. Once connected,
 * what PostgreSQL itself answers: the database reached and the address of the server answering.
 *
 * Used by Tests\TestCase (every test, before RefreshDatabase empties anything), Tests\Concerns\RunsConcurrently (before
 * it migrates its own database) and scripts/serve-isolated.sh (before it makes, migrates or seeds a database).
 */
final class DatabaseIsolation
{
    /** The production server's deploy folder (docs/DEPLOY.md): present only there. */
    public const PRODUCTION_ROOT = '/var/www/alopera-gym';

    /** @var array<string, true> connections already checked live in this process (host|port|database) */
    private static array $checked = [];

    /**
     * What decides it, read without connecting.
     *
     * @return array{environment: string, env_file_environment: ?string, env_file_database: ?string, config_cached: bool, production_server: bool, connection: string, driver: string, url: string, host: string, port: string, database: string}
     */
    public static function facts(Application $app, ?string $connection = null): array
    {
        $config = $app['config'];
        $connection ??= (string) $config->get('database.default');
        $settings = (array) $config->get("database.connections.$connection", []);
        $envFile = $app->environmentFilePath();

        return [
            'environment' => (string) $app->environment(),
            'env_file_environment' => self::envFileValue($envFile, 'APP_ENV'),
            'env_file_database' => self::envFileValue($envFile, 'DB_DATABASE'),
            'config_cached' => is_file($app->getCachedConfigPath()),
            'production_server' => is_dir(self::PRODUCTION_ROOT),
            'connection' => $connection,
            'driver' => (string) ($settings['driver'] ?? ''),
            'url' => (string) ($settings['url'] ?? ''),
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (string) ($settings['port'] ?? ''),
            'database' => (string) ($settings['database'] ?? ''),
        ];
    }

    /**
     * Why nothing may be written there, or null.
     *
     * @param  array<string, mixed>  $facts  from facts()
     * @param  list<string>  $environments  the app environments allowed: the tests run in testing; a browser check in local or testing
     */
    public static function refusal(array $facts, array $environments): ?string
    {
        if ($facts['production_server']) {
            return 'this is the production server ('.self::PRODUCTION_ROOT.' exists).';
        }
        if ($facts['env_file_environment'] === 'production') {
            return 'the .env file says APP_ENV=production.';
        }
        if ($facts['config_cached']) {
            return 'the configuration is cached, so the database settings given to the tests are ignored: run php artisan config:clear.';
        }
        if (! in_array($facts['environment'], $environments, true)) {
            return "APP_ENV is \"{$facts['environment']}\", not ".implode(' or ', array_map(fn ($e) => "\"$e\"", $environments)).'.';
        }
        if ($facts['driver'] === 'sqlite' && $facts['database'] === ':memory:') {
            return null;
        }
        if ($facts['driver'] !== 'pgsql') {
            return "the connection \"{$facts['connection']}\" is \"{$facts['driver']}\", not PostgreSQL.";
        }
        if ($facts['url'] !== '') {
            return 'DB_URL is set: it overrides the host and the database, which are what is checked here.';
        }
        if (! self::isLocal($facts['host'])) {
            return "the database host \"{$facts['host']}\" is not this computer (127.0.0.1, localhost, ::1 or a socket).";
        }
        if (stripos($facts['database'], 'test') === false) {
            return "the database \"{$facts['database']}\" is not a test database: its name must contain \"test\" (phpunit.xml: alopera_gym_testing).";
        }
        if ($facts['env_file_database'] !== null && strcasecmp($facts['database'], $facts['env_file_database']) === 0) {
            return "the database \"{$facts['database']}\" is the one in .env, the app's own data.";
        }

        return null;
    }

    /** Once connected: the database PostgreSQL reached, and the address of the server answering (null over a socket). */
    public static function liveRefusal(string $expectedDatabase, string $currentDatabase, ?string $serverAddress): ?string
    {
        if ($currentDatabase !== $expectedDatabase) {
            return "the connection reached the database \"$currentDatabase\", not \"$expectedDatabase\".";
        }
        if ($serverAddress !== null && $serverAddress !== '' && ! self::isLocal($serverAddress)) {
            return "the PostgreSQL server answering is at $serverAddress, not on this computer.";
        }

        return null;
    }

    /**
     * Both checks; the live one once per connection target and process. Throws before anything is written.
     *
     * @param  list<string>  $environments
     */
    public static function assert(Application $app, array $environments, string $prefix, ?string $connection = null): void
    {
        $facts = self::facts($app, $connection);
        if (($refusal = self::refusal($facts, $environments)) !== null) {
            throw new RuntimeException($prefix.$refusal);
        }
        if ($facts['driver'] !== 'pgsql') {
            return;
        }
        $target = $facts['host'].'|'.$facts['port'].'|'.$facts['database'];
        if (isset(self::$checked[$target])) {
            return;
        }
        $answer = $app['db']->connection($facts['connection'])
            ->selectOne('select current_database() as database, host(inet_server_addr()) as address');
        if (($refusal = self::liveRefusal($facts['database'], (string) $answer->database, $answer->address)) !== null) {
            throw new RuntimeException($prefix.$refusal);
        }
        self::$checked[$target] = true;
    }

    public static function isLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || str_starts_with($host, '/')
            || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
    }

    /** A value as written in the .env file: the environment given to the tests overrides it, this does not. */
    private static function envFileValue(string $file, string $key): ?string
    {
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*"?([^"#\s]*)"?/', $line, $m)) {
                return $m[1] === '' ? null : $m[1];
            }
        }

        return null;
    }
}
