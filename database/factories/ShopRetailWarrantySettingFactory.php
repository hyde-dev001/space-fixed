<?php

namespace Database\Factories;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopRetailWarrantySettingFactory extends Factory
{
    public function definition(): array
    {
        return ['shop_owner_id' => ShopOwner::factory()->state(['business_type' => 'retail']), 'enabled' => false,
            'title' => 'Product Warranty', 'duration_value' => 5, 'duration_unit' => 'days', 'terms' => 'Assessment of manufacturing defects.'];
    }
}
