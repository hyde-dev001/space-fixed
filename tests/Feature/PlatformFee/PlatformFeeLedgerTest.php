<?php

namespace Tests\Feature\PlatformFee;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformFeeLedgerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_completed_marketplace_order_creates_one_fee_charge_without_shipping(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1800,
            'shipping_fee' => 200,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $order->update(['status' => 'delivered']);
        $order->update(['status' => 'completed']);

        $this->assertDatabaseCount('platform_fee_charges', 1);
        $this->assertDatabaseHas('platform_fee_charges', [
            'shop_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'source_origin' => 'marketplace',
            'fee_base' => '1800.00',
            'fee_rate' => '5.000000',
            'platform_fee_amount' => '90.00',
            'vat_amount' => '10.80',
            'total_charge' => '100.80',
        ]);
    }

    #[Test]
    public function a_paid_company_order_delivered_by_shop_owned_logistics_creates_a_fee_charge(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1800,
            'status' => 'shipped',
            'payment_status' => 'paid',
            'delivery_method' => 'shop_owned',
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'shop_owner_id' => $shop->id,
            'status' => 'in_transit',
            'requires_delivery_proof' => false,
        ]);

        $service = app(ShipmentLegService::class);
        $service->markDelivered($leg);
        $service->markDelivered($leg->fresh());

        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertDatabaseCount('platform_fee_charges', 1);
        $this->assertDatabaseHas('platform_fee_charges', [
            'shop_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'source_origin' => 'marketplace',
            'total_charge' => '100.80',
        ]);
    }

    #[Test]
    public function a_paid_third_party_order_delivered_by_logistics_creates_a_fee_charge(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'total_amount' => 1800,
            'status' => 'shipped',
            'payment_status' => 'paid',
            'delivery_method' => 'third_party',
            'carrier_company' => 'External Courier',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'shop_owner_id' => $shop->id,
            'status' => 'in_transit',
            'requires_delivery_proof' => false,
        ]);

        $service = app(ShipmentLegService::class);
        $service->updateThirdParty($leg, $shop, 'delivered', []);
        $service->updateThirdParty($leg->fresh(), $shop, 'delivered', []);

        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertDatabaseCount('platform_fee_charges', 1);
        $this->assertDatabaseHas('platform_fee_charges', [
            'shop_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'source_origin' => 'marketplace',
            'total_charge' => '100.80',
        ]);
    }

    #[Test]
    public function a_pos_order_never_creates_a_platform_fee_charge(): void
    {
        $shop = ShopOwner::factory()->approved()->create();

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'pos',
            'status' => 'pending',
        ]);

        $order->update(['status' => 'delivered']);

        $this->assertDatabaseCount('platform_fee_charges', 0);
    }

    #[Test]
    public function a_completed_marketplace_repair_creates_one_fee_charge(): void
    {
        $shop = ShopOwner::factory()->approved()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'final_total' => 1000,
            'status' => 'pending',
            'payment_status' => 'completed',
            'total_paid_amount' => 1000,
            'payment_policy' => 'full_upfront',
        ]);

        $repair->update([
            'status' => 'ready-for-pickup',
            'completed_at' => now(),
        ]);

        $this->assertDatabaseCount('platform_fee_charges', 1);
        $this->assertDatabaseHas('platform_fee_charges', [
            'shop_id' => $shop->id,
            'source_type' => 'repair',
            'source_id' => $repair->id,
            'fee_base' => '1000.00',
            'total_charge' => '56.00',
        ]);
    }

    #[Test]
    public function an_effective_date_does_not_back_charge_older_marketplace_sales(): void
    {
        config()->set('platform_fee.effective_from', now()->addDay()->toDateString());
        $shop = ShopOwner::factory()->approved()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'origin_channel' => 'marketplace',
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $order->update(['status' => 'completed']);

        $this->assertDatabaseCount('platform_fee_charges', 0);
    }
}
