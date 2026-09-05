<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PhaseOneStateCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shipped_order_confirmation_reaches_delivered_and_keeps_cod_payment_side_effects(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => 'shipped',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($customer, 'user')
            ->postJson('/orders/confirm-delivery', ['order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();

        $this->assertSame('delivered', $order->status->value);
        $this->assertSame('paid', (string) $order->payment_status);
        $this->assertNotNull($order->paid_at);
    }

    #[Test]
    public function direct_or_pickup_shop_owner_completion_reaches_completed(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
            'pickup_enabled' => true,
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertSame('completed', $order->fresh()->status->value);
    }

    #[Test]
    public function retail_refund_accepts_approved_and_succeeded_terminal_outcomes(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'both']);
        $cashier = User::factory()->create(['shop_owner_id' => $shop->id]);
        $product = \App\Models\Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Characterization Shoe',
            'slug' => 'characterization-shoe-' . random_int(1000, 9999),
            'price' => 800,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $checkout = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'idempotency_key' => 'phase-one-refund-check',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk In Buyer',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 800,
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 800]],
        ])->assertCreated();

        $transactionId = (int) $checkout->json('data.id');
        $refund = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/refunds', [
            'source_transaction_id' => $transactionId,
            'request_type' => 'full',
            'requested_amount' => 800,
            'reason_code' => 'customer_return',
            'reason_notes' => 'Characterization return.',
        ])->assertOk()->json('refund_id');

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refund}/approve", [
                'approved_amount' => 800,
                'approval_note' => 'Approved for characterization.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refund}/execute", [
                'execution_mode' => 'manual',
                'execution_note' => 'Returned at counter.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertSame('succeeded', (string) PosRefund::query()->findOrFail($refund)->status);
        $this->assertSame('refunded', (string) PosTransaction::query()->findOrFail($transactionId)->status);
    }

    #[Test]
    public function full_purchase_order_receipt_posts_inventory_and_reaches_delivered_not_completed(): void
    {
        [$owner, $receiver, $purchaseOrder, $item, $inventory] = $this->purchaseOrderFixture();

        $this->actingAs($receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$purchaseOrder->id}/receipts", [
                'idempotency_key' => 'phase-one-receipt',
                'items' => [[
                    'purchase_order_item_id' => $item->id,
                    'received_quantity' => 2,
                    'defective_quantity' => 0,
                ]],
            ])
            ->assertCreated();

        $this->assertSame('delivered', $purchaseOrder->fresh()->status);
        $this->assertSame(12, (int) $inventory->fresh()->available_quantity);
        $this->assertDatabaseCount('purchase_order_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    #[Test]
    public function explicit_purchase_order_closure_reaches_completed(): void
    {
        [$owner, $receiver, $purchaseOrder, $item] = $this->purchaseOrderFixture([
            'status' => 'delivered',
        ]);
        $item->update(['ordered_quantity' => 0]);
        Permission::findOrCreate('procurement.complete_purchase_orders', 'user');
        $receiver->givePermissionTo('procurement.complete_purchase_orders');

        $this->actingAs($receiver, 'user')
            ->postJson("/api/erp/procurement/purchase-orders/{$purchaseOrder->id}/update-status", [
                'status' => 'completed',
            ])
            ->assertOk();

        $this->assertSame('completed', $purchaseOrder->fresh()->status);
    }

    #[Test]
    public function assigned_rider_can_submit_current_custody_proof(): void
    {
        [$shop, $order, $leg, $rider] = $this->riderFixture();

        Storage::fake('local');
        $proof = $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->proofFile(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('proof');

        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->assertNotNull($proof['id'] ?? null);
        $this->assertSame('shipped', $order->fresh()->status->value);
    }

    #[Test]
    public function unassigned_rider_cannot_submit_current_custody_proof(): void
    {
        [$shop, $order, $leg, $rider] = $this->riderFixture(withAssignment: false);

        Storage::fake('local');
        $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/proof", [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'proof_file' => $this->proofFile(),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame('in_transit', $leg->fresh()->status->value);
        $this->assertSame(0, $leg->proofs()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function customer_credentials_keep_the_user_guard_and_customer_destination(): void
    {
        $customer = User::factory()->create([
            'email' => 'phase-one-customer@example.test',
            'password' => Hash::make('Password1!'),
        ]);

        $this->post('/user/login', [
            'email' => $customer->email,
            'password' => 'Password1!',
        ])->assertRedirect(route('landing'));

        $this->assertAuthenticatedAs($customer, 'user');
        $this->assertGuest('shop_owner');
    }

    #[Test]
    public function staff_credentials_keep_the_user_guard_and_staff_destination(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
            'email' => 'phase-one-staff@example.test',
            'password' => Hash::make('Password1!'),
        ]);
        Employee::factory()->create([
            'shop_owner_id' => $shop->id,
            'email' => $staff->email,
            'status' => 'active',
        ]);

        $this->post('/user/login', [
            'email' => $staff->email,
            'password' => 'Password1!',
        ])->assertRedirect(route('erp.time-in'));

        $this->assertAuthenticatedAs($staff, 'user');
        $this->assertGuest('shop_owner');
    }

    #[Test]
    public function shop_owner_credentials_reach_the_existing_two_factor_challenge(): void
    {
        Mail::fake();
        $shop = ShopOwner::factory()->approved()->create([
            'email' => 'phase-one-owner@example.test',
            'password' => Hash::make('Password1!'),
            'two_factor_email_enabled' => true,
        ]);

        $this->post('/shop-owner/login', [
            'email' => $shop->email,
            'password' => 'Password1!',
        ])
            ->assertRedirect(route('shop-owner.two-factor.challenge'))
            ->assertSessionHas('shop_owner_2fa_pending_id', $shop->id);

        $this->assertGuest('shop_owner');
    }

    #[Test]
    public function shop_owner_two_factor_verification_establishes_the_shop_owner_guard(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'password' => Hash::make('Password1!'),
            'two_factor_email_enabled' => true,
        ]);

        $this->withSession([
            'shop_owner_2fa_pending_id' => $shop->id,
            'shop_owner_2fa_remember' => false,
            'shop_owner_2fa_pending_at' => now()->timestamp,
            'shop_owner_2fa_entry' => [
                'shop_owner_id' => $shop->id,
                'otp_hash' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ])->post('/shop-owner/two-factor/verify', ['otp' => '123456'])
            ->assertRedirect(route('shop-owner.dashboard'));

        $this->assertAuthenticatedAs($shop, 'shop_owner');
        $this->assertGuest('user');
    }

    /** @return array{ShopOwner, User, PurchaseOrder, PurchaseOrderItem, InventoryItem} */
    private function purchaseOrderFixture(array $purchaseOrderOverrides = []): array
    {
        $owner = ShopOwner::factory()->approved()->create();
        $receiver = User::factory()->for($owner)->create();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $owner->id]);
        $inventory = InventoryItem::factory()->create([
            'shop_owner_id' => $owner->id,
            'available_quantity' => 10,
        ]);
        $purchaseOrder = PurchaseOrder::factory()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'supplier_id' => $supplier->id,
            'ordered_by' => $receiver->id,
            'inventory_item_id' => $inventory->id,
            'quantity' => 2,
            'unit_cost' => 100,
            'total_cost' => 200,
            'status' => 'in_transit',
        ], $purchaseOrderOverrides));
        $item = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => 2,
            'unit_cost' => 100,
            'line_total' => 200,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => [],
        ]);

        foreach (['procurement.receive_purchase_orders', 'view-inventory'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $receiver->givePermissionTo(['procurement.receive_purchase_orders', 'view-inventory']);

        return [$owner, $receiver, $purchaseOrder, $item, $inventory];
    }

    /** @return array{ShopOwner, Order, ShipmentLeg, User} */
    private function riderFixture(bool $withAssignment = true): array
    {
        foreach (['record-logistics-proof', 'update-logistics-status'] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shop->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'shipped',
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo(['record-logistics-proof', 'update-logistics-status']);

        if ($withAssignment) {
            $profile = RiderProfile::factory()->create([
                'shop_owner_id' => $shop->id,
                'linked_type' => User::class,
                'linked_id' => $rider->id,
            ]);
            $assignment = $leg->assignments()->create([
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $profile->id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);
            $leg->events()->create([
                'shipment_id' => $leg->shipment_id,
                'event_type' => 'dropoff_arrived',
                'visibility' => 'internal',
                'message' => 'Rider arrived at the customer location.',
                'metadata' => ['delivery_assignment_id' => $assignment->id],
            ]);
        }

        return [$shop, $order, $leg, $rider];
    }

    private function proofFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'proof.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
