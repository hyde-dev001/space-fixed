<?php

namespace Database\Factories;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RepairRequest>
 */
class RepairRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\RepairRequest>
     */
    protected $model = RepairRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $createdAt = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'request_id' => 'REP-' . strtoupper($this->faker->bothify('??###??##')),
            'customer_name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->numerify('09#########'),
            'shoe_type' => $this->faker->randomElement(['Sneakers', 'Boots', 'Loafers', 'Sandals']),
            'brand' => $this->faker->randomElement(['Nike', 'Adidas', 'Puma', 'New Balance']),
            'description' => $this->faker->sentence(),
            'shop_owner_id' => ShopOwner::factory(),
            'user_id' => User::factory(),
            'total' => $this->faker->randomFloat(2, 150, 3500),
            'status' => 'new_request',
            'delivery_method' => 'walk_in',
            'payment_status' => 'pending',
            'payment_enabled' => false,
            'payment_policy' => 'deposit_50',
            'is_high_value' => false,
            'requires_owner_approval' => false,
            'assignment_method' => 'auto',
            'reassignment_count' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    public function assignedToRepairer(?User $repairer = null): static
    {
        return $this->state(function () use ($repairer): array {
            return [
                'assigned_repairer_id' => $repairer?->id ?? User::factory(),
                'status' => 'assigned_to_repairer',
                'assigned_at' => now(),
            ];
        });
    }

    public function repairerRejected(string $reason = 'Repair rejected by repairer'): static
    {
        return $this->state(fn (): array => [
            'status' => 'repairer_rejected',
            'repairer_rejection_reason' => $reason,
            'repairer_rejected_at' => now(),
        ]);
    }
}
