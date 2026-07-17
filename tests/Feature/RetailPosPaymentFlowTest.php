<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\PosTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailPosPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_walk_in_checkout_creates_retail_pos_transaction(): void
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
            'name' => 'Retail POS Sneaker',
            'slug' => 'retail-pos-sneaker-' . random_int(1000, 9999),
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '42',
            'color' => 'Black',
            'quantity' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-test-12345',
                'customer_type' => 'walk_in',
                'walk_in_name' => 'Walk In Buyer',
                'walk_in_phone' => '09170000000',
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 500,
                    'size' => '42',
                    'color' => 'Black',
                ]],
                'payment_lines' => [[
                    'tender_type' => 'cash',
                    'amount' => 500,
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.module_type', 'retail');

        $transactionId = (int) $response->json('data.id');
        $transaction = PosTransaction::query()->findOrFail($transactionId);

        $this->assertSame('retail', (string) $transaction->module_type);
        $this->assertSame('paid', (string) $transaction->status);
        $this->assertSame(9, (int) $product->fresh()->stock_quantity);
        $this->assertSame($variant->id, OrderItem::where('order_id', $transaction->module_reference_id)->value('product_variant_id'));
    }
}
