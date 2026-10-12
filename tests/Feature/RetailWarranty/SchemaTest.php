<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\Order;
use App\Models\RetailWarrantyIssuance;
use App\Models\ShopRetailWarrantySetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_child_schema_defaults_disabled_and_one_issuance_per_order(): void
    {
        $this->assertTrue(Schema::hasTable('shop_retail_warranty_settings'));
        $this->assertTrue(Schema::hasTable('retail_warranty_issuances'));
        $this->assertTrue(Schema::hasTable('retail_warranties'));
        $order = Order::factory()->create();
        $setting = ShopRetailWarrantySetting::create(['shop_owner_id' => $order->shop_owner_id]);
        $this->assertFalse($setting->fresh()->enabled);
        $issuance = RetailWarrantyIssuance::create([
            'shop_owner_id' => $order->shop_owner_id, 'order_id' => $order->id,
            'customer_id' => $order->customer_id, 'warranty_number' => 'WRNTY-TEST-A',
            'fulfilled_at' => now(), 'issued_at' => now(), 'business_timezone' => 'Asia/Manila',
            'shop_snapshot' => [], 'customer_snapshot' => [],
        ]);
        $this->assertSame($issuance->id, $order->retailWarrantyIssuance->id);
        $this->assertSame('pending', $issuance->fresh()->email_delivery_state);
        $this->assertTrue(Schema::hasColumn('order_refund_items', 'retail_warranty_id'));
        $this->assertTrue(Schema::hasColumn('pos_refund_items', 'retail_warranty_id'));
        $this->expectException(QueryException::class);
        RetailWarrantyIssuance::create(array_merge($issuance->getAttributes(), [
            'id' => null, 'warranty_number' => 'WRNTY-TEST-B',
        ]));
    }
}
