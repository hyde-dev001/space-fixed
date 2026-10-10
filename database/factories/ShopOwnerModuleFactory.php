<?php

namespace Database\Factories;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopOwnerModule>
 */
class ShopOwnerModuleFactory extends Factory
{
    protected $model = ShopOwnerModule::class;

    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory()->approved(),
            'module_key' => 'inventory',
            'enabled' => true,
        ];
    }
}
