<?php

namespace Tests\Feature\Finance;

use App\Models\Approval;
use App\Models\PriceChangeRequest;
use App\Models\ProcurementSettings;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\PriceChangeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PriceChangeApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwnerAuth;
    private User $shopOwnerApproverUser;
    private User $requester;
    private User $financeFirst;
    private User $financeSecond;
    private PriceChangeApprovalService $priceChangeApprovalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('finance', 'user');
        Role::findOrCreate('shop-owner', 'user');

        $this->shopOwnerAuth = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        // The owner approval endpoint resolves a linked owner actor for the
        // generic Approval User foreign keys.
        $this->shopOwnerApproverUser = User::factory()->create([
            'id' => $this->shopOwnerAuth->id,
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->shopOwnerApproverUser->assignRole('shop-owner');

        $this->requester = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);

        $this->financeFirst = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeFirst->assignRole('finance');

        $this->financeSecond = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeSecond->assignRole('finance');

        $this->priceChangeApprovalService = app(PriceChangeApprovalService::class);
    }

    public function test_price_change_approval_progresses_through_all_three_levels(): void
    {
        $priceChange = $this->createWorkflowBoundPriceChange();

        $this->priceChangeApprovalService->notifyPriceChangeApprovalRequested($priceChange);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->financeFirst->id,
            'title' => 'New Price Change Request',
            'action_url' => "/finance?section=shoe-pricing&price_change={$priceChange->id}",
        ]);

        // Level 1: Finance
        $firstApproval = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Finance initial review approved',
            ]);

        $firstApproval->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_final' => false,
                'approval_level' => 2,
            ]);

        $priceChange->refresh();
        $this->assertSame(2, $priceChange->current_approval_level);

        // Wrong actor at level 2: Finance cannot approve shop owner stage
        $wrongRoleApproval = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Attempting to bypass owner stage',
            ]);

        $wrongRoleApproval->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);

        // Level 2: Shop owner
        $ownerApproval = $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/shop-owner/price-changes/{$priceChange->id}/approve");

        $ownerApproval->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_final' => false,
                'approval_level' => 3,
            ]);

        // Level 3: Finance final
        $thirdApproval = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Secondary finance review approved',
            ]);

        $thirdApproval->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_final' => true,
                'approval_level' => 3,
            ]);

        $priceChange->refresh();
        $this->assertSame(3, $priceChange->current_approval_level);
        $this->assertSame('owner_approved', $priceChange->status->value ?? $priceChange->status);

        $this->assertDatabaseHas('approvals', [
            'id' => $priceChange->approval_id,
            'status' => 'approved',
            'current_level' => 3,
            'current_approver_role' => 'finance',
        ]);
    }

    public function test_finance_can_reject_price_change_at_level_one(): void
    {
        $priceChange = $this->createWorkflowBoundPriceChange();

        $rejection = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/reject", [
                'reason' => 'Insufficient justification',
            ]);

        $rejection->assertStatus(200)
            ->assertJson([
                'success' => true,
                'rejection_level' => 1,
            ]);

        $priceChange->refresh();
        $this->assertSame(1, $priceChange->current_approval_level);
        $this->assertSame('finance_rejected', $priceChange->status->value ?? $priceChange->status);

        $approval = Approval::findOrFail($priceChange->approval_id);
        $this->assertSame('rejected', $approval->status->value ?? $approval->status);
    }

    public function test_same_shop_finance_user_cannot_approve_owner_stage(): void
    {
        $priceChange = $this->createWorkflowBoundPriceChange();

        // Level 1 (Finance) approval should succeed and move workflow to owner stage.
        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Finance initial review approved',
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'approval_level' => 2,
            ]);

        $priceChange->refresh();
        $this->assertSame(2, $priceChange->current_approval_level);
        $this->assertSame('finance_approved', $priceChange->status->value ?? $priceChange->status);

        // Regression guard: another finance user from same shop cannot bypass owner stage.
        $blockedAttempt = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Attempt to bypass owner stage',
            ]);

        $blockedAttempt->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);

        $priceChange->refresh();
        $this->assertSame(2, $priceChange->current_approval_level);
        $this->assertSame('finance_approved', $priceChange->status->value ?? $priceChange->status);

        $approval = Approval::findOrFail($priceChange->approval_id);
        $this->assertSame(2, (int) $approval->current_level);
        $this->assertSame('shop_owner', (string) $approval->current_approver_role);
    }

    public function test_owner_can_approve_price_change_without_an_erp_user_mirror(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $settings = ProcurementSettings::getForShopOwner($owner->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['price_approval']['enabled'] = true;
        $settings->update(['settings_json' => $settingsJson]);

        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $finance = User::factory()->create(['shop_owner_id' => $owner->id]);
        $finance->assignRole('finance');
        $product = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Owner Mirror Product ' . random_int(1000, 9999),
            'slug' => 'owner-mirror-product-' . uniqid(),
            'description' => 'Owner mirror regression product',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('users', [
            'shop_owner_id' => $owner->id,
            'email' => $owner->email,
        ]);

        $this->actingAs($requester, 'user')
            ->postJson("/api/products/{$product->id}/request-price-change", [
                'product_name' => $product->name,
                'current_price' => 100,
                'proposed_price' => 130,
                'reason' => 'Owner mirror regression test',
            ])
            ->assertStatus(201);

        $priceChange = PriceChangeRequest::query()
            ->where('product_id', $product->id)
            ->latest('id')
            ->firstOrFail();
        $ownerProxy = User::query()
            ->where('shop_owner_id', $owner->id)
            ->where('email', "shopowner+{$owner->id}@solespace.local")
            ->first();

        $this->assertNotNull($ownerProxy);
        $this->assertSame('STAFF', $ownerProxy->role);
        $this->assertDatabaseHas('approvals', [
            'id' => $priceChange->approval_id,
            'shop_owner_id' => $ownerProxy->id,
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Finance initial review',
            ])
            ->assertOk()
            ->assertJsonPath('approval_level', 2);

        $this->actingAs($owner, 'shop_owner')
            ->postJson("/api/shop-owner/price-changes/{$priceChange->id}/approve")
            ->assertOk()
            ->assertJsonPath('approval_level', 3);
    }

    public function test_owner_cannot_approve_a_price_change_from_another_shop(): void
    {
        $otherOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $product = Product::create([
            'shop_owner_id' => $otherOwner->id,
            'name' => 'Cross Shop Price Product ' . random_int(1000, 9999),
            'slug' => 'cross-shop-price-product-' . uniqid(),
            'description' => 'Cross-shop price approval regression product',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $priceChange = PriceChangeRequest::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 130,
            'reason' => 'Cross-shop authorization test',
            'requested_by' => User::factory()->create(['shop_owner_id' => $otherOwner->id])->id,
            'status' => 'finance_approved',
            'shop_owner_id' => $otherOwner->id,
        ]);

        $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/shop-owner/price-changes/{$priceChange->id}/approve")
            ->assertNotFound();
    }

    public function test_product_price_workflow_keeps_the_submission_role_map_after_settings_change(): void
    {
        $product = Product::create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'name' => 'Snapshot Product ' . random_int(1000, 9999),
            'slug' => 'snapshot-product-' . uniqid(),
            'description' => 'Price snapshot test product',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $settings = ProcurementSettings::getForShopOwner($this->shopOwnerAuth->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['price_approval']['enabled'] = true;
        $settings->update(['settings_json' => $settingsJson]);

        $response = $this->actingAs($this->requester, 'user')
            ->postJson("/api/products/{$product->id}/request-price-change", [
                'product_name' => $product->name,
                'current_price' => 100,
                'proposed_price' => 130,
                'reason' => 'Snapshot role-map test',
            ]);

        $response->assertStatus(201);

        $priceChange = PriceChangeRequest::query()->latest('id')->firstOrFail();
        $approval = Approval::findOrFail($priceChange->approval_id);
        $this->assertSame(['1' => 'finance', '2' => 'shop_owner', '3' => 'finance'], $approval->approval_roles);

        $settingsJson['approval_pages']['price_approval']['enabled'] = false;
        $settings->update(['settings_json' => $settingsJson]);

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Live setting changed after submission',
            ])
            ->assertStatus(200)
            ->assertJsonPath('is_final', false)
            ->assertJsonPath('approval_level', 2);

        $priceChange->refresh();
        $this->assertSame(2, $priceChange->current_approval_level);
        $this->assertSame('finance_approved', $priceChange->status->value ?? $priceChange->status);
    }

    public function test_product_price_workflow_uses_finance_only_map_when_owner_approval_is_disabled(): void
    {
        $product = Product::create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'name' => 'Finance Only Product ' . random_int(1000, 9999),
            'slug' => 'finance-only-product-' . uniqid(),
            'description' => 'Finance-only price test product',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $settings = ProcurementSettings::getForShopOwner($this->shopOwnerAuth->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['price_approval']['enabled'] = false;
        $settings->update(['settings_json' => $settingsJson]);

        $response = $this->actingAs($this->requester, 'user')
            ->postJson("/api/products/{$product->id}/request-price-change", [
                'product_name' => $product->name,
                'current_price' => 100,
                'proposed_price' => 130,
                'reason' => 'Finance-only role-map test',
            ]);

        $response->assertStatus(201);

        $priceChange = PriceChangeRequest::query()->latest('id')->firstOrFail();
        $approval = Approval::findOrFail($priceChange->approval_id);
        $this->assertSame(['1' => 'finance'], $approval->approval_roles);

        $this->actingAs($this->shopOwnerAuth, 'shop_owner')
            ->postJson("/api/shop-owner/price-changes/{$priceChange->id}/approve")
            ->assertStatus(400);

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Finance-only final approval',
            ])
            ->assertStatus(200)
            ->assertJsonPath('is_final', true);

        $this->assertSame('130.00', (string) $product->fresh()->price);
        $this->assertDatabaseMissing('notifications', [
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'title' => 'Price Change Finalized by Finance',
        ]);
    }

    public function test_finance_reconciles_a_pending_legacy_price_change_using_the_current_owner_setting(): void
    {
        $product = Product::create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'name' => 'Legacy Price Workflow Product ' . random_int(1000, 9999),
            'slug' => 'legacy-price-workflow-product-' . uniqid(),
            'description' => 'Legacy pricing workflow regression product',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $settings = ProcurementSettings::getForShopOwner($this->shopOwnerAuth->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['price_approval']['enabled'] = false;
        $settings->update(['settings_json' => $settingsJson]);
        $priceChange = PriceChangeRequest::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 130,
            'reason' => 'Recover missing legacy workflow metadata.',
            'requested_by' => $this->requester->id,
            'status' => 'pending',
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'approval_id' => null,
            'current_approval_level' => null,
        ]);

        $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Reconciled missing workflow metadata.',
            ])
            ->assertOk()
            ->assertJsonPath('is_final', true);

        $approval = Approval::findOrFail($priceChange->fresh()->approval_id);
        $this->assertSame(['1' => 'finance'], $approval->approval_roles);
        $this->assertSame('v4_multi_level', $priceChange->fresh()->approval_workflow_version);
        $this->assertSame('130.00', (string) $product->fresh()->price);
    }

    private function createWorkflowBoundPriceChange(): PriceChangeRequest
    {
        $product = Product::create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'name' => 'Workflow Test Product ' . random_int(1000, 9999),
            'slug' => 'workflow-test-product-' . uniqid(),
            'description' => 'Price change workflow feature test',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $priceChange = PriceChangeRequest::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 130,
            'reason' => 'Supplier increase and margin adjustment',
            'requested_by' => $this->requester->id,
            'status' => 'pending',
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);

        $this->priceChangeApprovalService->createPriceChangeApproval(
            $priceChange,
            $this->shopOwnerApproverUser,
            $this->requester
        );

        return $priceChange->fresh();
    }
}
