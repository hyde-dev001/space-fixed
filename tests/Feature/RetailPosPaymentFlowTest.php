<?php

namespace Tests\Feature;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Finance\Invoice;
use App\Models\OrderItem;
use App\Models\PosTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\StockMovement;
use App\Services\Finance\FinanceSummaryService;
use Carbon\CarbonImmutable;
use App\Services\OrderReceiptService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RetailPosPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function anonymous_retail_checkout_is_allowed_and_uses_walk_in_display_name(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        /** @var User $cashier */
        $cashier = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Anonymous Retail POS Sneaker',
            'slug' => 'anonymous-retail-pos-sneaker-' . random_int(1000, 9999),
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', [
                'idempotency_key' => 'retail-anonymous-12345',
                'customer_type' => 'walk_in',
                'walk_in_name' => null,
                'walk_in_phone' => null,
                'walk_in_email' => null,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 500,
                ]],
                'payment_lines' => [[
                    'tender_type' => 'cash',
                    'amount' => 500,
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $transaction = PosTransaction::query()->findOrFail((int) $response->json('data.id'));
        $this->assertNull($transaction->customer_id);
        $this->assertNull($transaction->walk_in_name);
        $this->assertSame('Walk-in Customer', (string) $transaction->sourceOrder()->value('customer_name'));
        $this->assertSame(
            'Walk-in Customer',
            (string) data_get($transaction->receipt()->value('print_payload'), 'customer.name'),
        );
    }

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

        $order = $transaction->sourceOrder()->firstOrFail();
        $receiptService = app(OrderReceiptService::class);
        $this->assertTrue(
            $receiptService->isPosOrder($order),
            'Retail POS orders must be identified from the canonical POS transaction source.',
        );
        $this->assertFalse($receiptService->canConfirm($order));
        $this->assertSame(
            'invalid_state',
            $receiptService->confirm($order)['result'],
        );
    }

    #[Test]
    public function retail_pos_checkout_creates_one_paid_invoice_for_the_pos_order(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        /** @var User $cashier */
        $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Invoice Retail POS Sneaker',
            'slug' => 'invoice-retail-pos-' . random_int(1000, 9999),
            'price' => 100,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $payload = [
            'idempotency_key' => 'retail-invoice-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Invoice Buyer',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 100,
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 100]],
        ];

        $response = $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', $payload);

        $response->assertCreated();
        $transaction = PosTransaction::query()->findOrFail((int) $response->json('data.id'));
        $order = $transaction->sourceOrder()->firstOrFail();
        $invoice = Invoice::query()
            ->where('shop_id', $shopOwner->id)
            ->where('job_order_id', $order->id)
            ->first();

        $this->assertNotNull($invoice);
        $this->assertSame('paid', (string) $invoice->status);
        $this->assertSame($order->order_number, (string) $invoice->job_reference);
        $this->assertSame('retail_pos', data_get($invoice->meta, 'source'));
        $this->assertSame((string) $transaction->transaction_no, (string) data_get($invoice->meta, 'pos_transaction_no'));
        $this->assertSame('100.00', (string) $invoice->total);
        $this->assertSame('10.71', (string) $invoice->tax_amount);
        $this->assertSame(1, $invoice->items()->count());

        $this->actingAs($cashier, 'user')
            ->postJson('/api/retail-pos/checkout', $payload)
            ->assertCreated();

        $this->assertSame(
            1,
            Invoice::query()
                ->where('shop_id', $shopOwner->id)
                ->where('job_order_id', $order->id)
                ->count(),
        );

        Permission::findOrCreate('access-finance-invoices', 'user');
        $cashier->givePermissionTo('access-finance-invoices');
        $this->actingAs($cashier, 'user')
            ->getJson('/api/finance/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $invoice->reference)
            ->assertJsonPath('data.0.meta.source', 'retail_pos');
    }

    #[Test]
    public function retail_pos_checkout_decrements_the_linked_inventory_variant(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        /** @var User $cashier */
        $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Linked Retail POS Sneaker',
            'slug' => 'linked-retail-pos-' . random_int(1000, 9999),
            'price' => 100,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '8',
            'color' => 'Black',
            'quantity' => 1,
            'is_active' => true,
        ]);
        $inventoryItem = InventoryItem::create([
            'product_id' => $product->id,
            'shop_owner_id' => $shopOwner->id,
            'name' => $product->name,
            'sku' => 'INV-RETAIL-POS-1001',
            'category' => 'shoes',
            'unit' => 'pairs',
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'reorder_level' => 1,
            'reorder_quantity' => 5,
            'is_active' => true,
        ]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $inventoryItem->id,
            'color_name' => 'Black',
            'color_code' => '#000000',
            'quantity' => 10,
        ]);
        $size = InventorySize::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 10,
        ]);

        $response = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'idempotency_key' => 'retail-linked-inventory-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Linked Inventory Buyer',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 100,
                'size' => '8',
                'color' => 'Black',
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 200]],
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $transactionId = (int) $response->json('data.id');
        $transaction = PosTransaction::findOrFail($transactionId);
        $this->assertSame(8, (int) $product->fresh()->stock_quantity);
        $this->assertSame(1, (int) $variant->fresh()->quantity);
        $this->assertSame(8, (int) $inventoryItem->fresh()->available_quantity);
        $this->assertSame(8, (int) $color->fresh()->quantity);
        $this->assertSame(8, (int) $size->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -2,
            'quantity_before' => 10,
            'quantity_after' => 8,
            'reference_type' => 'order',
            'reference_id' => $transaction->module_reference_id,
        ]);
    }

    #[Test]
    public function retail_pos_checkout_rejects_when_linked_inventory_is_insufficient_and_rolls_back(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        /** @var User $cashier */
        $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Insufficient Linked Retail POS Sneaker',
            'slug' => 'insufficient-linked-retail-pos-' . random_int(1000, 9999),
            'price' => 100,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'size' => '8',
            'color' => 'Black',
            'quantity' => 10,
            'is_active' => true,
        ]);
        $inventoryItem = InventoryItem::create([
            'product_id' => $product->id,
            'shop_owner_id' => $shopOwner->id,
            'name' => $product->name,
            'sku' => 'INV-RETAIL-POS-1002',
            'category' => 'shoes',
            'unit' => 'pairs',
            'available_quantity' => 1,
            'reserved_quantity' => 0,
            'reorder_level' => 1,
            'reorder_quantity' => 5,
            'is_active' => true,
        ]);
        $color = InventoryColorVariant::create([
            'inventory_item_id' => $inventoryItem->id,
            'color_name' => 'Black',
            'color_code' => '#000000',
            'quantity' => 1,
        ]);
        InventorySize::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_color_variant_id' => $color->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 1,
        ]);

        $response = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'idempotency_key' => 'retail-insufficient-inventory-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Insufficient Inventory Buyer',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 100,
                'size' => '8',
                'color' => 'Black',
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 200]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $this->assertSame(1, (int) $inventoryItem->fresh()->available_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[Test]
    public function retail_pos_sale_is_counted_once_in_the_finance_summary(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        /** @var User $cashier */
        $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Finance Retail POS Sneaker',
            'slug' => 'finance-retail-pos-' . random_int(1000, 9999),
            'price' => 100,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'idempotency_key' => 'retail-finance-summary-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Finance Summary Buyer',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 100,
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 100]],
        ]);

        $response->assertCreated();
        $summary = app(FinanceSummaryService::class)->forCurrentPeriod(
            (int) $shopOwner->id,
            CarbonImmutable::now(config('app.timezone')),
        );

        $this->assertSame('89.29', $summary['supporting']['gross_revenue']);
        $this->assertSame('100.00', $summary['primary']['net_cash_movement']);
    }
}
