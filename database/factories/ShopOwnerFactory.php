<?php

namespace Database\Factories;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopOwner>
 */
class ShopOwnerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ShopOwner::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => $this->faker->unique()->safeEmail,
            'phone' => $this->faker->phoneNumber,
            'password' => Hash::make('password'), // Default password for testing
            'business_name' => $this->faker->company,
            'business_address' => $this->faker->address,
            'business_type' => $this->faker->randomElement(['retail', 'repair', 'both']),
            'registration_type' => $this->faker->randomElement(['individual', 'company']),
            'operating_hours' => json_encode([
                'monday' => ['open' => '09:00', 'close' => '17:00'],
                'tuesday' => ['open' => '09:00', 'close' => '17:00'],
                'wednesday' => ['open' => '09:00', 'close' => '17:00'],
                'thursday' => ['open' => '09:00', 'close' => '17:00'],
                'friday' => ['open' => '09:00', 'close' => '17:00'],
                'saturday' => ['open' => '09:00', 'close' => '17:00'],
                'sunday' => ['open' => '10:00', 'close' => '16:00'],
            ]),
            'status' => $this->faker->randomElement(['pending', 'approved', 'rejected']),
            'rejection_reason' => $this->faker->optional()->sentence,
        ];
    }

    /**
     * Indicate that the shop owner is pending.
     */
    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the shop owner is approved.
     */
    public function approved(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'approved',
        ]);
    }

    /**
     * Indicate that the shop owner is rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'rejected',
        ]);
    }
}
