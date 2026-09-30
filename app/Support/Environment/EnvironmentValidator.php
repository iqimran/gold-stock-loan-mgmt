<?php

namespace App\Support\Environment;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Validates that the runtime configuration is safe to run the application.
 */
class EnvironmentValidator
{
    /** Recommended minimum (docs/00-tech-stack.md); older servers produce a warning, not an error. */
    public const RECOMMENDED_PGSQL_VERSION = '16.0';

    /** Environments in which a non-PostgreSQL database (e.g. in-memory SQLite) is acceptable. */
    private const NON_PGSQL_ENVIRONMENTS = ['testing'];

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(bool $checkDatabase = true): array
    {
        $errors = [];
        $warnings = [];
        $environment = (string) $this->config->get('app.env');
        $production = $environment === 'production';

        if (blank($this->config->get('app.key'))) {
            $errors[] = 'APP_KEY is not set. Run `php artisan key:generate`.';
        }

        if (blank($this->config->get('app.url'))) {
            $errors[] = 'APP_URL is not set.';
        }

        if ($production && $this->config->get('app.debug')) {
            $errors[] = 'APP_DEBUG must be false in production.';
        }

        if ($production && ! str_starts_with((string) $this->config->get('app.url'), 'https://')) {
            $warnings[] = 'APP_URL should use https:// in production.';
        }

        if ($production && str_starts_with((string) $this->config->get('app.url'), 'https://') && ! $this->config->get('session.secure')) {
            $warnings[] = 'SESSION_SECURE_COOKIE should be true in production (HTTPS), so the session cookie is never sent over plain HTTP.';
        }

        $connection = (string) $this->config->get('database.default');
        $driver = (string) $this->config->get("database.connections.{$connection}.driver");

        if ($driver !== 'pgsql' && ! in_array($environment, self::NON_PGSQL_ENVIRONMENTS, true)) {
            $errors[] = "DB_CONNECTION must use the pgsql driver (current: {$driver}).";
        }

        if ($checkDatabase) {
            [$databaseErrors, $databaseWarnings] = $this->checkDatabase($connection, $driver);
            array_push($errors, ...$databaseErrors);
            array_push($warnings, ...$databaseWarnings);
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [errors, warnings]
     */
    private function checkDatabase(string $connection, string $driver): array
    {
        try {
            $pdo = $this->db->connection($connection)->getPdo();
        } catch (Throwable $e) {
            return [["Unable to connect to the [{$connection}] database: {$e->getMessage()}"], []];
        }

        if ($driver !== 'pgsql') {
            return [[], []];
        }

        $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

        if (version_compare($this->normaliseVersion($version), self::RECOMMENDED_PGSQL_VERSION, '<')) {
            return [[], ['PostgreSQL '.self::RECOMMENDED_PGSQL_VERSION."+ is recommended (server reports {$version})."]];
        }

        return [[], []];
    }

    private function normaliseVersion(string $version): string
    {
        return preg_match('/^\d+(\.\d+)*/', $version, $matches) ? $matches[0] : $version;
    }
}
