<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory(),
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => $this->faker->unique()->safeEmail,
            'password' => Hash::make('password'),
            'phone' => $this->faker->phoneNumber,
            'address' => $this->faker->address,
            'city' => $this->faker->city,
            'state' => $this->faker->state,
            'zip_code' => $this->faker->postcode,
            'emergency_contact' => $this->faker->name,
            'emergency_phone' => $this->faker->phoneNumber,
            'position' => $this->faker->jobTitle,
            'department' => $this->faker->randomElement(['Sales', 'Technical', 'Finance', 'HR', 'Operations']),
            'branch' => $this->faker->randomElement(['Main', 'Branch 1', 'Branch 2']),
            'functional_role' => $this->faker->randomElement(['manager', 'staff', 'technician', 'accountant']),
            'salary' => $this->faker->randomFloat(2, 30000, 150000),
            'hire_date' => $this->faker->dateTimeBetween('-5 years', 'now'),
            'status' => $this->faker->randomElement(['active', 'inactive', 'on_leave', 'suspended']),
        ];
    }

    /**
     * Indicate that the employee is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the employee is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the employee is on leave.
     */
    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'on_leave',
        ]);
    }

    /**
     * Indicate that the employee is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}
