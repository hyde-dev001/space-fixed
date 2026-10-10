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
            'status' => 'ready_for_pickup',
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

    private function marketplaceRepair(array $overrides = []): RepairRequest
    {
        return RepairRequest::factory()->create(array_merge([
            'shop_owner_id' => ShopOwner::factory()->approved()->create()->id,
            'origin_channel' => 'marketplace', 'billing_mode' => 'standard',
            'total' => 1000, 'final_total' => 1000, 'status' => 'pending',
            'payment_status' => 'completed', 'total_paid_amount' => 1000,
            'payment_policy' => 'full_upfront', 'created_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function all_legitimate_terminal_aliases_finalize_once(): void
    {
        foreach (['completed', 'ready-for-pickup', 'ready_for_pickup', 'picked_up', 'shipped'] as $status) {
            $repair = $this->marketplaceRepair();
            $repair->update(['status' => $status === 'ready-for-pickup' ? 'ready_for_pickup' : $status]);
            $source = $repair->fresh();
            // Legacy read models may carry the alias; the current enum stores its canonical value.
            $source->setRawAttributes(array_replace($source->getAttributes(), ['status' => $status]));
            $this->assertNotNull(app(\App\Services\PlatformFeeLedgerService::class)->finalizeRepair($source));
            $this->assertDatabaseHas('platform_fee_charges', [
                'source_type' => 'repair', 'source_id' => $repair->id, 'fee_base' => '1000.00', 'total_charge' => '56.00',
            ]);
        }
        $this->assertDatabaseCount('platform_fee_charges', 5);
    }

    #[Test]
    public function legacy_status_writes_persist_canonical_values_and_keep_fee_eligibility(): void
    {
        foreach (['ready-for-pickup' => 'ready_for_pickup', 'in-progress' => 'in_progress'] as $input => $stored) {
            $repair = $this->marketplaceRepair();
            $repair->update(['status' => $input]);
            $this->assertSame($stored, $repair->fresh()->status);
        }
        $this->assertDatabaseCount('platform_fee_charges', 1);
    }

    #[Test]
    public function ready_then_final_payment_then_receipt_creates_one_charge(): void
    {
        $repair = $this->marketplaceRepair(['payment_policy' => 'deposit_50', 'payment_status' => 'paid', 'total_paid_amount' => 500]);
        $repair->update(['status' => 'ready_for_pickup']);
        $this->assertDatabaseCount('platform_fee_charges', 0);
        $repair->update(['payment_status' => 'completed', 'total_paid_amount' => 1000]);
        $repair->update(['status' => 'picked_up']);
        $this->assertDatabaseCount('platform_fee_charges', 1);
    }

    #[Test]
    public function already_received_repair_can_finalize_on_late_payment(): void
    {
        $repair = $this->marketplaceRepair(['status' => 'picked_up', 'payment_status' => 'pending', 'total_paid_amount' => 0]);
        $this->assertDatabaseCount('platform_fee_charges', 0);
        $repair->update(['payment_status' => 'completed', 'total_paid_amount' => 1000]);
        $this->assertDatabaseCount('platform_fee_charges', 1);
    }

    #[Test]
    public function eligibility_changes_in_final_total_origin_and_billing_trigger_finalization(): void
    {
        foreach ([
            ['initial' => ['final_total' => 1200, 'total' => 1200], 'update' => ['final_total' => 1000]],
            ['initial' => ['origin_channel' => 'pos'], 'update' => ['origin_channel' => 'marketplace']],
            ['initial' => ['billing_mode' => 'warranty_no_charge'], 'update' => ['billing_mode' => 'standard']],
        ] as $case) {
            $repair = $this->marketplaceRepair(['status' => 'completed', ...$case['initial']]);
            $repair->update($case['update']);
            $this->assertDatabaseHas('platform_fee_charges', ['source_type' => 'repair', 'source_id' => $repair->id]);
        }
        $this->assertDatabaseCount('platform_fee_charges', 3);
    }

    #[Test]
    public function verified_service_ledgers_cover_stale_raw_amount_without_delivery_in_fee_base(): void
    {
        $repair = $this->marketplaceRepair(['total_paid_amount' => 0]);
        \App\Models\RepairPaymentSession::create([
            'repair_request_id' => $repair->id, 'provider' => 'paymongo', 'provider_link_id' => 'cs_fee_initial',
            'phase' => 'initial', 'status' => 'paid', 'service_amount' => 500, 'delivery_amount' => 200,
        ]);
        \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-FEE-BALANCE', 'idempotency_key' => 'fee-balance', 'shop_owner_id' => $repair->shop_owner_id,
            'module_type' => 'repair', 'module_reference_id' => $repair->id, 'customer_type' => 'registered',
            'due_type' => 'balance', 'subtotal' => 500, 'total_amount' => 650, 'paid_amount' => 650,
            'status' => 'paid', 'metadata' => ['service_amount' => 500, 'delivery_amount' => 150],
        ]);
        $repair->update(['status' => 'completed']);
        $this->assertDatabaseHas('platform_fee_charges', ['source_type' => 'repair', 'source_id' => $repair->id, 'fee_base' => '1000.00']);
        app(\App\Services\PlatformFeeLedgerService::class)->finalizeRepair($repair->fresh());
        $this->assertDatabaseCount('platform_fee_charges', 1);
        $this->assertEquals(0, $repair->fresh()->total_paid_amount);
    }

    #[Test]
    public function foreign_tenant_pos_evidence_cannot_pay_a_marketplace_repair(): void
    {
        $repair = $this->marketplaceRepair(['total_paid_amount' => 0]);
        \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-FOREIGN-FEE', 'idempotency_key' => 'foreign-fee',
            'shop_owner_id' => ShopOwner::factory()->approved()->create()->id,
            'module_type' => 'repair', 'module_reference_id' => $repair->id, 'customer_type' => 'registered',
            'due_type' => 'full', 'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 1000,
            'status' => 'paid', 'metadata' => ['service_amount' => 1000],
        ]);
        $repair->update(['status' => 'completed']);
        $this->assertDatabaseCount('platform_fee_charges', 0);
    }

    #[Test]
    public function delivery_collection_cannot_cover_an_unpaid_service_balance(): void
    {
        $repair = $this->marketplaceRepair([
            'total_paid_amount' => 1000, 'intake_delivery_fee' => 500, 'intake_logistics_locked_at' => now(),
        ]);
        $repair->update(['status' => 'completed']);
        $this->assertDatabaseCount('platform_fee_charges', 0);
    }

    #[Test]
    public function unfinished_unpaid_pos_warranty_refunded_and_reconciling_repairs_remain_excluded(): void
    {
        foreach ([
            ['status' => 'in_progress'], ['status' => 'cancelled'], ['status' => 'rejected'],
            ['status' => 'completed', 'payment_status' => 'pending'],
            ['status' => 'completed', 'total_paid_amount' => 500],
            ['status' => 'completed', 'total_paid_amount' => 0],
            ['status' => 'completed', 'origin_channel' => 'pos'],
            ['status' => 'completed', 'origin_channel' => null, 'pricing_breakdown' => ['mode' => 'manual_pos']],
            ['status' => 'completed', 'billing_mode' => 'warranty_no_charge'],
            ['status' => 'completed', 'is_warranty_job' => true],
            ['status' => 'completed', 'payment_status' => 'refunded'],
            ['status' => 'completed', 'logistics_payment_reconciliation' => ['status' => 'pending']],
        ] as $overrides) {
            $repair = $this->marketplaceRepair($overrides);
            $this->assertNull(app(\App\Services\PlatformFeeLedgerService::class)->finalizeRepair($repair->fresh()), json_encode($overrides));
        }
        $this->assertDatabaseCount('platform_fee_charges', 0);
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
