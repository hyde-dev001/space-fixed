<?php

namespace Tests\Unit\Maintenance;

use App\Services\MaintenanceCommandLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceCommandLockTest extends TestCase
{
    public function test_non_mysql_connections_are_rejected_without_a_mock(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('SQLite-only assertion.');
        }

        $this->expectException(\RuntimeException::class);
        app(MaintenanceCommandLock::class)->run(static fn (): string => 'unexpected');
    }
}
