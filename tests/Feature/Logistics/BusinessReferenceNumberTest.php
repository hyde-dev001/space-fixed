<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Services\Logistics\ProofService;
use App\Services\Logistics\ShipmentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessReferenceNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_numbers_are_scoped_to_each_shop_and_keep_order_sequence_independent(): void
    {
        $shopA = ShopOwner::factory()->create();
        $shopB = ShopOwner::factory()->create();

        $firstA = DB::transaction(function () use ($shopA): string {
            $number = Order::generateOrderNumber($shopA->id);
            Order::factory()->create([
                'shop_owner_id' => $shopA->id,
                'order_number' => $number,
            ]);

            return $number;
        });
        $secondA = DB::transaction(function () use ($shopA): string {
            $number = Order::generateOrderNumber($shopA->id);
            Order::factory()->create([
                'shop_owner_id' => $shopA->id,
                'order_number' => $number,
            ]);

            return $number;
        });
        $firstB = DB::transaction(function () use ($shopB): string {
            $number = Order::generateOrderNumber($shopB->id);
            Order::factory()->create([
                'shop_owner_id' => $shopB->id,
                'order_number' => $number,
            ]);

            return $number;
        });

        $year = now()->format('Y');
        $this->assertSame("ORD-{$year}-001", $firstA);
        $this->assertSame("ORD-{$year}-002", $secondA);
        $this->assertSame("ORD-{$year}-001", $firstB);
    }

    public function test_delivery_and_delivery_proof_numbers_are_scoped_independently_to_each_shop(): void
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

        $shipmentA = $requestShipment($shopA, 1101);
        $shipmentB = $requestShipment($shopB, 2201);
        $legA = ShipmentLeg::query()->findOrFail($shipmentA->legs->first()->id);
        $legB = ShipmentLeg::query()->findOrFail($shipmentB->legs->first()->id);
        $this->assertSame(1, $legA->delivery_number);
        $this->assertSame(1, $legB->delivery_number);
        $this->assertSame($shopA->id, $legA->shop_owner_id);
        $this->assertSame($shopB->id, $legB->shop_owner_id);
        $legA->update(['status' => 'in_transit']);
        $legB->update(['status' => 'in_transit']);

        $proofA = app(ProofService::class)->recordProof($legA, [
            'handoff_type' => 'delivery',
            'proof_type' => 'staff_confirmation',
        ]);
        $proofB = app(ProofService::class)->recordProof($legB, [
            'handoff_type' => 'delivery',
            'proof_type' => 'staff_confirmation',
        ]);

        $this->assertSame(1, $proofA->proof_number);
        $this->assertSame(1, $proofB->proof_number);
        $this->assertSame($shopA->id, $proofA->shop_owner_id);
        $this->assertSame($shopB->id, $proofB->shop_owner_id);
    }
}
