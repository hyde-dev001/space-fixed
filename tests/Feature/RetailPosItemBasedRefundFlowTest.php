<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\PosRefund;
use App\Models\PosRefundItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\RefundInventoryDispositionService;
use App\Services\RetailPosRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailPosItemBasedRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_pos_partial_refund_persists_line_qty_and_disposition(): void
    {
        [$cashier, $product, $transactionId, $orderItemId] = $this->seedCheckout();

        $response = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItemId,
                        'requested_qty' => 1,
                        'inspection_disposition' => 'damaged',
                    ],
                ],
                'reason_code' => 'damaged_item',
                'reason_notes' => 'Customer reported damaged pair.',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('pos_refund_items', [
            'order_item_id' => $orderItemId,
            'requested_qty' => 1,
            'inspection_disposition' => 'damaged',
        ]);

        $refundId = (int) $response->json('refund_id');
        $refund = PosRefund::query()->findOrFail($refundId);
        $this->assertSame(800.0, round((float) $refund->requested_amount, 2));

        // Stock remains reduced by checkout until execute.
        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function damaged_refund_lines_do_not_restock_sellable_inventory(): void
    {
        [$cashier, $product, $transactionId, $orderItemId] = $this->seedCheckout();

        $requestRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItemId,
                        'requested_qty' => 1,
                        'inspection_disposition' => 'damaged',
                    ],
                ],
                'reason_code' => 'damaged_item',
                'reason_notes' => 'Damage confirmed at counter.',
            ]);

        $requestRefund->assertOk();
        $refundId = (int) $requestRefund->json('refund_id');

        $approve = $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/approve", [
                'approval_note' => 'Approved by cashier.',
            ]);

        $approve->assertOk()->assertJsonPath('data.status', 'approved');

        $execute = $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/execute", [
                'execution_mode' => 'manual',
                'execution_note' => 'Cash payout done.',
            ]);

        $execute->assertOk()->assertJsonPath('data.status', 'succeeded');

        $line = PosRefundItem::query()->where('pos_refund_id', $refundId)->firstOrFail();

        $this->assertSame('write_off', (string) $line->inventory_action);
        $this->assertNotNull($line->inventory_applied_at);
        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function resellable_refund_lines_restock_exact_qty_once_even_on_retry(): void
    {
        [$cashier, $product, $transactionId, $orderItemId] = $this->seedCheckout();

        $requestRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItemId,
                        'requested_qty' => 1,
                        'inspection_disposition' => 'resellable',
                    ],
                ],
                'reason_code' => 'customer_return',
                'reason_notes' => 'Pair is still sellable.',
            ]);

        $requestRefund->assertOk();
        $refundId = (int) $requestRefund->json('refund_id');

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/approve", [
                'approval_note' => 'Approved by cashier.',
            ])
            ->assertOk();

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/execute", [
                'execution_mode' => 'manual',
                'execution_note' => 'Cash payout done.',
            ])
            ->assertOk();

        $line = PosRefundItem::query()->where('pos_refund_id', $refundId)->firstOrFail();
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);

        app(RefundInventoryDispositionService::class)->applyPosLine($line->fresh());

        $line->refresh();
        $this->assertSame('restock', (string) $line->inventory_action);
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function resellable_pos_refund_restores_only_the_variant_sold_on_the_order_item(): void
    {
        $checkout = $this->seedCheckout(withVariant: true);
        [$cashier, , $transactionId, $orderItemId, $soldVariant] = $checkout;
        $otherVariant = ProductVariant::query()->create([
            'product_id' => $soldVariant->product_id,
            'size' => '10',
            'color' => 'White',
            'quantity' => 7,
            'is_active' => true,
        ]);

        $requestRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [[
                    'order_item_id' => $orderItemId,
                    'requested_qty' => 1,
                    'inspection_disposition' => 'resellable',
                ]],
                'reason_code' => 'customer_return',
                'reason_notes' => 'Restore only the returned size and color.',
            ]);
        $requestRefund->assertOk();
        $refundId = (int) $requestRefund->json('refund_id');

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/approve", ['approval_note' => 'Approved.'])
            ->assertOk();
        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/execute", ['execution_mode' => 'manual'])
            ->assertOk();

        $this->assertSame(5, (int) $soldVariant->fresh()->quantity);
        $this->assertSame(7, (int) $otherVariant->fresh()->quantity);
    }

    #[Test]
    public function resellable_pos_refund_restores_linked_inventory_and_records_one_return_movement(): void
    {
        $checkout = $this->seedCheckout(withLinkedInventory: true);
        [$cashier, $product, $transactionId, $orderItemId] = $checkout;
        $inventoryItem = $checkout[5];

        $requestRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [[
                    'order_item_id' => $orderItemId,
                    'requested_qty' => 1,
                    'inspection_disposition' => 'resellable',
                ]],
                'reason_code' => 'customer_return',
                'reason_notes' => 'Linked stock should be restored once.',
            ]);
        $requestRefund->assertOk();
        $refundId = (int) $requestRefund->json('refund_id');

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/approve", ['approval_note' => 'Approved.'])
            ->assertOk();
        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/execute", ['execution_mode' => 'manual'])
            ->assertOk();

        $line = PosRefundItem::query()->where('pos_refund_id', $refundId)->firstOrFail();
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
        $this->assertSame(5, (int) $inventoryItem->fresh()->available_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'return',
            'quantity_change' => 1,
            'quantity_before' => 4,
            'quantity_after' => 5,
            'reference_type' => 'pos_refund_item',
            'reference_id' => $line->id,
        ]);

        app(RefundInventoryDispositionService::class)->applyPosLine($line->fresh());

        $this->assertSame(5, (int) $inventoryItem->fresh()->available_quantity);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'pos_refund_item')
            ->where('reference_id', $line->id)
            ->count());
    }

    #[Test]
    public function pos_refund_execution_rolls_back_payment_and_inventory_state_if_restock_fails(): void
    {
        [$cashier, $product, $transactionId, $orderItemId] = $this->seedCheckout();

        $requestRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [[
                    'order_item_id' => $orderItemId,
                    'requested_qty' => 1,
                    'inspection_disposition' => 'resellable',
                ]],
                'reason_code' => 'customer_return',
                'reason_notes' => 'Rollback should preserve the approved refund.',
            ]);
        $requestRefund->assertOk();
        $refundId = (int) $requestRefund->json('refund_id');

        $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/approve", ['approval_note' => 'Approved.'])
            ->assertOk();

        $inventoryFailure = \Mockery::mock(RefundInventoryDispositionService::class);
        $inventoryFailure->shouldReceive('applyPosLine')
            ->once()
            ->andThrow(new \RuntimeException('Inventory restoration failed.'));
        $this->app->instance(RefundInventoryDispositionService::class, $inventoryFailure);

        try {
            app(RetailPosRefundService::class)->execute(PosRefund::query()->findOrFail($refundId), (int) $cashier->id);
            $this->fail('Expected the inventory restoration error to escape execution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Inventory restoration failed.', $exception->getMessage());
        }

        $this->assertSame('approved', (string) PosRefund::query()->findOrFail($refundId)->status);
        $this->assertSame(0, DB::table('pos_refund_lines')->where('pos_refund_id', $refundId)->count());
        $this->assertSame('paid', (string) DB::table('pos_transactions')->where('id', $transactionId)->value('status'));
        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
    }

    #[Test]
    public function second_pos_partial_refund_cannot_exceed_remaining_qty(): void
    {
        [$cashier, $product, $transactionId, $orderItemId] = $this->seedCheckout(2);

        $first = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItemId,
                        'requested_qty' => 1,
                        'inspection_disposition' => 'damaged',
                    ],
                ],
                'reason_code' => 'damaged_item',
                'reason_notes' => 'First partial refund line.',
            ]);

        $first->assertOk();
        $firstRefund = PosRefund::query()->findOrFail((int) $first->json('refund_id'));
        $firstRefund->update([
            'status' => 'succeeded',
            'approved_amount' => 800,
            'executed_at' => now(),
        ]);

        $second = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'partial',
                'refund_lines' => [
                    [
                        'order_item_id' => $orderItemId,
                        'requested_qty' => 2,
                        'inspection_disposition' => 'damaged',
                    ],
                ],
                'reason_code' => 'damaged_item',
                'reason_notes' => 'Second request should exceed remaining qty.',
            ]);

        $second->assertStatus(422)
            ->assertJsonValidationErrors(['refund_lines']);

        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    /**
     * @return array{0: User, 1: Product, 2: int, 3: int, 4: ?ProductVariant, 5: ?InventoryItem}
     */
    private function seedCheckout(int $qty = 1, bool $withVariant = false, bool $withLinkedInventory = false): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        /** @var User $cashier */
        $cashier = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);
        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shopOwner->id,
            'email' => $cashier->email,
        ]);
        DB::table('attendance_records')->insert([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shopOwner->id,
            'date' => now('Asia/Manila')->toDateString(),
            'check_in_time' => '08:00',
            'expected_check_in' => '08:00',
            'expected_check_out' => '20:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Refundable Retail Shoe',
            'slug' => 'refund-line-shoe-' . random_int(1000, 9999),
            'price' => 800,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $variant = $withVariant ? ProductVariant::query()->create([
            'product_id' => $product->id,
            'size' => '9',
            'color' => 'Black',
            'quantity' => 5,
            'is_active' => true,
        ]) : null;
        $inventoryItem = $withLinkedInventory ? InventoryItem::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => 'POS-REFUND-' . random_int(100000, 999999),
            'category' => 'shoes',
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]) : null;

        $checkoutItem = [
            'product_id' => $product->id,
            'qty' => $qty,
            'unit_price' => 800,
        ];
        if ($variant) {
            $checkoutItem['variant_id'] = $variant->id;
        }

        $checkout = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-refund-line-' . random_int(1000, 9999),
                'customer_type' => 'walk_in',
                'walk_in_name' => 'Walk In Buyer',
                'items' => [$checkoutItem],
                'payment_lines' => [[
                    'tender_type' => 'cash',
                    'amount' => 800 * $qty,
                ]],
            ]);

        $checkout->assertCreated();
        $transactionId = (int) $checkout->json('data.id');

        $order = Order::query()->findOrFail((int) $checkout->json('data.module_reference_id'));
        $orderItemId = (int) $order->items()->value('id');

        return [$cashier, $product, $transactionId, $orderItemId, $variant, $inventoryItem];
    }
}
