<?php

declare(strict_types=1);

namespace App\Services\HR;

use App\Models\EmployeeLifecycleRequest;

final class EmployeeLifecycleRequestPresenter
{
    /** @return array<string, mixed> */
    public function toArray(EmployeeLifecycleRequest $request): array
    {
        $employee = $request->employee;
        $status = $request->status?->value ?? (string) $request->getRawOriginal('status');
        $type = $request->request_type?->value ?? (string) $request->getRawOriginal('request_type');

        return [
            'id' => (int) $request->getKey(),
            'employee_id' => (int) $request->employee_id,
            'name' => $this->employeeName($employee),
            'email' => $employee?->email,
            'position' => $employee?->position,
            'request_type' => $type,
            'request_label' => $request->request_type?->label() ?? ucfirst($type).' request',
            'reason' => $request->reason,
            'evidence' => $request->evidence,
            'status' => $status,
            'workflow_status' => $status,
            'approval_stage' => match ($status) {
                'pending_manager' => 'manager',
                'pending_owner' => 'owner',
                default => 'complete',
            },
            'requested_at' => $request->created_at?->toISOString(),
            'requestedAt' => $request->created_at?->toISOString(),
            'requested_by' => $request->requester?->name ?? $request->requester?->email,
            'manager_status' => $request->manager_status,
            'manager_note' => $request->manager_note,
            'manager_name' => $request->manager?->name ?? $request->manager?->email,
            'owner_status' => $request->owner_status,
            'owner_note' => $request->owner_note,
            'owner_name' => $request->owner?->name ?? $request->owner?->email,
            'rehire' => [
                'start_date' => $request->rehire_start_date?->toDateString(),
                'position' => $request->rehire_position,
                'department' => $request->rehire_department,
                'functional_role' => $request->rehire_functional_role,
                'salary' => $request->rehire_salary !== null ? (float) $request->rehire_salary : null,
                'role' => $request->rehire_role,
            ],
            'rehire_start_date' => $request->rehire_start_date?->toDateString(),
            'rehire_position' => $request->rehire_position,
            'rehire_department' => $request->rehire_department,
            'rehire_functional_role' => $request->rehire_functional_role,
            'rehire_salary' => $request->rehire_salary !== null ? (float) $request->rehire_salary : null,
            'rehire_role' => $request->rehire_role,
            'age_days' => $request->created_at ? $request->created_at->diffInDays(now()) : 0,
            'overdue' => false,
            'sla' => ['configured' => false, 'minutes' => null],
            'next_action' => match ($status) {
                'pending_manager' => 'Manager review is required.',
                'pending_owner' => 'Shop Owner final review is required.',
                'approved' => $type === 'termination'
                    ? 'Employment closed and linked account disabled.'
                    : 'New employment period opened and linked account enabled.',
                default => 'No further action is required.',
            },
            'previous_decisions' => [
                [
                    'stage' => 'manager',
                    'status' => $request->manager_status,
                    'actor_id' => $request->manager_id !== null ? (int) $request->manager_id : null,
                    'at' => $request->manager_reviewed_at?->toISOString(),
                    'reason' => $request->manager_note,
                ],
                [
                    'stage' => 'owner',
                    'status' => $request->owner_status,
                    'actor_id' => $request->owner_id !== null ? (int) $request->owner_id : null,
                    'at' => $request->owner_reviewed_at?->toISOString(),
                    'reason' => $request->owner_note,
                ],
            ],
        ];
    }

    private function employeeName(mixed $employee): string
    {
        if (! $employee) {
            return 'Employee';
        }

        return trim((string) ($employee->name ?: implode(' ', array_filter([
            $employee->first_name,
            $employee->last_name,
        ])))) ?: 'Employee';
    }
}
