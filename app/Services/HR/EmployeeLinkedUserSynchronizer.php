<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;

final class EmployeeLinkedUserSynchronizer
{
    public function sync(Employee $employee): void
    {
        $targetUserStatus = $this->targetUserStatus($employee);
        $email = strtolower(trim((string) $employee->getAttribute('email')));
        $shopOwnerId = (int) $employee->getAttribute('shop_owner_id');

        if ($targetUserStatus === null || $email === '' || $shopOwnerId <= 0) {
            return;
        }

        $linkedUser = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($linkedUser && (string) $linkedUser->getAttribute('status') !== $targetUserStatus) {
            $linkedUser->forceFill(['status' => $targetUserStatus])->save();
        }
    }

    private function targetUserStatus(Employee $employee): ?string
    {
        $rawStatus = $employee->getAttributes()['status'] ?? $employee->getRawOriginal('status');

        if ($rawStatus instanceof EmployeeStatus) {
            return match ($rawStatus) {
                EmployeeStatus::ACTIVE => 'active',
                EmployeeStatus::INACTIVE, EmployeeStatus::TERMINATED => 'inactive',
                EmployeeStatus::SUSPENDED => 'suspended',
            };
        }

        if ($rawStatus instanceof \BackedEnum) {
            $rawStatus = $rawStatus->value;
        }

        return match (strtolower(trim((string) $rawStatus))) {
            'active', 'on_leave', 'on-leave' => 'active',
            'inactive', 'terminated' => 'inactive',
            'suspended' => 'suspended',
            default => null,
        };
    }
}
