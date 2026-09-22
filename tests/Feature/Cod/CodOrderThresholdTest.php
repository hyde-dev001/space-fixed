<?php

namespace Tests\Feature\Cod;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CodOrderThresholdTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_cod_is_available_at_the_threshold_when_logistics_is_ready(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop(['cod_order_threshold' => 5000]);
        $product = $this->product($shop, 5000, 'boundary');
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $address, 5000));

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', ['id' => $response->json('order.id'), 'payment_method' => 'cod']);
    }

    #[Test]
    public function cod_uses_the_combined_discounted_merchandise_total(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop(['registration_type' => 'company', 'cod_order_threshold' => 5000]);
        $product = $this->product($shop, 6000, 'discounted');
        PromoCampaign::create([
            'shop_owner_id' => $shop->id,
            'kind' => 'sale',
            'scope' => 'shop_wide',
            'name' => 'COD threshold sale',
            'discount_mode' => 'percentage',
            'value' => 20,
            'min_spend' => 0,
            'used_count' => 0,
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
            'status' => 'active',
            'stacking_mode' => 'combinable',
        ]);
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                ...$this->payload($product, $customer, $address, 6000),
                'disable_voucher' => false,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', ['id' => $response->json('order.id'), 'payment_method' => 'cod']);
    }

    #[Test]
    public function cod_is_available_for_a_shop_with_both_retail_and_repair_capabilities(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop(['business_type' => 'both', 'cod_order_threshold' => 5000]);
        $product = $this->product($shop, 4500, 'both');
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $address, 4500));

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', ['id' => $response->json('order.id'), 'payment_method' => 'cod']);
    }

    #[Test]
    public function cod_rejects_a_combined_merchandise_total_above_the_threshold(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop(['cod_order_threshold' => 5000]);
        $first = $this->product($shop, 3000, 'first');
        $second = $this->product($shop, 3000, 'second');
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                'items' => [
                    ['id' => 'threshold-first', 'pid' => $first->id, 'qty' => 1, 'name' => $first->name, 'price' => 3000],
                    ['id' => 'threshold-second', 'pid' => $second->id, 'qty' => 1, 'name' => $second->name, 'price' => 3000],
                ],
                'total_amount' => 6000,
                'shipping_fee' => 50,
                ...$this->addressPayload($customer, $address),
                'payment_method' => 'cash_on_delivery',
                'delivery_method' => 'shop_owned',
            ]);

        $response->assertUnprocessable()->assertJsonPath('error', 'cod_exceeds_threshold');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function cod_does_not_trust_a_forged_client_price(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop(['cod_order_threshold' => 5000]);
        $product = $this->product($shop, 6000, 'forged');
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $address, 1));

        $response->assertUnprocessable()->assertJsonPath('error', 'cod_exceeds_threshold');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function cod_rejects_disabled_or_third_party_delivery_requests(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $address = $this->address($customer);

        $disabledShop = $this->shop(['cod_enabled' => false]);
        $disabledProduct = $this->product($disabledShop, 1000, 'disabled');
        $disabledResponse = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($disabledProduct, $customer, $address, 1000));
        $disabledResponse->assertUnprocessable()->assertJsonPath('error', 'cod_disabled');

        $thirdPartyShop = $this->shop();
        $thirdPartyProduct = $this->product($thirdPartyShop, 1000, 'third-party');
        $thirdPartyResponse = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', [
                ...$this->payload($thirdPartyProduct, $customer, $address, 1000),
                'delivery_method' => 'third_party',
            ]);
        $thirdPartyResponse->assertUnprocessable()->assertJsonPath('error', 'cod_delivery_method_not_supported');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function cod_is_unavailable_when_shop_owned_logistics_is_disabled(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop([], false);
        $product = $this->product($shop, 1000, 'logistics-disabled');
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $address, 1000));

        $response->assertUnprocessable()->assertJsonPath('error', 'logistics_module_unavailable');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function cod_is_unavailable_until_an_active_dispatcher_and_rider_exist(): void
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $shop = $this->shop([], false);
        ShopOwnerModule::create([
            'shop_owner_id' => $shop->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);
        $product = $this->product($shop, 1000, 'staff-missing');
        $address = $this->address($customer);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/api/checkout/create-order', $this->payload($product, $customer, $address, 1000));

        $response->assertUnprocessable()->assertJsonPath('error', 'logistics_staff_unavailable');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function a_new_shop_defaults_cash_on_delivery_to_off(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
        ]);

        $this->assertFalse((bool) $shop->getRawOriginal('cod_enabled'));
    }

    private function shop(array $attributes = [], bool $withCodRequirements = true): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
            'cod_enabled' => true,
            'cod_order_threshold' => 5000,
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
            ...$attributes,
        ]);

        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 20,
        ]);

        if ($withCodRequirements) {
            $this->enableCodRequirements($shop);
        }

        return $shop;
    }

    private function enableCodRequirements(ShopOwner $shop): void
    {
        ShopOwnerModule::create([
            'shop_owner_id' => $shop->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        $dispatcher = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
        ]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('assign-logistics-deliveries', 'user'));

        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
        ]);
    }

    private function product(ShopOwner $shop, float $price, string $label): Product
    {
        return Product::create([
            'shop_owner_id' => $shop->id,
            'name' => "COD {$label} shoe",
            'slug' => "cod-{$label}-" . random_int(1000, 9999),
            'price' => $price,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    private function address(User $customer): UserAddress
    {
        return UserAddress::create([
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
    }

    private function payload(Product $product, User $customer, UserAddress $address, float $clientPrice): array
    {
        return [
            'items' => [[
                'id' => 'cod-item',
                'pid' => $product->id,
                'qty' => 1,
                'name' => $product->name,
                'price' => $clientPrice,
            ]],
            'total_amount' => $clientPrice,
            'shipping_fee' => 50,
            ...$this->addressPayload($customer, $address),
            'payment_method' => 'cash_on_delivery',
            'delivery_method' => 'shop_owned',
        ];
    }

    private function addressPayload(User $customer, UserAddress $address): array
    {
        return [
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $address->phone,
            'shipping_address' => $address->full_address,
            'address_id' => $address->id,
            'shipping_region' => $address->region,
            'shipping_province' => $address->province,
            'shipping_city' => $address->city,
            'shipping_barangay' => $address->barangay,
            'shipping_postal_code' => $address->postal_code,
            'shipping_address_line' => $address->address_line,
        ];
    }
}
