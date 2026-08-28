<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class SuspensionWorkflowAuthorizationService
{
    public function canReadOrSubmit(User $actor): bool
    {
        return $this->shopOwnerId($actor) !== null && $this->isHrActor($actor);
    }

    public function shopOwnerId(User $actor): ?int
    {
        $shopOwnerId = (int) $actor->getAttribute('shop_owner_id');

        if ($shopOwnerId < 1 || ! $actor->shopOwner()->whereKey($shopOwnerId)->exists()) {
            return null;
        }

        return $shopOwnerId;
    }

    public function employeeForActor(User $actor, int $employeeId): ?Employee
    {
        $shopOwnerId = $this->shopOwnerId($actor);

        if ($shopOwnerId === null) {
            return null;
        }

        return Employee::query()
            ->whereKey($employeeId)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();
    }

    private function isHrActor(User $actor): bool
    {
        if (strtoupper(trim((string) $actor->getAttribute('role'))) === 'HR') {
            return true;
        }

        try {
            return $actor->hasRole('HR', 'user')
                || $actor->hasPermissionTo('request-employee-suspensions', 'user');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
