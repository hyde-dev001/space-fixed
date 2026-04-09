<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PosRefund;
use App\Models\PosRefundItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RefundInventoryDispositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * @return array{0: User, 1: Product, 2: int, 3: int}
     */
    private function seedCheckout(): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
        ]);

        /** @var User $cashier */
        $cashier = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Refundable Retail Shoe',
            'slug' => 'refund-line-shoe-' . random_int(1000, 9999),
            'price' => 800,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $checkout = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-refund-line-' . random_int(1000, 9999),
                'customer_type' => 'walk_in',
                'walk_in_name' => 'Walk In Buyer',
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 800,
                ]],
                'payment_lines' => [[
                    'tender_type' => 'cash',
                    'amount' => 800,
                ]],
            ]);

        $checkout->assertCreated();
        $transactionId = (int) $checkout->json('data.id');

        $order = Order::query()->findOrFail((int) $checkout->json('data.module_reference_id'));
        $orderItemId = (int) $order->items()->value('id');

        return [$cashier, $product, $transactionId, $orderItemId];
    }
}
