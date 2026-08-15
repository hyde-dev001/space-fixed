<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class OrderFulfillmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_owner_generic_status_endpoint_cannot_jump_pending_to_shipped(): void
    {
        $shop = $this->shopOwner();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'shipped',
            ])
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    #[Test]
    public function staff_generic_status_endpoint_cannot_jump_pending_to_shipped(): void
    {
        $shop = $this->shopOwner(['registration_type' => 'company']);
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff->givePermissionTo('access-staff-job-orders');
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending',
        ]);

        $this->actingAs($staff, 'user')
            ->patchJson("/api/staff/orders/{$order->id}/status", [
                'status' => 'shipped',
            ])
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    #[Test]
    public function direct_completion_requires_pickup_or_direct_fulfillment_evidence(): void
    {
        $shop = $this->shopOwner();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
            'pickup_enabled' => false,
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'completed',
            ])
            ->assertStatus(422);

        $this->assertSame('processing', $order->fresh()->status->value);
    }

    #[Test]
    public function valid_direct_completion_is_allowed_only_with_pickup_evidence(): void
    {
        $shop = $this->shopOwner();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
            'pickup_enabled' => true,
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'completed',
            ])
            ->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
    }

    #[Test]
    public function marking_an_order_shipped_creates_only_one_outbound_shipment(): void
    {
        $shop = $this->shopOwner();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'shipped',
            ])
            ->assertOk();

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'shipped',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
    }

    #[Test]
    public function customer_delivery_confirmation_still_requires_shipped_and_marks_cod_paid(): void
    {
        $shop = $this->shopOwner();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => 'shipped',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($customer, 'user')
            ->postJson('/orders/confirm-delivery', ['order_id' => $order->id])
            ->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status->value);
        $this->assertSame('paid', (string) $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    #[Test]
    public function terminal_outcome_correction_requires_reason_and_preserves_other_evidence(): void
    {
        $shop = $this->shopOwner();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'paymongo_refund_id' => 're_existing',
            'tracking_number' => 'TRACK-123',
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $shop->id,
            'status' => 'succeeded',
            'return_status' => 'received',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/orders/{$order->id}/correct-terminal-outcome", [
                'target' => 'completed',
                'reason' => '   ',
            ])
            ->assertStatus(422);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/orders/{$order->id}/correct-terminal-outcome", [
                'target' => 'completed',
                'reason' => 'Verified pickup record was entered under the wrong terminal outcome.',
            ])
            ->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
        $this->assertSame('paid', (string) $order->fresh()->payment_status);
        $this->assertSame('re_existing', $order->fresh()->paymongo_refund_id);
        $this->assertSame('TRACK-123', $order->fresh()->tracking_number);
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame('received', $refund->fresh()->return_status);

        $audit = AuditLog::query()
            ->where('action', 'order_terminal_outcome_corrected')
            ->where('target_id', $order->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($shop->id, $audit->actor_user_id);
        $this->assertSame('delivered', $audit->metadata['previous_status']);
        $this->assertSame('completed', $audit->metadata['new_status']);
        $this->assertSame('Verified pickup record was entered under the wrong terminal outcome.', $audit->metadata['reason']);
    }

    #[Test]
    public function terminal_outcome_correction_is_tenant_scoped(): void
    {
        $actorShop = $this->shopOwner();
        $otherShop = $this->shopOwner();
        $order = Order::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'status' => 'delivered',
        ]);

        $this->actingAs($actorShop, 'shop_owner')
            ->postJson("/api/shop-owner/orders/{$order->id}/correct-terminal-outcome", [
                'target' => 'completed',
                'reason' => 'Cross-tenant access must be rejected.',
            ])
            ->assertStatus(404);

        $this->assertSame('delivered', $order->fresh()->status->value);
    }

    private function shopOwner(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ], $overrides));
    }
}
