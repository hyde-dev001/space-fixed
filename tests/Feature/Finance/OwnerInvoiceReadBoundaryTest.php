<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerInvoiceReadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_reads_own_invoice_and_cannot_mutate_finance_history(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        ShopOwnerModule::factory()->create(['shop_owner_id' => $owner->id, 'module_key' => 'finance', 'enabled' => true]);
        $invoice = Invoice::create(['shop_id' => $owner->id, 'reference' => 'INV-OWNER-RO',
            'customer_name' => 'QA customer', 'date' => now()->toDateString(), 'total' => 100, 'status' => 'draft']);
        $this->actingAs($owner, 'shop_owner')->getJson('/api/shop-owner/finance/invoices/'.$invoice->id)
            ->assertOk()->assertJsonPath('reference', 'INV-OWNER-RO');
        foreach ([true, false] as $enforced) {
            config(['shop_modules.enforcement_enabled' => $enforced]);
            foreach ([
                ['POST', '/send'], ['POST', '/mark-paid'], ['POST', '/post'], ['POST', '/restore'],
                ['PATCH', ''], ['DELETE', ''],
            ] as [$method, $suffix]) {
                $this->json($method, '/api/shop-owner/finance/invoices/'.$invoice->id.$suffix, [
                    'amount' => 100, 'status' => 'paid', 'customer_name' => 'Forbidden edit',
                ])->assertForbidden();
            }
        }
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('QA customer', $invoice->fresh()->customer_name);
        $this->assertNull($invoice->fresh()->deleted_at);
        $this->assertDatabaseCount('finance_invoice_payments', 0);
    }
}
