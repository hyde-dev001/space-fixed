<?php

namespace Tests\Feature\Cod;

use App\Models\Order;
use App\Models\Product;
use App\Models\Logistics\LogisticsSetting;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodCheckoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cod_checkout_creates_one_pending_collection_without_a_paymongo_request(): void
    {
        Http::preventStrayRequests();

        $customer = User::factory()->create([
            'identity_verification_status' => User::IDENTITY_APPROVED,
        ]);
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shopOwner->id,
            'coverage_radius_km' => 20,
        ]);
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'COD Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'postal_code' => '1000',
            'address_line' => '1 COD Street',
            'latitude' => 14.60,
            'longitude' => 120.98,
        ]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'COD Test Shoe',
            'slug' => 'cod-test-shoe-'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                'items' => [[
                    'id' => 'cod-item-1',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                ]],
                'total_amount' => 1000,
                'shipping_fee' => 50,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => '09171234567',
                'shipping_address' => $address->full_address,
                'address_id' => $address->id,
                'shipping_region' => $address->region,
                'shipping_province' => $address->province,
                'shipping_city' => $address->city,
                'shipping_barangay' => $address->barangay,
                'shipping_postal_code' => $address->postal_code,
                'shipping_address_line' => $address->address_line,
                'payment_method' => 'cash_on_delivery',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order = Order::query()->findOrFail((int) $response->json('order.id'));
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('pending', $order->payment_status);

        $collection = DB::table('cod_collections')
            ->where('order_id', $order->id)
            ->first();

        $this->assertNotNull($collection);
        $this->assertSame('pending', $collection->status);
        $this->assertEquals(
            (float) $order->total_amount + (float) $order->shipping_fee + (float) $order->vat_amount,
            (float) $collection->expected_amount,
        );
        $this->assertNotNull($order->invoice_id);
        $this->assertDatabaseHas('finance_invoices', [
            'id' => $order->invoice_id,
            'shop_id' => $shopOwner->id,
            'status' => 'sent',
            'payment_method' => 'cod',
        ]);
        $this->assertDatabaseCount('finance_invoice_payments', 0);
        $this->assertNull($order->paid_at);
    }

    #[Test]
    public function cod_checkout_rejects_an_address_outside_the_shop_delivery_radius(): void
    {
        Http::preventStrayRequests();

        $customer = User::factory()->create([
            'identity_verification_status' => User::IDENTITY_APPROVED,
        ]);
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shopOwner->id,
            'coverage_radius_km' => 1,
        ]);
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Outside Coverage Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Quezon City',
            'barangay' => 'Batasan Hills',
            'postal_code' => '1126',
            'address_line' => '1 Outside Coverage Street',
            'latitude' => 14.70,
            'longitude' => 121.10,
        ]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Outside Coverage COD Shoe',
            'slug' => 'outside-coverage-cod-shoe-'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                'items' => [[
                    'id' => 'outside-coverage-cod-item',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                ]],
                'total_amount' => 1000,
                'shipping_fee' => 50,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => '09171234567',
                'shipping_address' => $address->full_address,
                'address_id' => $address->id,
                'shipping_region' => $address->region,
                'shipping_province' => $address->province,
                'shipping_city' => $address->city,
                'shipping_barangay' => $address->barangay,
                'shipping_postal_code' => $address->postal_code,
                'shipping_address_line' => $address->address_line,
                'payment_method' => 'cash_on_delivery',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'cod_outside_delivery_radius');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function cod_checkout_is_not_available_for_an_individual_retail_shop(): void
    {
        Http::preventStrayRequests();

        $customer = User::factory()->create([
            'identity_verification_status' => User::IDENTITY_APPROVED,
        ]);
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shopOwner->id,
            'coverage_radius_km' => 20,
        ]);
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Individual Retail Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'postal_code' => '1000',
            'address_line' => '1 Individual Retail Street',
            'latitude' => 14.60,
            'longitude' => 120.98,
        ]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Individual Retail COD Shoe',
            'slug' => 'individual-retail-cod-shoe-'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                'items' => [[
                    'id' => 'individual-retail-cod-item',
                    'pid' => $product->id,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => 1000,
                ]],
                'total_amount' => 1000,
                'shipping_fee' => 50,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => '09171234567',
                'shipping_address' => $address->full_address,
                'address_id' => $address->id,
                'shipping_region' => $address->region,
                'shipping_province' => $address->province,
                'shipping_city' => $address->city,
                'shipping_barangay' => $address->barangay,
                'shipping_postal_code' => $address->postal_code,
                'shipping_address_line' => $address->address_line,
                'payment_method' => 'cash_on_delivery',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'cod_not_available_for_individual_shop');
        $this->assertDatabaseCount('orders', 0);
    }
}
