<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\DeliveryScheduleService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffRetailShippingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $staff;
    private UserAddress $address;
    private Order $order;
    private LogisticsSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'status' => 'approved',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $this->staff = User::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'role' => 'STAFF',
        ]);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $this->staff->givePermissionTo('access-staff-job-orders');

        $customer = User::factory()->create();
        $this->address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => 'Customer',
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'latitude' => 14.60,
            'longitude' => 120.98,
        ]);
        $this->order = Order::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'address_id' => $this->address->id,
            'status' => 'processing',
        ]);
        $this->settings = LogisticsSetting::create([
            'shop_owner_id' => $this->shop->id,
            'coverage_radius_km' => 20,
        ]);
    }

    public function test_list_and_show_include_exact_shop_owned_coverage(): void
    {
        $coverage = [
            'available' => true,
            'reason' => null,
            'distance_km' => 0.46,
            'coverage_radius_km' => 20,
        ];

        $list = $this->actingAs($this->staff, 'user')->getJson('/api/staff/orders')->assertOk();
        $this->assertSame($coverage, $list->json('0.shop_owned_coverage'));

        $show = $this->actingAs($this->staff, 'user')
            ->getJson("/api/staff/orders/{$this->order->id}")
            ->assertOk();
        $this->assertSame($coverage, $show->json('shop_owned_coverage'));
    }

    public function test_outside_coverage_blocks_normalized_shop_owned_shipping_before_side_effects(): void
    {
        $this->moveAddressOutsideCoverage();
        $message = 'Shop-owned logistics is unavailable for this delivery address.';
        $coverage = [
            'available' => false,
            'reason' => 'outside_coverage',
            'distance_km' => 10.65,
            'coverage_radius_km' => 1,
        ];

        $response = $this->actingAs($this->staff, 'user')
            ->patchJson("/api/staff/orders/{$this->order->id}/status", [
                'status' => 'shipped',
                'carrier_company' => '  sHoP-oWnEd LoGiStIcS  ',
                'eta' => '1-2 business days',
            ])
            ->assertUnprocessable();

        $this->assertSame([
            'success' => false,
            'message' => $message,
            'errors' => ['carrier_company' => [$message]],
            'shop_owned_coverage' => $coverage,
        ], $response->json());
        $this->assertSame('processing', $this->order->fresh()->status->value);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order',
            'source_id' => $this->order->id,
        ]);
    }

    public function test_delivered_order_cannot_be_moved_back_to_processing(): void
    {
        $this->order->update(['status' => 'delivered']);

        $this->actingAs($this->staff, 'user')
            ->patchJson("/api/staff/orders/{$this->order->id}/status", [
                'status' => 'processing',
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'The order is already Delivered and cannot be moved back to Processing.',
            );

        $this->assertSame('delivered', $this->order->fresh()->status->value);
    }

    public function test_normalized_shop_owned_carrier_is_persisted_canonically_and_auto_completes(): void
    {
        $this->actingAs($this->staff, 'user')
            ->patchJson("/api/staff/orders/{$this->order->id}/status", [
                'status' => 'shipped',
                'carrier_company' => '  sHoP-oWnEd LoGiStIcS  ',
            ])
            ->assertOk();

        $this->assertSame('Shop-owned logistics', $this->order->fresh()->carrier_company);

        $leg = Shipment::query()
            ->where('source_type', 'order')
            ->where('source_id', $this->order->id)
            ->firstOrFail()
            ->legs()
            ->firstOrFail();
        $leg->update(['status' => 'in_transit', 'requires_delivery_proof' => false]);

        app(ShipmentLegService::class)->markDelivered($leg->fresh());

        $this->assertSame('completed', $this->order->fresh()->status->value);
    }

    public function test_outside_coverage_does_not_block_complete_third_party_shipping(): void
    {
        $this->moveAddressOutsideCoverage();

        $this->actingAs($this->staff, 'user')
            ->patchJson("/api/staff/orders/{$this->order->id}/status", [
                'status' => 'shipped',
                'carrier_company' => 'J&T Express',
                'tracking_number' => 'JT-123456',
                'carrier_name' => 'Juan Rider',
                'carrier_phone' => '09170000000',
                'tracking_link' => 'https://example.test/track/JT-123456',
                'eta' => '2026-07-21',
            ])
            ->assertOk();

        $this->assertSame('shipped', $this->order->fresh()->status->value);
        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order',
            'source_id' => $this->order->id,
            'purpose' => 'retail_delivery',
        ]);
    }

    public function test_shipping_rechecks_coverage_after_radius_changes(): void
    {
        $this->address->update(['latitude' => 14.6760, 'longitude' => 121.0437]);

        $this->actingAs($this->staff, 'user')
            ->getJson('/api/staff/orders')
            ->assertOk()
            ->assertJsonPath('0.shop_owned_coverage.available', true)
            ->assertJsonPath('0.shop_owned_coverage.coverage_radius_km', 20);

        $this->settings->update(['coverage_radius_km' => 1]);

        $this->actingAs($this->staff, 'user')
            ->patchJson("/api/staff/orders/{$this->order->id}/status", [
                'status' => 'shipped',
                'carrier_company' => 'Shop-owned logistics',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('shop_owned_coverage.reason', 'outside_coverage')
            ->assertJsonPath('shop_owned_coverage.coverage_radius_km', 1);

        $this->assertSame('processing', $this->order->fresh()->status->value);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order',
            'source_id' => $this->order->id,
        ]);
    }

    public function test_missing_customer_and_shop_pins_are_exposed(): void
    {
        $this->address->update(['latitude' => null, 'longitude' => null]);

        $this->actingAs($this->staff, 'user')
            ->getJson('/api/staff/orders')
            ->assertOk()
            ->assertJsonPath('0.shop_owned_coverage', [
                'available' => false,
                'reason' => 'address_needs_pin',
                'distance_km' => null,
                'coverage_radius_km' => 20,
            ]);

        $this->address->update(['latitude' => 14.60, 'longitude' => 120.98]);
        $this->shop->update(['shop_latitude' => null, 'shop_longitude' => null]);

        $this->actingAs($this->staff, 'user')
            ->getJson("/api/staff/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonPath('shop_owned_coverage', [
                'available' => false,
                'reason' => 'shop_needs_pin',
                'distance_km' => null,
                'coverage_radius_km' => 20,
            ]);
    }

    public function test_coverage_failure_is_exposed_as_fail_closed(): void
    {
        Log::spy();
        $this->mock(DeliveryScheduleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('coverage')->once()->andThrow(new \RuntimeException('unavailable'));
        });

        $this->actingAs($this->staff, 'user')
            ->getJson('/api/staff/orders')
            ->assertOk()
            ->assertJsonPath('0.shop_owned_coverage', [
                'available' => false,
                'reason' => 'logistics_unavailable',
                'distance_km' => null,
                'coverage_radius_km' => null,
            ]);

        Log::shouldHaveReceived('warning')->once();
    }

    private function moveAddressOutsideCoverage(): void
    {
        $this->address->update(['latitude' => 14.6760, 'longitude' => 121.0437]);
        $this->settings->update(['coverage_radius_km' => 1]);
    }
}
