<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\LogisticsSetting;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\DeliveryScheduleService;
use App\Services\RepairDeliveryService;
use App\Services\ShippingEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairDeliveryQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_quote_an_owned_pinned_address_using_retail_formula(): void
    {
        [$customer, $shop] = $this->customerAndShop();
        $address = $this->address($customer, 14.6000, 120.9800);

        $coverage = app(DeliveryScheduleService::class)->coverage($shop, $address->latitude, $address->longitude);
        $estimate = app(ShippingEstimateService::class)->calculate($coverage['distance_km']);

        $this->actingAs($customer, 'user')
            ->getJson("/api/repair/shops/{$shop->id}/delivery-quote?address_id={$address->id}")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('distance_km', $coverage['distance_km'])
            ->assertJsonPath('fee', $estimate['max_fee']);
    }

    public function test_customer_cannot_quote_another_customers_address(): void
    {
        [$customer, $shop] = $this->customerAndShop();
        $foreign = $this->address(User::factory()->create(), 14.6000, 120.9800);

        $this->actingAs($customer, 'user')
            ->getJson("/api/repair/shops/{$shop->id}/delivery-quote?address_id={$foreign->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');
    }

    public function test_missing_pin_and_outside_radius_return_clear_reasons(): void
    {
        [$customer, $shop] = $this->customerAndShop();
        $unpinned = $this->address($customer, null, null);
        $outside = $this->address($customer, 15.0000, 121.5000);

        $this->actingAs($customer, 'user')
            ->getJson("/api/repair/shops/{$shop->id}/delivery-quote?address_id={$unpinned->id}")
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'address_needs_pin')
            ->assertJsonPath('fee', null);

        $this->actingAs($customer, 'user')
            ->getJson("/api/repair/shops/{$shop->id}/delivery-quote?address_id={$outside->id}")
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'outside_coverage')
            ->assertJsonPath('fee', null);
    }

    public function test_boundary_distance_and_rounding_reuse_existing_services(): void
    {
        [$customer, $shop] = $this->customerAndShop();
        $address = $this->address($customer, 14.6251, 120.9842);
        $coverage = app(DeliveryScheduleService::class)->coverage($shop, $address->latitude, $address->longitude);
        $shop->logisticsSetting->update(['coverage_radius_km' => $coverage['distance_km']]);
        $shop->unsetRelation('logisticsSetting');

        $quote = app(RepairDeliveryService::class)->quote($shop->fresh(), $address);
        $estimate = app(ShippingEstimateService::class)->calculate($coverage['distance_km']);

        $this->assertTrue($quote['available']);
        $this->assertSame($coverage['distance_km'], $quote['distance_km']);
        $this->assertSame($estimate['max_fee'], $quote['fee']);
    }

    public function test_snapshot_is_stable_and_method_specific(): void
    {
        [$customer] = $this->customerAndShop();
        $address = $this->address($customer, 14.6000, 120.9800);
        $service = app(RepairDeliveryService::class);

        $pickup = $service->snapshot($address, 'shop_pickup');
        $return = $service->snapshot($address, 'shop_delivery');

        $this->assertSame($address->id, $pickup['address_id']);
        $this->assertSame($address->latitude, $pickup['latitude']);
        $this->assertSame($pickup['version'], $service->version($pickup, 'shop_pickup'));
        $this->assertNotSame($pickup['version'], $return['version']);
    }

    private function customerAndShop(): array
    {
        $customer = User::factory()->create();
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 10,
        ]);

        return [$customer, $shop->fresh('logisticsSetting')];
    }

    private function address(User $user, ?float $latitude, ?float $longitude): UserAddress
    {
        return UserAddress::create([
            'user_id' => $user->id,
            'name' => 'Miguel Dela Rosa',
            'phone' => '09171234567',
            'region' => 'CALABARZON',
            'province' => 'Cavite',
            'city' => 'General Trias City',
            'barangay' => 'Buenavista II',
            'postal_code' => '4107',
            'address_line' => '126 Ilang-ilang Street',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'delivery_instructions' => 'Blue gate',
        ]);
    }
}
