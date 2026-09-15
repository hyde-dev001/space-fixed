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
        $variant = ProductVariant::create([
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
        $size = InventorySize::create([
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
                'variant_id' => $variant->id,
                'inventory_color_variant_id' => $color->id,
                'inventory_size_id' => $size->id,
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
    #[Test]
    public function retail_pos_product_listing_returns_exact_live_linked_variant_stock_and_ids(): void
    {
        ['product' => $product] = $this->createLinkedRetailCatalog();

        $response = $this->actingAs(User::query()->where('shop_owner_id', $product->shop_owner_id)->firstOrFail(), 'user')
            ->getJson('/api/retail-pos/products');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame(36, (int) $row['stock_quantity']);

        $variants = collect($row['variants'])->keyBy(fn (array $variant): string => $variant['color'] . '/' . $variant['size']);
        $this->assertSame(4, (int) $variants['Black/8']['quantity']);
        $this->assertSame(12, (int) $variants['Black/9']['quantity']);
        $this->assertSame(20, (int) $variants['White/8']['quantity']);
        $this->assertNotNull($variants['Black/8']['inventory_color_variant_id']);
        $this->assertNotNull($variants['Black/8']['inventory_size_id']);
        $this->assertNotSame($variants['Black/8']['inventory_size_id'], $variants['Black/9']['inventory_size_id']);
    }

    #[Test]
    public function retail_pos_listing_reflects_post_sale_linked_variant_stock_without_hard_refresh(): void
    {
        ['cashier' => $cashier, 'product' => $product, 'blackEight' => $blackEight, 'blackEightSize' => $blackEightSize] = $this->createLinkedRetailCatalog();

        $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'idempotency_key' => 'retail-live-linked-stock-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Live Stock Buyer',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $blackEight->id,
                'inventory_color_variant_id' => $blackEightSize->inventory_color_variant_id,
                'inventory_size_id' => $blackEightSize->id,
                'qty' => 2,
                'unit_price' => 100,
                'size' => '8',
                'color' => 'Black',
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 200]],
        ])->assertCreated();

        $listing = $this->actingAs($cashier, 'user')->getJson('/api/retail-pos/products')->assertOk();
        $row = collect($listing->json('data'))->firstWhere('id', $product->id);
        $variant = collect($row['variants'])->firstWhere('inventory_size_id', $blackEightSize->id);

        $this->assertSame(2, (int) $blackEightSize->fresh()->quantity);
        $this->assertSame(2, (int) $variant['quantity']);
    }

    #[Test]
    public function retail_pos_rejects_a_valid_same_product_but_wrong_inventory_size_id(): void
    {
        ['cashier' => $cashier, 'product' => $product, 'blackEight' => $blackEight, 'blackEightSize' => $blackEightSize, 'blackNineSize' => $blackNineSize] = $this->createLinkedRetailCatalog();

        $response = $this->actingAs($cashier, 'user')->postJson('/api/retail-pos/checkout', [
            'idempotency_key' => 'retail-wrong-linked-target-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Wrong Target Buyer',
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $blackEight->id,
                'inventory_color_variant_id' => $blackEightSize->inventory_color_variant_id,
                'inventory_size_id' => $blackNineSize->id,
                'qty' => 1,
                'unit_price' => 100,
                'size' => '8',
                'color' => 'Black',
            ]],
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(4, (int) $blackEightSize->fresh()->quantity);
        $this->assertSame(12, (int) $blackNineSize->fresh()->quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    /** @return array{cashier: User, product: Product, blackEight: ProductVariant, blackEightSize: InventorySize, blackNineSize: InventorySize} */
    private function createLinkedRetailCatalog(): array
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $cashier = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Canonical Linked Retail POS Sneaker',
            'slug' => 'canonical-linked-retail-pos-' . random_int(1000, 9999),
            'price' => 100,
            'stock_quantity' => 99,
            'is_active' => true,
        ]);
        $blackEight = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '8',
            'color' => 'Black',
            'quantity' => 99,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'size' => '9',
            'color' => 'Black',
            'quantity' => 99,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'size' => '8',
            'color' => 'White',
            'quantity' => 99,
            'is_active' => true,
        ]);
        $inventoryItem = InventoryItem::create([
            'product_id' => $product->id,
            'shop_owner_id' => $shopOwner->id,
            'name' => $product->name,
            'sku' => 'INV-CANONICAL-RETAIL-' . random_int(1000, 9999),
            'category' => 'shoes',
            'unit' => 'pairs',
            'available_quantity' => 36,
            'reserved_quantity' => 0,
            'reorder_level' => 1,
            'reorder_quantity' => 5,
            'is_active' => true,
        ]);
        $black = InventoryColorVariant::create([
            'inventory_item_id' => $inventoryItem->id,
            'color_name' => 'Black',
            'quantity' => 16,
        ]);
        $white = InventoryColorVariant::create([
            'inventory_item_id' => $inventoryItem->id,
            'color_name' => 'White',
            'quantity' => 20,
        ]);
        $blackEightSize = InventorySize::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 4,
        ]);
        $blackNineSize = InventorySize::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_color_variant_id' => $black->id,
            'size' => '9',
            'size_system' => 'US',
            'quantity' => 12,
        ]);
        InventorySize::create([
            'inventory_item_id' => $inventoryItem->id,
            'inventory_color_variant_id' => $white->id,
            'size' => '8',
            'size_system' => 'US',
            'quantity' => 20,
        ]);

        return compact('cashier', 'product', 'blackEight', 'blackEightSize', 'blackNineSize');
    }
}
