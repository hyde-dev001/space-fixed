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

    public function test_owner_history_is_paginated_tenant_scoped_searchable_and_void_requires_reason(): void
    {
        Storage::fake('local');
        $issuance = RetailWarrantyIssuance::factory()->create();
        $warranty = RetailWarranty::factory()->create(['retail_warranty_issuance_id' => $issuance->id]);
        RetailWarranty::factory()->create();
        $owner = ShopOwner::findOrFail($issuance->shop_owner_id);
        ShopOwnerModule::updateOrCreate(['shop_owner_id' => $owner->id, 'module_key' => 'retail_operations'], ['enabled' => true]);
        $this->actingAs($owner, 'shop_owner')->getJson('/api/shop-owner/retail-warranties?search='.$issuance->warranty_number)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.reference', $issuance->warranty_number);
        $this->patchJson('/api/shop-owner/retail-warranties/items/'.$warranty->id.'/void', ['reason' => ''])->assertUnprocessable();
        $this->patchJson('/api/shop-owner/retail-warranties/items/'.$warranty->id.'/void', ['reason' => 'Inspection confirmed excluded deliberate damage.'])->assertOk();
        $this->assertSame('voided', $warranty->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retail_warranty.voided', 'target_id' => $warranty->id]);
        $other = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        ShopOwnerModule::updateOrCreate(['shop_owner_id' => $other->id, 'module_key' => 'retail_operations'], ['enabled' => true]);
        $this->actingAs($other, 'shop_owner')->getJson('/api/shop-owner/retail-warranties/'.$issuance->warranty_number.'/certificate')->assertNotFound();
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
        ShopOwnerModule::where('shop_owner_id', $issuance->shop_owner_id)->where('module_key', 'retail_operations')->update(['enabled' => false]);
        $this->getJson($url)->assertForbidden();
    }
}
