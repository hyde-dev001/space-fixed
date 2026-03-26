<?php

namespace Database\Factories;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\SuspensionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuspensionRequest>
 */
class SuspensionRequestFactory extends Factory
{
    protected $model = SuspensionRequest::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'requested_by' => User::factory(),
            'reason' => $this->faker->sentence(),
            'evidence' => null,
            'status' => SuspensionStatus::PENDING_MANAGER,
            'manager_id' => null,
            'manager_status' => 'pending',
            'manager_note' => null,
            'manager_reviewed_at' => null,
            'owner_id' => null,
            'owner_status' => 'pending',
            'owner_note' => null,
            'owner_reviewed_at' => null,
        ];
    }
}
