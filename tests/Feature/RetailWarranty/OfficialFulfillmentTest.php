<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\CodCollection;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\ProofReviewService;
use App\Services\OrderReceiptService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\RetailWarrantyService;

class OfficialFulfillmentTest extends WarrantyTestCase
{
    public function test_early_shop_owned_receipt_is_not_fulfillment_but_approved_final_proof_issues_once(): void
    {
        $order = $this->purchase();
        $order->forceFill(['carrier_company' => 'Shop-owned logistics', 'delivery_method' => 'shop_owned'])->save();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order',
            'source_id' => $order->id, 'purpose' => 'retail_delivery', 'status' => 'active']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'awaiting_proof_approval', 'requires_delivery_proof' => true]);
        app(OrderReceiptService::class)->confirm($order);
        $this->assertDatabaseCount('retail_warranty_issuances', 0);
        $this->assertNull($order->fresh()->retail_warranty_fulfilled_at);
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery']);
        app(ProofReviewService::class)->approve($proof, $order->shopOwner);
        app(ProofReviewService::class)->approve($proof->fresh(), $order->shopOwner);
        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
        $this->assertDatabaseCount('retail_warranties', 3);
    }

    public function test_direct_pickup_completion_and_collected_cod_do_not_depend_on_remittance(): void
    {
        $order = $this->purchase('pending');
        $shop = $order->shopOwner;
        $shop->update(['registration_type' => 'individual']);
        app(OrderFulfillmentService::class)->completeDirectly($order, $shop);
        $this->assertSame(3, $order->fresh()->retailWarrantyIssuance->warranties->count());
        $cod = $this->purchase('delivered');
        $cod->update(['payment_method' => 'cod', 'payment_status' => 'pending']);
        $this->assertNull(app(RetailWarrantyService::class)->captureFulfillment($cod));
        CodCollection::create(['shop_owner_id' => $cod->shop_owner_id, 'order_id' => $cod->id, 'expected_amount' => 1000,
            'collected_amount' => 1000, 'status' => 'cash_collected', 'collected_at' => now()]);
        $this->assertNotNull(app(RetailWarrantyService::class)->issueCaptured($cod));
        $this->assertSame('pending', $cod->fresh()->payment_status);
    }
}
