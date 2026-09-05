<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\ShippingMethod;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_shipment_with_one_outbound_leg_for_order(): void
    {
        $shop = ShopOwner::factory()->create();
        $order = Order::factory()->create(['shop_owner_id' => $shop->id]);

        $shipment = app(ShipmentRequestService::class)->requestShipment([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'legs' => [[
                'leg_type' => 'outbound',
                'origin_snapshot' => ['name' => $shop->shop_name ?? $shop->business_name, 'type' => 'shop'],
                'destination_snapshot' => ['name' => $order->customer_name, 'type' => 'customer'],
            ]],
        ]);

        $this->assertSame('requested', $shipment->status->value);
        $this->assertCount(1, $shipment->legs);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_requested',
        ]);
    }

    public function test_shipment_numbers_restart_for_each_shop_owner(): void
    {
        $shopA = ShopOwner::factory()->create();
        $shopB = ShopOwner::factory()->create();
        $requestShipment = fn (ShopOwner $shop, int $sourceId) => app(ShipmentRequestService::class)->requestShipment([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $sourceId,
            'purpose' => 'retail_delivery',
            'legs' => [['leg_type' => 'outbound']],
        ]);

        $firstA = $requestShipment($shopA, 1101);
        $secondA = $requestShipment($shopA, 1102);
        $firstB = $requestShipment($shopB, 2201);

        $this->assertSame(1, $firstA->shipment_number);
        $this->assertSame(2, $secondA->shipment_number);
        $this->assertSame(1, $firstB->shipment_number);
    }

    public function test_it_accepts_an_active_internal_assignment_method(): void
    {
        $shop = ShopOwner::factory()->create();
        $method = ShippingMethod::factory()->create([
            'carrier_type' => 'internal',
            'requires_assignment' => true,
            'active' => true,
        ]);

        $shipment = app(ShipmentRequestService::class)->requestShipment([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 1001,
            'purpose' => 'retail_delivery',
            'legs' => [[
                'leg_type' => 'outbound',
                'shipping_method_id' => $method->id,
            ]],
        ]);

        $this->assertSame($method->id, $shipment->legs->first()->shipping_method_id);
    }

    #[DataProvider('unsupportedShopOwnedMethods')]
    public function test_it_rejects_methods_that_cannot_run_through_shop_owned_logistics(array $attributes): void
    {
        $shop = ShopOwner::factory()->create();
        $method = ShippingMethod::factory()->create($attributes);

        try {
            app(ShipmentRequestService::class)->requestShipment([
                'shop_owner_id' => $shop->id,
                'source_type' => 'order',
                'source_id' => 1002,
                'purpose' => 'retail_delivery',
                'legs' => [[
                    'leg_type' => 'outbound',
                    'shipping_method_id' => $method->id,
                ]],
            ]);

            $this->fail('Unsupported shipping method was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Selected shipping method is not supported by shop-owned logistics.',
                $exception->errors()['legs.0.shipping_method_id'][0] ?? $exception->errors()['legs'][0] ?? null,
            );
        }

        $this->assertDatabaseCount('shipments', 0);
    }

    public static function unsupportedShopOwnedMethods(): array
    {
        return [
            'inactive internal method' => [['carrier_type' => 'internal', 'requires_assignment' => true, 'active' => false]],
            'assignment free internal method' => [['carrier_type' => 'internal', 'requires_assignment' => false, 'active' => true]],
            'external method' => [['carrier_type' => 'external', 'requires_assignment' => false, 'active' => true]],
            'customer controlled method' => [['carrier_type' => 'customer_controlled', 'requires_assignment' => false, 'active' => true]],
        ];
    }
}
