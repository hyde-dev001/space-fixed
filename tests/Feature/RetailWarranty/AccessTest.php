<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\RetailWarranty;
use App\Models\RetailWarrantyIssuance;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_private_download_and_read_projection_are_scoped_and_do_not_write_expiry(): void
    {
        Storage::fake('local');
        $issuance = RetailWarrantyIssuance::factory()->create();
        $warranty = RetailWarranty::factory()->create(['retail_warranty_issuance_id' => $issuance->id, 'warranty_expiration_date' => now()->subSecond()]);
        $buyer = User::findOrFail($issuance->customer_id);
        $this->actingAs($buyer, 'user')->getJson('/orders/warranties/'.$issuance->warranty_number)->assertOk()
            ->assertJsonPath('data.status', 'expired')->assertJsonMissingPath('data.certificate_path')
            ->assertJsonMissingPath('data.email_delivery_state');
        $this->assertSame('active', $warranty->fresh()->status);
        $this->get('/orders/warranties/'.$issuance->warranty_number.'/certificate')->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = User::factory()->create();
        $this->actingAs($other, 'user')->getJson('/orders/warranties/'.$issuance->warranty_number)->assertNotFound();
        $this->getJson('/orders/warranties/'.$issuance->warranty_number.'/certificate')->assertNotFound();
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
    }

    public function test_owner_issued_warranty_management_routes_are_removed_without_changing_coverage(): void
    {
        $issuance = RetailWarrantyIssuance::factory()->create();
        $warranty = RetailWarranty::factory()->create(['retail_warranty_issuance_id' => $issuance->id]);
        $original = $warranty->fresh()->getAttributes();
        $owner = ShopOwner::findOrFail($issuance->shop_owner_id);
        ShopOwnerModule::updateOrCreate(['shop_owner_id' => $owner->id, 'module_key' => 'retail_operations'], ['enabled' => true]);
        $this->actingAs($owner, 'shop_owner');
        $prefix = '/api/shop-owner/retail-warranties';
        $this->getJson($prefix.'?search='.$issuance->warranty_number.'&status=active&page=1')->assertNotFound();
        $this->getJson($prefix.'/'.$issuance->warranty_number)->assertNotFound();
        $this->getJson($prefix.'/'.$issuance->warranty_number.'/certificate')->assertNotFound();
        $this->patchJson($prefix.'/items/'.$warranty->id.'/void', ['reason' => 'Legacy manual void attempt'])->assertNotFound();
        foreach (['index', 'show', 'certificate', 'void'] as $action) {
            $name = 'shop_owner.retail-warranties.'.$action;
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($name));
            $this->assertArrayNotHasKey($name, config('shop_modules.routes'));
        }
        $this->assertSame($original, $warranty->fresh()->getAttributes());
        $this->assertModelExists($issuance);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'retail_warranty.voided', 'target_id' => $warranty->id]);
    }

    public function test_owner_refund_context_has_no_removed_certificate_url(): void
    {
        $issuance = RetailWarrantyIssuance::factory()->create();
        $warranty = RetailWarranty::factory()->create(['retail_warranty_issuance_id' => $issuance->id]);
        $projection = app(\App\Services\RetailWarrantyService::class)->projectIssuances(collect([$issuance]), 'owner')[$issuance->order_id];
        $this->assertNull($projection['download_url']);
        $this->assertSame($issuance->warranty_number, $projection['reference']);
        $this->assertSame($warranty->id, $projection['items'][0]['id']);
        $this->assertSame($warranty->original_covered_quantity, $projection['items'][0]['available_quantity']);
    }

    public function test_staff_download_requires_explicit_permission_live_tenant_and_enabled_module(): void
    {
        Storage::fake('local');
        $issuance = RetailWarrantyIssuance::factory()->create();
        RetailWarranty::factory()->create(['retail_warranty_issuance_id' => $issuance->id]);
        $staff = User::factory()->create(['shop_owner_id' => $issuance->shop_owner_id, 'role' => 'STAFF']);
        ShopOwnerModule::updateOrCreate(['shop_owner_id' => $issuance->shop_owner_id, 'module_key' => 'retail_operations'], ['enabled' => true]);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $url = '/api/staff/retail-warranties/'.$issuance->warranty_number.'/certificate';
        $this->actingAs($staff, 'user')->getJson($url)->assertForbidden();
        $staff->givePermissionTo('access-staff-job-orders');
        $this->get($url)->assertOk();
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        ShopOwnerModule::updateOrCreate(['shop_owner_id' => $otherShop->id, 'module_key' => 'retail_operations'], ['enabled' => true]);
        $otherStaff = User::factory()->create(['shop_owner_id' => $otherShop->id, 'role' => 'STAFF']);
        $otherStaff->givePermissionTo('access-staff-job-orders');
        $this->actingAs($otherStaff, 'user')->getJson($url)->assertNotFound();
        $this->actingAs($staff, 'user');
        ShopOwnerModule::where('shop_owner_id', $issuance->shop_owner_id)->where('module_key', 'retail_operations')->update(['enabled' => false]);
        $this->getJson($url)->assertForbidden();
    }
}
