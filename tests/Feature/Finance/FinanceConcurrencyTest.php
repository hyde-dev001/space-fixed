<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-invoices', 'user');
    }

    public function test_job_invoice_creation_replays_the_existing_invoice_without_duplicate_items(): void
    {
        [$shop, $user, $order] = $this->makeJobContext();

        $first = $this->actingAs($user, 'user')->postJson('/api/finance/invoices/from-job', [
            'job_id' => $order->id,
            'auto_generate' => true,
        ]);
        $first->assertCreated();

        $second = $this->actingAs($user, 'user')->postJson('/api/finance/invoices/from-job', [
            'job_id' => $order->id,
            'auto_generate' => true,
        ]);
        $second->assertOk()->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_invoice_items', 2);
        $this->assertDatabaseHas('finance_invoices', [
            'shop_id' => $shop->id,
            'job_order_id' => $order->id,
        ]);
    }

    public function test_job_invoice_creation_cannot_cross_tenant_boundaries(): void
    {
        [$localShop, $user] = $this->makeJobContext(false);
        $otherShop = ShopOwner::factory()->create();
        $otherOrder = Order::factory()->create(['shop_owner_id' => $otherShop->id]);

        $response = $this->actingAs($user, 'user')->postJson('/api/finance/invoices/from-job', [
            'job_id' => $otherOrder->id,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('finance_invoices', [
            'shop_id' => $localShop->id,
            'job_order_id' => $otherOrder->id,
        ]);
    }

    public function test_job_invoice_unique_index_is_present_and_rejects_duplicate_rows(): void
    {
        $indexes = collect(Schema::getIndexes('finance_invoices'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['shop_id', 'job_order_id']
        ));

        [$shop, $user, $order] = $this->makeJobContext();
        $attributes = [
            'customer_name' => 'Duplicate Test',
            'date' => now()->toDateString(),
            'total' => '10.00',
            'tax_amount' => '0.00',
            'status' => 'draft',
            'shop_id' => $shop->id,
            'job_order_id' => $order->id,
        ];
        Invoice::create(['reference' => 'INV-DUP-1', ...$attributes]);

        $this->expectException(QueryException::class);
        Invoice::create(['reference' => 'INV-DUP-2', ...$attributes]);
    }

    public function test_job_invoice_migration_aborts_when_duplicate_rows_preexist(): void
    {
        Schema::table('finance_invoices', function ($table): void {
            $table->dropUnique('finance_invoices_shop_job_unique');
        });

        $migration = require base_path('database/migrations/2026_08_11_000002_add_finance_job_invoice_unique_constraint.php');
        [$shop, $user, $order] = $this->makeJobContext();
        $attributes = [
            'customer_name' => 'Migration Guard',
            'date' => now()->toDateString(),
            'total' => '10.00',
            'tax_amount' => '0.00',
            'status' => 'draft',
            'shop_id' => $shop->id,
            'job_order_id' => $order->id,
        ];
        Invoice::create(['reference' => 'INV-GUARD-1', ...$attributes]);
        Invoice::create(['reference' => 'INV-GUARD-2', ...$attributes]);

        try {
            $this->expectException(\RuntimeException::class);
            $migration->up();
        } finally {
            Invoice::whereIn('reference', ['INV-GUARD-1', 'INV-GUARD-2'])->delete();
            $migration->up();
        }
    }

    private function makeJobContext(bool $withOrder = true): array
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('access-finance-invoices');

        if (! $withOrder) {
            return [$shop, $user];
        }

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'total_amount' => 1000,
            'shipping_fee' => 50,
            'vat_amount' => 120,
        ]);

        return [$shop, $user, $order];
    }
}
