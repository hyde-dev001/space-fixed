<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailPosRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_refund_can_be_requested_approved_and_executed(): void
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
            'slug' => 'refundable-retail-shoe-' . random_int(1000, 9999),
            'price' => 800,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $checkout = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-refund-12345',
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

        $requestRefund = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/refunds', [
                'source_transaction_id' => $transactionId,
                'request_type' => 'full',
                'requested_amount' => 800,
                'reason_code' => 'customer_return',
                'reason_notes' => 'Customer returned item at counter.',
            ]);

        $requestRefund->assertOk()->assertJsonPath('success', true);

        $refundId = (int) $requestRefund->json('refund_id');

        $approve = $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/approve", [
                'approved_amount' => 800,
                'approval_note' => 'Approved at cashier desk.',
            ]);

        $approve->assertOk()->assertJsonPath('data.status', 'approved');

        $execute = $this->actingAs($cashier, 'user')
            ->postJson("/api/retail-pos/refunds/{$refundId}/execute", [
                'execution_mode' => 'manual',
                'execution_note' => 'Cash returned to customer.',
            ]);

        $execute->assertOk()->assertJsonPath('data.status', 'succeeded');

        $refund = PosRefund::query()->findOrFail($refundId);
        $transaction = PosTransaction::query()->findOrFail($transactionId);

        $this->assertSame('succeeded', (string) $refund->status);
        $this->assertSame('refunded', (string) $transaction->status);
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }
}
