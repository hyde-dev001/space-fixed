<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutAddressOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_create_an_order_with_another_customers_address(): void
    {
        $customer = User::factory()->create();
        $foreignAddress = $this->addressFor(User::factory()->create());
        $product = $this->productFor(ShopOwner::factory()->approved()->create());

        $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $foreignAddress))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_customer_can_create_an_order_with_their_own_address(): void
    {
        $customer = User::factory()->create();
        $address = $this->addressFor($customer);
        $product = $this->productFor(ShopOwner::factory()->approved()->create());

        $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $address))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'address_id' => $address->id,
        ]);
    }

    private function productFor(ShopOwner $shop): Product
    {
        return Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Address Ownership Shoe',
            'slug' => 'address-ownership-shoe-'.random_int(1000, 9999),
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    private function addressFor(User $user): UserAddress
    {
        return UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'postal_code' => '1000',
            'address_line' => '1 Test Street',
            'latitude' => 14.60,
            'longitude' => 120.98,
        ]);
    }

    private function payload(Product $product, User $customer, UserAddress $address): array
    {
        return [
            'items' => [[
                'id' => 'cart-item-1',
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
            'payment_method' => 'cod',
        ];
    }
}
