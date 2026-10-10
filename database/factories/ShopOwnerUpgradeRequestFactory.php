<?php

namespace Database\Factories;

use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopOwnerUpgradeRequest>
 */
class ShopOwnerUpgradeRequestFactory extends Factory
{
    protected $model = ShopOwnerUpgradeRequest::class;

    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory()->approved(),
            'current_registration_type' => 'individual',
            'current_business_type' => 'retail',
            'requested_registration_type' => 'company',
            'requested_business_type' => 'both',
            'status' => ShopOwnerUpgradeRequest::STATUS_PENDING,
            'required_document_set' => [],
            'decision_reason' => null,
            'reviewed_by_super_admin_id' => null,
            'reviewed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShopOwnerUpgradeRequest::STATUS_PENDING,
            'decision_reason' => null,
            'reviewed_by_super_admin_id' => null,
            'reviewed_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShopOwnerUpgradeRequest::STATUS_APPROVED,
            'decision_reason' => 'Approved by Super Admin.',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShopOwnerUpgradeRequest::STATUS_REJECTED,
            'decision_reason' => 'Additional evidence is required.',
            'reviewed_at' => now(),
        ]);
    }
}
