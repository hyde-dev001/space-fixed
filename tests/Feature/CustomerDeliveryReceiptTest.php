<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DeliveryDispute;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerDeliveryReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owned_customer_can_confirm_receipt_while_proof_awaits_dispatcher_approval(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::SHIPPED,
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'status' => 'active',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'awaiting_proof_approval',
            'requires_delivery_proof' => true,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/confirm-delivery', ['order_id' => $order->id]);

        $response->assertOk()->assertJson([
            'success' => true,
            'receipt_status' => 'confirmed',
            'order_status' => 'shipped',
        ]);

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->customer_receipt_status);
        $this->assertNotNull($order->fresh()->customer_received_at);
    }

    public function test_shop_owned_receipt_confirmation_does_not_change_already_delivered_order(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::DELIVERED,
            'carrier_company' => 'Shop-owned logistics',
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/confirm-delivery', ['order_id' => $order->id]);

        $response->assertOk()->assertJson([
            'success' => true,
            'receipt_status' => 'confirmed',
            'order_status' => 'delivered',
        ]);

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->customer_receipt_status);
    }

    public function test_third_party_customer_confirmation_keeps_legacy_delivery_transition(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::SHIPPED,
            'carrier_company' => 'Third-party Logistics',
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson('/orders/confirm-delivery', ['order_id' => $order->id]);

        $response->assertOk()->assertJson([
            'success' => true,
            'receipt_status' => 'confirmed',
            'order_status' => 'delivered',
        ]);

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->customer_receipt_status);
    }

    public function test_customer_can_report_after_receipt_confirmation_without_rolling_back_delivery(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::DELIVERED,
            'carrier_company' => 'Shop-owned logistics',
            'customer_receipt_status' => 'confirmed',
            'customer_received_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($customer, 'user')
            ->postJson("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'damaged',
                'notes' => 'The sole is damaged.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dispute.status', 'open');

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame('disputed', $order->fresh()->customer_receipt_status);
        $this->assertDatabaseHas('delivery_disputes', [
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'open',
            'reason' => 'damaged',
        ]);
    }

    public function test_duplicate_customer_report_returns_the_existing_active_dispute(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::DELIVERED,
            'customer_receipt_status' => 'pending',
        ]);

        $first = $this->actingAs($customer, 'user')->postJson("/orders/{$order->id}/delivery-disputes", [
            'reason' => 'item_not_received',
        ])->assertOk()->json('dispute.id');
        $second = $this->actingAs($customer, 'user')->postJson("/orders/{$order->id}/delivery-disputes", [
            'reason' => 'other',
        ])->assertOk()->json('dispute.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('delivery_disputes', 1);
    }

    public function test_dispatcher_can_resolve_a_dispute_to_refund_without_changing_delivered_status(): void
    {
        Permission::findOrCreate('view-logistics-shipments', 'user');
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(['view-logistics-shipments', 'resolve-logistics-exceptions']);
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::DELIVERED,
            'carrier_company' => 'Shop-owned logistics',
            'payment_status' => 'paid',
            'payment_method' => 'paymongo',
            'total_amount' => 1000,
        ]);
        $dispute = DeliveryDispute::query()->create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'open',
            'reason' => 'damaged',
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/investigate")
            ->assertOk()
            ->assertJsonPath('dispute.status', 'investigating');

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/resolve", [
                'resolution' => 'refund_required',
                'resolution_note' => 'Refund workflow is required after investigation.',
            ])
            ->assertOk()
            ->assertJsonPath('result', 'resolved')
            ->assertJsonPath('dispute.resolution', 'refund_required');

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame('disputed', $order->fresh()->customer_receipt_status);
        $this->assertDatabaseHas('delivery_disputes', [
            'id' => $dispute->id,
            'status' => 'resolved',
            'resolution' => 'refund_required',
        ]);
        $this->assertDatabaseHas('order_refunds', [
            'order_id' => $order->id,
            'reason_code' => 'delivery_dispute',
            'return_status' => 'awaiting_approval',
        ]);
    }
}
