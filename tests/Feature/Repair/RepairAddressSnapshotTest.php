<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\LogisticsSetting;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairAddressSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_shop_owned_intake_and_same_address_return_store_independent_authoritative_snapshots(): void
    {
        [$customer, $shop, $service] = $this->fixtures();
        $address = $this->address($customer, ['address_line' => '126 Ilang-ilang Street']);

        $this->submit($customer, $shop, $service, [
            'intake_delivery_method' => 'shop_pickup',
            'intake_address_id' => $address->id,
            'return_delivery_method' => 'shop_delivery',
            'same_as_intake_address' => true,
        ])->assertOk()->assertJsonPath('success', true);

        $repair = RepairRequest::query()->latest('id')->firstOrFail();
        $this->assertFalse((bool) $repair->payment_enabled);
        $this->assertNull($repair->payment_enabled_at);
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/activate-payment")
            ->assertStatus(400);
        $this->assertSame($address->id, $repair->intake_address['address_id']);
        $this->assertSame($address->id, $repair->return_address['address_id']);
        $this->assertSame($repair->intake_address['address_line'], $repair->return_address['address_line']);
        $this->assertNotSame($repair->intake_address['version'], $repair->return_address['version']);
        $this->assertTrue($repair->same_as_intake_address);
        $this->assertGreaterThan(0, (float) $repair->intake_delivery_fee);
        $this->assertGreaterThan(0, (float) $repair->return_delivery_fee);
        $this->assertTrue($repair->intake_logistics_quote['available']);
        $this->assertTrue($repair->return_logistics_quote['available']);

        $address->update(['address_line' => 'Edited after booking']);
        $repair->refresh();
        $this->assertSame('126 Ilang-ilang Street', $repair->intake_address['address_line']);
        $this->assertSame('126 Ilang-ilang Street', $repair->return_address['address_line']);
    }

    public function test_third_party_intake_and_return_can_use_separate_saved_addresses_without_system_fees(): void
    {
        [$customer, $shop, $service] = $this->fixtures();
        $intake = $this->address($customer, ['address_line' => 'Intake address']);
        $return = $this->address($customer, ['address_line' => 'Return address']);

        $this->submit($customer, $shop, $service, [
            'intake_delivery_method' => 'customer_delivery',
            'intake_address_id' => $intake->id,
            'return_delivery_method' => 'customer_pickup',
            'return_address_id' => $return->id,
            'same_as_intake_address' => false,
        ])->assertOk();

        $repair = RepairRequest::query()->latest('id')->firstOrFail();
        $this->assertSame('customer_delivery', $repair->intake_delivery_method);
        $this->assertSame('customer_pickup', $repair->return_delivery_method);
        $this->assertSame($intake->id, $repair->intake_address['address_id']);
        $this->assertSame($return->id, $repair->return_address['address_id']);
        $this->assertSame(0.0, (float) $repair->intake_delivery_fee);
        $this->assertSame(0.0, (float) $repair->return_delivery_fee);
        $this->assertFalse($repair->same_as_intake_address);
    }

    public function test_walk_in_both_ways_does_not_require_an_address(): void
    {
        [$customer, $shop, $service] = $this->fixtures();

        $this->submit($customer, $shop, $service, [
            'intake_delivery_method' => 'walk_in',
            'return_delivery_method' => 'walk_in',
            'same_as_intake_address' => true,
        ])->assertOk();

        $repair = RepairRequest::query()->latest('id')->firstOrFail();
        $this->assertNull($repair->intake_address);
        $this->assertNull($repair->return_address);
        $this->assertSame(0.0, (float) $repair->intake_delivery_fee);
        $this->assertSame(0.0, (float) $repair->return_delivery_fee);
    }

    public function test_foreign_addresses_and_outside_coverage_are_rejected_only_for_shop_owned_choices(): void
    {
        [$customer, $shop, $service] = $this->fixtures();
        $foreign = $this->address(User::factory()->create());
        $outside = $this->address($customer, ['latitude' => 15.2, 'longitude' => 121.7]);

        $this->submit($customer, $shop, $service, [
            'intake_delivery_method' => 'shop_pickup',
            'intake_address_id' => $foreign->id,
            'return_delivery_method' => 'walk_in',
        ])->assertUnprocessable()->assertJsonValidationErrors('intake_address_id');

        $this->submit($customer, $shop, $service, [
            'intake_delivery_method' => 'shop_pickup',
            'intake_address_id' => $outside->id,
            'return_delivery_method' => 'walk_in',
        ])->assertUnprocessable()->assertJsonValidationErrors('intake_address_id');

        $this->submit($customer, $shop, $service, [
            'intake_delivery_method' => 'customer_delivery',
            'intake_address_id' => $outside->id,
            'return_delivery_method' => 'customer_pickup',
            'same_as_intake_address' => true,
        ])->assertOk();
    }

    private function fixtures(): array
    {
        $customer = User::factory()->create();
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 12,
        ]);
        $service = RepairService::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Deep clean',
            'category' => 'Cleaning',
            'price' => 500,
            'duration' => '2 days',
            'description' => 'Test service',
            'status' => 'active',
        ]);

        return [$customer, $shop, $service];
    }

    private function address(User $customer, array $overrides = []): UserAddress
    {
        return UserAddress::create(array_merge([
            'user_id' => $customer->id,
            'name' => 'Miguel Dela Rosa',
            'phone' => '09171234567',
            'region' => 'CALABARZON',
            'province' => 'Cavite',
            'city' => 'General Trias City',
            'barangay' => 'Buenavista II',
            'postal_code' => '4107',
            'address_line' => '126 Ilang-ilang Street',
            'latitude' => 14.6000,
            'longitude' => 120.9800,
            'delivery_instructions' => 'Blue gate',
        ], $overrides));
    }

    private function submit(User $customer, ShopOwner $shop, RepairService $service, array $logistics)
    {
        return $this->actingAs($customer, 'user')->post('/api/repair-requests', array_merge([
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'shop_owner_id' => $shop->id,
            'services' => [$service->id],
            'images' => [UploadedFile::fake()->create('shoe.jpg', 100, 'image/jpeg')],
            'total' => 500,
        ], $logistics), ['Accept' => 'application/json']);
    }
}
