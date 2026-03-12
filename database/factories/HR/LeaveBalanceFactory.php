<?php

namespace Database\Factories\HR;

use App\Models\HR\LeaveBalance;
use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HR\LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = LeaveBalance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'shop_owner_id' => ShopOwner::factory(),
            'year' => now()->year,
            'vacation_days' => 15,
            'sick_days' => 10,
            'personal_days' => 5,
            'maternity_days' => 0,
            'paternity_days' => 0,
            'used_vacation' => $this->faker->numberBetween(0, 5),
            'used_sick' => $this->faker->numberBetween(0, 3),
            'used_personal' => $this->faker->numberBetween(0, 2),
            'used_maternity' => 0,
            'used_paternity' => 0,
        ];
    }

    /**
     * Indicate that some vacation days have been used.
     */
    public function withUsedVacation(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'used_vacation' => $days,
        ]);
    }

    /**
     * Indicate that the employee has maternity leave.
     */
    public function withMaternity(int $total = 90, int $used = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'maternity_days' => $total,
            'used_maternity' => $used,
        ]);
    }

    /**
     * Indicate that the employee has paternity leave.
     */
    public function withPaternity(int $total = 14, int $used = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'paternity_days' => $total,
            'used_paternity' => $used,
        ]);
    }
}
