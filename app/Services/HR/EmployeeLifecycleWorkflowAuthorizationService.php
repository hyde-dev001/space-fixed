<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Enums\EmployeeLifecycleRequestType;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class EmployeeLifecycleWorkflowAuthorizationService
{
    public function canSubmit(User $actor, EmployeeLifecycleRequestType $type): bool
    {
        return $this->shopOwnerId($actor) !== null && $this->isCompanyShop($actor)
            && $this->isHrActor($actor, $type);
    }

    public function shopOwnerId(User $actor): ?int
    {
        $shopOwnerId = (int) $actor->getAttribute('shop_owner_id');

        if ($shopOwnerId < 1 || ! $actor->shopOwner()->whereKey($shopOwnerId)->exists()) {
            return null;
        }

        return $shopOwnerId;
    }

    public function shopOwnerFor(User $actor): ?ShopOwner
    {
        $shopOwnerId = $this->shopOwnerId($actor);

        if ($shopOwnerId === null) {
            return null;
        }

        return $actor->shopOwner()->whereKey($shopOwnerId)->first();
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

    private function isCompanyShop(User $actor): bool
    {
        return strtolower(trim((string) $actor->shopOwner?->registration_type)) === 'company';
    }

    private function isHrActor(User $actor, EmployeeLifecycleRequestType $type): bool
    {
        if (strtoupper(trim((string) $actor->getAttribute('role'))) === 'HR') {
            return true;
        }

        $permission = match ($type) {
            EmployeeLifecycleRequestType::TERMINATION => 'request-employee-terminations',
            EmployeeLifecycleRequestType::REHIRE => 'request-employee-rehires',
        };

        try {
            return $actor->hasRole('HR', 'user') || $actor->hasPermissionTo($permission, 'user');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
