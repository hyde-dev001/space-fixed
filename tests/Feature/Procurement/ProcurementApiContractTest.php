<?php

namespace Tests\Feature\Procurement;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProcurementApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_mutations_use_the_standard_envelope(): void
    {
        config(['auth.defaults.guard' => 'user']);
        $owner = ShopOwner::factory()->create();
        $user = User::factory()->for($owner)->create();
        Permission::findOrCreate('procurement.manage_suppliers', 'user');
        $user->givePermissionTo('procurement.manage_suppliers');

        $created = $this->actingAs($user)->postJson('/api/erp/procurement/suppliers', ['name' => 'Local Supplier'])->assertCreated();
        $this->assertSame(['message', 'data'], array_keys($created->json()));
        $id = $created->json('data.id');

        $updated = $this->putJson("/api/erp/procurement/suppliers/{$id}", ['name' => 'Updated Supplier'])->assertOk();
        $this->assertSame(['message', 'data'], array_keys($updated->json()));

        $archived = $this->deleteJson("/api/erp/procurement/suppliers/{$id}")->assertOk();
        $this->assertSame(['message', 'data'], array_keys($archived->json()));

        $restored = $this->postJson("/api/erp/procurement/suppliers/{$id}/restore")->assertOk();
        $this->assertSame(['message', 'data'], array_keys($restored->json()));
    }

    public function test_supplier_workflow_schema_has_only_the_approved_records_and_links(): void
    {
        foreach ([
            'supplier_payment_profiles',
            'supplier_payment_attempts',
            'supplier_adjustments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table} table.");
        }

        $this->assertTrue(Schema::hasColumns('purchase_order_receipt_items', [
            'replacement_for_adjustment_id',
        ]));
        $this->assertTrue(Schema::hasColumns('finance_expense_settlements', [
            'supplier_adjustment_id',
            'notes',
        ]));

        $this->assertFalse(Schema::hasTable('supplier_refunds'));
        $this->assertFalse(Schema::hasTable('supplier_adjustment_attachments'));
    }

    public function test_supplier_workflow_models_preserve_sensitive_fields_and_relationships(): void
    {
        $profile = new \App\Models\SupplierPaymentProfile();
        $attempt = new \App\Models\SupplierPaymentAttempt();
        $adjustment = new \App\Models\SupplierAdjustment();

        $this->assertSame('encrypted', $profile->getCasts()['account_number']);
        $this->assertContains('account_number', $profile->getHidden());
        $this->assertSame('encrypted:array', $attempt->getCasts()['destination_snapshot']);
        $this->assertContains('destination_snapshot', $attempt->getHidden());

        $this->assertSame(ExpenseSettlement::ENTRY_SUPPLIER_REFUND, ExpenseSettlement::ENTRY_SUPPLIER_REFUND);
        $this->assertTrue(method_exists($adjustment, 'registerMediaCollections'));
        $this->assertTrue(method_exists($profile, 'supplier'));
        $this->assertTrue(method_exists($attempt, 'expense'));
        $this->assertTrue(method_exists($attempt, 'settlement'));
        $this->assertTrue(method_exists($adjustment, 'receiptItem'));
        $this->assertTrue(method_exists((new Supplier()), 'paymentProfile'));
        $this->assertTrue(method_exists((new PurchaseOrderReceiptItem()), 'replacementForAdjustment'));
        $this->assertTrue(method_exists((new Expense()), 'supplierPaymentAttempts'));
        $this->assertTrue(method_exists((new ExpenseSettlement()), 'supplierAdjustment'));
    }
}
