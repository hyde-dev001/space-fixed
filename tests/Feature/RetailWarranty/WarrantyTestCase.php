<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Services\RetailWarrantyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class WarrantyTestCase extends TestCase
{
    use RefreshDatabase;

    protected function purchase(string $status = 'shipped', string $businessType = 'retail'): Order
    {
        Queue::fake();
        $owner = ShopOwner::factory()->approved()->create(['business_type' => $businessType]);
        app(RetailWarrantyService::class)->saveSettings($owner, ['enabled' => true, 'title' => 'Product Warranty',
            'duration_value' => 1, 'duration_unit' => 'years', 'terms' => 'Custom manufacturing evaluation']);
        $order = Order::factory()->create(['shop_owner_id' => $owner->id, 'status' => $status, 'pickup_enabled' => true]);
        foreach (['Product A' => 2, 'Product B' => 1, 'Product C' => 1] as $name => $qty) {
            $slug = strtolower(str_replace(' ', '-', $name));
            $product = Product::create(['shop_owner_id' => $owner->id, 'name' => $name, 'slug' => $slug.'-'.$order->id,
                'price' => 250, 'stock_quantity' => 10, 'is_active' => true]);
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $name, 'product_slug' => $slug,
                'price' => 250, 'subtotal' => 250 * $qty, 'quantity' => $qty, 'size' => '9', 'color' => 'Black']);
        }

        return $order;
    }
}
