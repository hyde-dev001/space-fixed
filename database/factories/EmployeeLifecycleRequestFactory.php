<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployeeLifecycleRequestStatus;
use App\Enums\EmployeeLifecycleRequestType;
use App\Models\Employee;
use App\Models\EmployeeLifecycleRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeLifecycleRequest> */
final class EmployeeLifecycleRequestFactory extends Factory
{
    protected $model = EmployeeLifecycleRequest::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'requested_by' => User::factory(),
            'request_type' => EmployeeLifecycleRequestType::TERMINATION,
            'reason' => 'Employment lifecycle decision requires review.',
            'evidence' => null,
            'status' => EmployeeLifecycleRequestStatus::PENDING_MANAGER,
            'manager_id' => null,
            'manager_status' => 'pending',
            'manager_note' => null,
            'manager_reviewed_at' => null,
            'owner_id' => null,
            'owner_status' => 'pending',
            'owner_note' => null,
            'owner_reviewed_at' => null,
            'rehire_start_date' => null,
            'rehire_position' => null,
            'rehire_department' => null,
            'rehire_functional_role' => null,
            'rehire_salary' => null,
            'rehire_role' => null,
        ];
    }
}
