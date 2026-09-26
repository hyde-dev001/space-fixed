<?php

namespace Tests\Feature\Cod;

use App\Models\CodCollection;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoicePayment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\CodCollectionService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CodRemittanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('operate-logistics-deliveries', 'user');
        Permission::findOrCreate('access-cod-remittances', 'user');
    }

    public function test_rider_can_submit_owned_collections_once_and_replay_the_request(): void
    {
        [$shop, $rider, $finance, $collection] = $this->makeContext();
        $payload = [
            'collection_ids' => [$collection->id],
            'idempotency_key' => 'cod-remittance-submit-1',
        ];

        $first = $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/cod/remittances', $payload);

        $first->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('remittance.status', 'submitted')
            ->assertJsonPath('remittance.expected_amount', '100.00')
            ->assertJsonPath('remittance.submitted_amount', '100.00');

        $second = $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/cod/remittances', $payload);

        $second->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('cod_remittances', 1);
        $this->assertDatabaseCount('cod_remittance_items', 1);
        $this->assertDatabaseCount('finance_invoice_payments', 0);
    }

    public function test_confirmed_cod_loss_creates_a_refund_claim_and_blocks_rider_remittance(): void
    {
        [$shop, $rider, , $collection] = $this->makeContext();
        $order = $collection->order()->firstOrFail();
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        $collection->update([
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
        ]);

        app(ShipmentLegService::class)->confirmLoss($leg, 'Investigation confirmed the COD parcel was lost.');

        $claim = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('reason_code', 'delivery_loss_confirmed')
            ->firstOrFail();

        $this->assertSame('requested', $claim->status);
        $this->assertSame('pending', $claim->finance_status);
        $this->assertSame('pending', $claim->shop_owner_status);
        $this->assertSame('not_required', $claim->return_status);
        $this->assertSame('xendit', $claim->payment_gateway);
        $this->assertDatabaseHas('cod_collections', [
            'id' => $collection->id,
            'status' => CodCollection::STATUS_REFUND_PENDING,
        ]);
        $projection = app(CodCollectionService::class)->riderCollections($rider);
        $this->assertSame('100.00', $projection['summary']['cash_currently_held']);
        $this->assertSame(0, $projection['summary']['pending_remittance_count']);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/cod/remittances', [
                'collection_ids' => [$collection->id],
                'idempotency_key' => 'cod-remittance-loss-'.$collection->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only your exact, cash-collected COD records can be remitted.');

        $this->assertDatabaseCount('cod_remittances', 0);
        $this->assertDatabaseCount('cod_remittance_items', 0);
    }

    public function test_finance_mismatch_disputes_without_creating_ledger_history(): void
    {
        [$shop, $rider, $finance, $collection] = $this->makeContext();
        $remittance = $this->submit($rider, $collection);

        $response = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/cod-remittances/{$remittance->id}/confirm", [
                'received_amount' => '90.00',
                'dispute_reason' => 'Short by PHP 10',
            ]);

        $response->assertOk()
            ->assertJsonPath('remittance.status', 'disputed')
            ->assertJsonPath('remittance.variance_amount', '-10.00');
        $this->assertDatabaseCount('finance_invoice_payments', 0);
        $this->assertDatabaseHas('cod_collections', [
            'id' => $collection->id,
            'status' => CodCollection::STATUS_CASH_COLLECTED,
        ]);
    }

    public function test_finance_exact_confirmation_settles_collection_and_appends_one_payment(): void
    {
        [$shop, $rider, $finance, $collection] = $this->makeContext();
        $remittance = $this->submit($rider, $collection);

        $response = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/cod-remittances/{$remittance->id}/confirm", [
                'received_amount' => '100.00',
            ]);

        $response->assertOk()
            ->assertJsonPath('remittance.status', 'settled')
            ->assertJsonPath('remittance.variance_amount', '0.00');
        $this->assertDatabaseHas('cod_collections', [
            'id' => $collection->id,
            'status' => CodCollection::STATUS_SETTLED,
        ]);
        $this->assertDatabaseHas('finance_invoice_payments', [
            'invoice_id' => $collection->order->invoice_id,
            'amount' => '100.00',
            'payment_method' => 'cash',
            'source' => InvoicePayment::SOURCE_COD_REMITTANCE,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $collection->order_id,
            'payment_status' => 'paid',
        ]);

        $replay = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/cod-remittances/{$remittance->id}/confirm", [
                'received_amount' => '100.00',
            ]);

        $replay->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('finance_invoice_payments', 1);
    }

    public function test_rider_cannot_confirm_and_finance_list_is_shop_scoped(): void
    {
        [$shop, $rider, $finance, $collection] = $this->makeContext();
        $remittance = $this->submit($rider, $collection);

        $this->actingAs($rider, 'user')
            ->postJson("/api/finance/cod-remittances/{$remittance->id}/confirm", [
                'received_amount' => '100.00',
            ])
            ->assertForbidden();

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/cod-remittances')
            ->assertOk()
            ->assertJsonPath('data.0.id', $remittance->id);

        $otherShop = ShopOwner::factory()->create();
        $otherFinance = User::factory()->create(['shop_owner_id' => $otherShop->id]);
        $otherFinance->givePermissionTo('access-cod-remittances');

        $this->actingAs($otherFinance, 'user')
            ->getJson('/api/finance/cod-remittances')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function submit(User $rider, CodCollection $collection): CodCollection
    {
        $response = $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/cod/remittances', [
                'collection_ids' => [$collection->id],
                'idempotency_key' => 'cod-remittance-confirm-'.$collection->id,
            ])
            ->assertCreated();

        return $collection->fresh(['order'])->setRelation(
            'remittance',
            \App\Models\CodRemittance::findOrFail($response->json('remittance.id')),
        );
    }

    private function makeContext(): array
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('operate-logistics-deliveries');
        $this->clockInEmployee($rider);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-cod-remittances');
        $this->clockInEmployee($finance);

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'total_amount' => '100.00',
            'shipping_fee' => '0.00',
            'vat_amount' => '0.00',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
        $invoice = Invoice::create([
            'shop_id' => $shop->id,
            'reference' => 'INV-COD-'.$order->id,
            'customer_name' => $order->customer_name,
            'date' => now()->toDateString(),
            'total' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'sent',
            'payment_method' => 'cod',
            'job_order_id' => $order->id,
        ]);
        $order->update([
            'invoice_id' => $invoice->id,
            'invoice_generated' => true,
        ]);
        $collection = CodCollection::create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'rider_profile_id' => $profile->id,
            'rider_user_id' => $rider->id,
            'expected_amount' => '100.00',
            'collected_amount' => '100.00',
            'status' => CodCollection::STATUS_CASH_COLLECTED,
            'collection_reference' => 'COD-COLLECTION-'.$order->order_number,
            'collection_idempotency_key' => 'cod-collection-'.$order->id,
            'collected_at' => now(),
            'collected_by_user_id' => $rider->id,
        ]);

        return [$shop, $rider, $finance, $collection];
    }
}
