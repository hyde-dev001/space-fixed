<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceReceiptSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-expenses', 'user');
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_receipt_upload_uses_private_server_generated_path(): void
    {
        $shop = $this->shop();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('access-finance-expenses');
        $expense = $this->expense($shop->id);

        $response = $this->actingAs($user, 'user')->post('/api/finance/expenses/'.$expense->id.'/receipt', [
            'receipt' => UploadedFile::fake()->create('../../receipt.pdf', 10, 'application/pdf'),
        ]);

        $response->assertOk();
        $expense->refresh();
        $this->assertStringStartsWith('finance/shops/1/expenses/'.$expense->id.'/receipts/', $expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);
        Storage::disk('public')->assertMissing($expense->receipt_path);
    }

    public function test_cross_shop_receipt_download_does_not_disclose_existence(): void
    {
        $shop = $this->shop();
        $otherShop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $user->givePermissionTo('access-finance-expenses');
        $expense = $this->expense($otherShop->id, 'finance/shops/'.$otherShop->id.'/expenses/1/receipts/receipt.pdf');
        Storage::disk('local')->put($expense->receipt_path, 'secret');

        $this->actingAs($user, 'user')
            ->get('/api/finance/expenses/'.$expense->id.'/receipt')
            ->assertNotFound();
    }

    public function test_owner_finance_endpoint_resolves_erp_tenant_context(): void
    {
        $shop = $this->shop();

        $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/finance/expenses')
            ->assertOk();
    }

    public function test_owner_finance_dashboard_endpoint_resolves_erp_tenant_context(): void
    {
        $shop = $this->shop();

        $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/finance/dashboard')
            ->assertOk()
            ->assertJsonStructure(['period', 'primary', 'supporting', 'trend', 'definitions', 'integrity_warnings']);
    }

    private function expense(int $shopId, ?string $path = null): Expense
    {
        return Expense::create([
            'reference' => 'EXP-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Supplies',
            'amount' => '10.00',
            'tax_amount' => '0.00',
            'status' => 'submitted',
            'shop_id' => $shopId,
            'receipt_path' => $path,
            'receipt_original_name' => $path ? 'receipt.pdf' : null,
            'receipt_mime_type' => $path ? 'application/pdf' : null,
        ]);
    }

    private function shop(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
    }
}
