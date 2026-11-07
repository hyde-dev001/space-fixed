<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryBatchFactory extends Factory
{
    protected $model = DeliveryBatch::class;
    public function definition(): array
    {
        return ['shop_owner_id' => ShopOwner::factory(), 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning'];
    }
}
