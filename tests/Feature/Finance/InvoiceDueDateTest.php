<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvoiceDueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-invoices', 'user');
    }

    public function test_payment_condition_derives_due_date_and_ignores_tampered_date(): void
    {
        [$shop, $user] = $this->makeContext();

        foreach (['Net 7' => '2026-09-12', 'Net 15' => '2026-09-20', 'Net 30' => '2026-10-05', 'Due on receipt' => '2026-09-05'] as $condition => $expectedDueDate) {
            $response = $this->actingAs($user, 'user')->postJson('/api/finance/invoices', [
                'reference' => 'INV-' . str_replace(' ', '-', $condition),
                'customer_name' => 'Due Date Buyer',
                'date' => '2026-09-05',
                'due_date' => '2026-09-06',
                'payment_condition' => $condition,
                'items' => [[
                    'description' => 'Repair service',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                ]],
            ]);

            $response->assertCreated();
            $invoice = Invoice::query()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
            $this->assertSame($expectedDueDate, $invoice->due_date->toDateString());
            $this->assertSame($condition, data_get($invoice->meta, 'payment_condition'));
        }
    }

    public function test_draft_update_rederives_due_date_from_the_new_condition_and_issue_date(): void
    {
        [$shop, $user] = $this->makeContext();
        $invoice = Invoice::create([
            'reference' => 'INV-UPDATE-DUE-DATE',
            'customer_name' => 'Update Buyer',
            'date' => '2026-09-01',
            'due_date' => '2026-09-08',
            'total' => 100,
            'tax_amount' => 0,
            'status' => 'draft',
            'shop_id' => $shop->id,
        ]);

        $this->actingAs($user, 'user')
            ->patchJson('/api/finance/invoices/' . $invoice->id, [
                'date' => '2026-09-05',
                'due_date' => '2026-09-06',
                'payment_condition' => 'Net 30',
            ])
            ->assertOk();

        $this->assertSame('2026-10-05', $invoice->fresh()->due_date->toDateString());
    }

    private function makeContext(): array
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('access-finance-invoices');

        return [$shop, $user];
    }
}
