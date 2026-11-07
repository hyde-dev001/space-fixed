<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeEmploymentPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeEmploymentPeriod> */
final class EmployeeEmploymentPeriodFactory extends Factory
{
    protected $model = EmployeeEmploymentPeriod::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'shop_owner_id' => fn (array $attributes): int => (int) Employee::query()
                ->find($attributes['employee_id'])?->shop_owner_id,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'end_reason' => null,
            'position' => 'Employee',
            'department' => 'General',
            'functional_role' => null,
            'salary' => 0,
            'role' => 'Staff',
        ];
    }
}
