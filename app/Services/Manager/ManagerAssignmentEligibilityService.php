<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Employee;
use App\Models\RepairerUnavailability;
use App\Models\User;
use App\Services\HR\EmployeeOperationalPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class ManagerAssignmentEligibilityService
{
    public function __construct(
        private readonly EmployeeOperationalPolicy $employeePolicy,
    ) {
    }

    /**
     * @return array{eligible: bool, reason_code: ?string, reason_label: ?string}
     */
    public function evaluate(
        User $assignee,
        int $shopOwnerId,
        string $workType,
        CarbonInterface|string|null $activeWorkDate = null,
        ?string $requiredSkill = null,
    ): array {
        $workType = strtolower(trim($workType));

        if (! in_array($workType, ['order', 'repair'], true)) {
            throw new InvalidArgumentException('Unsupported assignment work type.');
        }

        $date = CarbonImmutable::parse($activeWorkDate ?? now());

        if ((int) $assignee->getAttribute('shop_owner_id') !== $shopOwnerId) {
            return $this->ineligible('wrong_shop');
        }

        $accountReason = $this->userAccountReason($assignee);
        if ($accountReason !== null) {
            return $this->ineligible($accountReason);
        }

        $employee = $this->employeeFor($assignee, $shopOwnerId);
        if ($employee !== null) {
            if ($employee->isOffboarded()) {
                return $this->ineligible('offboarded');
            }

            $employeeReason = $this->employeePolicy->assignmentIneligibilityReason($employee, $date);
            if ($employeeReason !== null) {
                return $this->ineligible($employeeReason);
            }
        }

        if ($workType === 'repair' && $this->isExplicitlyUnavailable($assignee, $shopOwnerId, $date)) {
            return $this->ineligible('explicitly_unavailable');
        }

        if (! $this->hasEligibleRole($assignee, $workType)) {
            return $this->ineligible('role_ineligible');
        }

        if ($requiredSkill !== null && trim($requiredSkill) !== '' && ! $this->hasPermission($assignee, trim($requiredSkill))) {
            return $this->ineligible('missing_required_skill');
        }

        return $this->eligible();
    }

    /**
     * @return array{eligible: bool, reason_code: ?string, reason_label: ?string}
     */
    private function eligible(): array
    {
        return [
            'eligible' => true,
            'reason_code' => null,
            'reason_label' => null,
        ];
    }

    /**
     * @return array{eligible: bool, reason_code: string, reason_label: string}
     */
    private function ineligible(string $reasonCode): array
    {
        return [
            'eligible' => false,
            'reason_code' => $reasonCode,
            'reason_label' => match ($reasonCode) {
                'wrong_shop' => 'Assignee belongs to another shop.',
                'inactive' => 'Assignee is inactive.',
                'suspended' => 'Assignee is suspended.',
                'terminated' => 'Assignee is terminated.',
                'resigned' => 'Assignee has resigned.',
                'offboarded' => 'Assignee is offboarded.',
                'approved_leave' => 'Approved leave covers the active work date.',
                'explicitly_unavailable' => 'Repairer is explicitly unavailable on the active work date.',
                'role_ineligible' => 'Assignee no longer has an eligible work role.',
                'missing_required_skill' => 'Assignee does not have the required skill capability.',
                default => 'Assignee is not eligible for this work.',
            },
        ];
    }

    private function userAccountReason(User $user): ?string
    {
        if ($user->trashed()) {
            return 'offboarded';
        }

        return match (strtolower(trim((string) ($user->getRawOriginal('status') ?? $user->getAttribute('status'))))) {
            'inactive' => 'inactive',
            'suspended' => 'suspended',
            'terminated' => 'terminated',
            'resigned' => 'resigned',
            'offboarded' => 'offboarded',
            default => null,
        };
    }

    private function employeeFor(User $user, int $shopOwnerId): ?Employee
    {
        if ($user->relationLoaded('employee')) {
            $employee = $user->getRelation('employee');

            return $employee instanceof Employee && (int) $employee->shop_owner_id === $shopOwnerId
                ? $employee
                : null;
        }

        return Employee::withTrashed()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('email', $user->email)
            ->first();
    }

    private function isExplicitlyUnavailable(User $repairer, int $shopOwnerId, CarbonImmutable $date): bool
    {
        $record = RepairerUnavailability::query()
            ->where('repairer_id', $repairer->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('month_key', $date->format('Y-m'))
            ->first();

        if ($record === null) {
            return false;
        }

        return in_array($date->toDateString(), $record->unavailable_dates ?? [], true);
    }

    private function hasEligibleRole(User $user, string $workType): bool
    {
        $roles = $workType === 'repair' ? ['Repairer'] : ['Staff', 'Manager'];

        foreach ($roles as $role) {
            if (strtoupper(trim((string) $user->getAttribute('role'))) === strtoupper($role)) {
                return true;
            }

            try {
                if ($user->hasRole($role, 'user')) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                // A missing role is not an eligibility grant.
            }
        }

        return false;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission, 'user');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
