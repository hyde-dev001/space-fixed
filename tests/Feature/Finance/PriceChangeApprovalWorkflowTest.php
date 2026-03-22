<?php

namespace Tests\Feature\Finance;

use App\Models\Approval;
use App\Models\PriceChangeRequest;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\PriceChangeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
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
    private User $financeFinal;
    private PriceChangeApprovalService $priceChangeApprovalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('approve-expenses', 'user');
        Role::findOrCreate('finance', 'user');
        Role::findOrCreate('shop-owner', 'user');
        Role::findOrCreate('Finance Manager', 'user');

        $this->shopOwnerAuth = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        // The owner approval endpoint maps shop_owner guard id to User::find($shopOwner->id)
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

        $this->financeFinal = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeFinal->assignRole('Finance Manager');
        $this->financeFinal->givePermissionTo('approve-expenses');

        $this->priceChangeApprovalService = app(PriceChangeApprovalService::class);
    }

    public function test_price_change_approval_progresses_through_all_four_levels(): void
    {
        $priceChange = $this->createWorkflowBoundPriceChange();

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

        // Level 3: Finance
        $thirdApproval = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Secondary finance review approved',
            ]);

        $thirdApproval->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_final' => false,
                'approval_level' => 4,
            ]);

        // Level 4: Finance final
        $finalApproval = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/price-changes/{$priceChange->id}/approve", [
                'notes' => 'Final finance approval complete',
            ]);

        $finalApproval->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_final' => true,
                'approval_level' => 4,
            ]);

        $priceChange->refresh();
        $this->assertSame(4, $priceChange->current_approval_level);
        $this->assertSame('owner_approved', $priceChange->status->value ?? $priceChange->status);

        $this->assertDatabaseHas('approvals', [
            'id' => $priceChange->approval_id,
            'status' => 'approved',
            'current_level' => 4,
            'current_approver_role' => 'finance_final',
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
