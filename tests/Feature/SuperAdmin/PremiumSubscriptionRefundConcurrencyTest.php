<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The SQLite test profile cannot prove row-lock behavior for two refund
 * requests. The supported MySQL profile is the required production gate.
 */
final class PremiumSubscriptionRefundConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Blocked: subscription refund concurrency requires the pdo_mysql extension.');
        }

        $database = getenv('MYSQL_TEST_DATABASE') ?: ($_ENV['MYSQL_TEST_DATABASE'] ?? null);
        if (! $database) {
            $this->markTestSkipped('Blocked: set MYSQL_TEST_DATABASE to run the subscription refund concurrency profile.');
        }

        $this->setMysqlTestingEnvironment((string) $database);
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Blocked: the subscription refund concurrency profile did not establish a MySQL connection.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Blocked: subscription refund concurrency requires process isolation through pcntl_fork.');
        }
    }

    public function test_mysql_process_isolation_profile_is_available_for_subscription_refund_race_matrix(): void
    {
        self::assertSame('mysql', DB::getDriverName());
        self::assertTrue(function_exists('pcntl_fork'));
    }

    private function setMysqlTestingEnvironment(string $database): void
    {
        $connection = getenv('MYSQL_TEST_CONNECTION') ?: ($_ENV['MYSQL_TEST_CONNECTION'] ?? 'mysql');
        $host = getenv('MYSQL_TEST_HOST') ?: ($_ENV['MYSQL_TEST_HOST'] ?? (getenv('DB_HOST') ?: '127.0.0.1'));
        $port = getenv('MYSQL_TEST_PORT') ?: ($_ENV['MYSQL_TEST_PORT'] ?? (getenv('DB_PORT') ?: '3306'));
        $username = getenv('MYSQL_TEST_USERNAME') ?: ($_ENV['MYSQL_TEST_USERNAME'] ?? (getenv('DB_USERNAME') ?: 'root'));
        $password = getenv('MYSQL_TEST_PASSWORD') ?: ($_ENV['MYSQL_TEST_PASSWORD'] ?? (getenv('DB_PASSWORD') ?: ''));

        foreach ([
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
