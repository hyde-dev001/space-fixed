<?php

namespace App\Services;

use App\Exceptions\MaintenanceConflictException;
use Closure;
use Illuminate\Support\Facades\DB;

class MaintenanceCommandLock
{
    public function run(Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Platform maintenance commands require a MySQL connection.');
        }

        $lockName = (string) config('platform_maintenance.lock_name', 'platform_maintenance_command');
        $timeout = (int) config('platform_maintenance.lock_timeout_seconds', 5);
        $result = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $timeout]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new MaintenanceConflictException('lock_timeout');
        }

        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
}
