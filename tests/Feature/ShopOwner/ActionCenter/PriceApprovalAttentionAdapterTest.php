<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\PriceChangeRequest;
use App\Models\Product;
use App\Models\RepairPackage;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\PriceApprovalAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PriceApprovalAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_pending_product_and_repair_price_changes_are_tenant_scoped_and_snapshot_scoped(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $otherOwner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);

        $product = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Action Center Product',
            'slug' => 'action-center-product-'.uniqid(),
            'description' => 'Product price projection',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $productChange = PriceChangeRequest::create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 140,
            'reason' => 'Product price projection',
            'requested_by' => $requester->id,
            'status' => 'finance_approved',
            'shop_owner_id' => $owner->id,
        ]);
        $this->createApproval($productChange, $owner, $requester, 'shop_owner');

        $service = RepairService::create([
            'name' => 'Repair Price Projection',
            'category' => 'Restoration',
            'price' => 1200,
            'old_price' => 1000,
            'duration' => '2 days',
            'description' => 'Repair price projection',
            'change_reason' => 'Repair price projection',
            'status' => 'Pending Owner Approval',
            'shop_owner_id' => $owner->id,
            'created_by' => $requester->id,
            'updated_by' => $requester->id,
            'finance_notes' => '1250',
            'approval_workflow_version' => 'repair_finance_owner_finance',
        ]);

        $this->createProductChangeWithApproval($otherOwner, 'finance_approved', 'shop_owner');
        $this->createProductChangeWithApproval($owner, 'finance_approved', 'finance');
        $this->createRepairPackage($owner, 'finance_approved', 'repair_finance_only');

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(2, $result->qualifyingCount);
        $this->assertSame([
            'product_price_change:'.$productChange->id.':price_approval',
            'repair_price_change:'.$service->id.':price_approval',
        ], array_map(static fn ($item): string => $item->attentionKey, $result->items));
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=product_price_change:'.$productChange->id,
            collect($result->items)->firstWhere('sourceType', 'product_price_change')->destinationUrl,
        );
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=repair_price_change:'.$service->id,
            collect($result->items)->firstWhere('sourceType', 'repair_price_change')->destinationUrl,
        );
    }

    public function test_product_price_projection_has_bounded_query_count(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $change = $this->createProductChangeWithApproval($owner, 'finance_approved', 'shop_owner', [
            'requested_by' => $requester->id,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createProductChangeWithApproval($owner, 'finance_approved', 'shop_owner', [
            'requested_by' => $requester->id,
        ]);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
        $this->assertNotNull($change);
    }

    public function test_product_price_projection_matches_linked_shop_owner_user_approval(): void
    {
        User::factory()->count(2)->create();
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $shopOwnerUser = User::factory()->create([
            'shop_owner_id' => $owner->id,
            'role' => 'Shop Owner',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $change = $this->createProductChangeWithApproval(
            $owner,
            'finance_approved',
            'shop_owner',
            ['requested_by' => $requester->id],
            (int) $shopOwnerUser->id,
        );

        $this->assertNotSame((int) $owner->id, (int) $shopOwnerUser->id);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertSame('product_price_change:'.$change->id.':price_approval', $result->items[0]->attentionKey);
    }

    private function adapter(): PriceApprovalAttentionAdapter
    {
        return app(PriceApprovalAttentionAdapter::class);
    }

    private function createApproval(
        object $approvable,
        ShopOwner $owner,
        User $requester,
        string $role,
        ?int $approvalOwnerId = null,
    ): Approval {
        $approval = Approval::create([
            'shop_owner_id' => $approvalOwnerId ?? $owner->id,
            'approvable_type' => $approvable::class,
            'approvable_id' => $approvable->id,
            'reference' => 'AC-'.uniqid(),
            'description' => 'Action Center approval',
            'amount' => 40,
            'requested_by' => $requester->id,
            'current_level' => $role === 'shop_owner' ? 2 : 1,
            'total_levels' => $role === 'shop_owner' ? 3 : 3,
            'status' => ApprovalStatus::PENDING,
            'approval_roles' => ['1' => 'finance', '2' => 'shop_owner', '3' => 'finance'],
            'current_approver_role' => $role,
        ]);
        $approvable->update([
            'approval_id' => $approval->id,
            'current_approval_level' => $approval->current_level,
        ]);

        return $approval;
    }

    private function createProductChangeWithApproval(
        ShopOwner $owner,
        string $status,
        string $role,
        array $overrides = [],
        ?int $approvalOwnerId = null,
    ): PriceChangeRequest {
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $product = Product::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Product '.uniqid(),
            'slug' => 'product-'.uniqid(),
            'description' => 'Projection product',
            'price' => 100,
            'category' => 'shoes',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $change = PriceChangeRequest::create(array_merge([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'current_price' => 100,
            'proposed_price' => 120,
            'reason' => 'Projection test',
            'requested_by' => $requester->id,
            'status' => $status,
            'shop_owner_id' => $owner->id,
        ], $overrides));
        $this->createApproval($change, $owner, $requester, $role, $approvalOwnerId);

        return $change->fresh();
    }

    private function createRepairPackage(ShopOwner $owner, string $approvalStatus, string $workflow): RepairPackage
    {
        return RepairPackage::create([
            'shop_owner_id' => $owner->id,
            'name' => 'Repair package '.uniqid(),
            'description' => 'Projection package',
            'package_price' => 2500,
            'old_package_price' => 2200,
            'change_reason' => 'Projection package',
            'status' => 'active',
            'approval_status' => $approvalStatus,
            'approval_workflow_version' => $workflow,
        ]);
    }
}
