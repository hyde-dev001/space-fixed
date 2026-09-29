<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use Illuminate\Support\Facades\DB;

final class PhaseOneStateReconciler
{
    /**
     * Re-evaluate and normalize one employee while the caller's transaction is open.
     *
     * Unknown values are deliberately left untouched. The row is scoped by both its
     * primary key and owner so a stale or cross-shop identifier cannot be changed.
     */
    public function normalizeEmployee(int $shopOwnerId, int $employeeId): bool
    {
        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->where('shop_owner_id', $shopOwnerId)
            ->lockForUpdate()
            ->first(['id', 'status']);

        if ($employee === null || ! $this->isLegacyLeaveStatus((string) ($employee->status ?? ''))) {
            return false;
        }

        return DB::table('employees')
            ->where('id', $employee->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]) === 1;
    }

    public function isLegacyLeaveStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['on_leave', 'on-leave'], true);
    }
}
