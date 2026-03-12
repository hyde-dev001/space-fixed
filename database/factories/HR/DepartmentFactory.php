<?php

namespace Database\Factories\HR;

use App\Models\HR\Department;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HR\Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Department::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory(),
            'name' => $this->faker->randomElement(['Sales', 'Technical', 'Finance', 'HR', 'Operations', 'Marketing']),
            'description' => $this->faker->sentence,
            'manager_name' => $this->faker->name,
            'location' => $this->faker->randomElement(['Main Office', 'Branch 1', 'Branch 2', 'Remote']),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the department is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
