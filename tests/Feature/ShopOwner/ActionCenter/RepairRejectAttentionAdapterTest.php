<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\RepairRejectAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RepairRejectAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_pending_repair_rejections_use_snapshot_and_tenant_scope(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $otherOwner = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
        $repairer = User::factory()->create(['shop_owner_id' => $owner->id]);

        $actionable = $this->createRepair($owner, $repairer, true);
        $this->createRepair($owner, $repairer, false);
        $this->createRepair($owner, $repairer, true, 'manager_reviewing');
        $otherRepairer = User::factory()->create(['shop_owner_id' => $otherOwner->id]);
        $this->createRepair($otherOwner, $otherRepairer, true);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('repair_rejection:'.$actionable->id.':repair_rejection_approval', $item->attentionKey);
        $this->assertSame(1500.0, $item->comparableMonetaryExposure);
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=repair_rejection:'.$actionable->id,
            $item->destinationUrl,
        );
    }

    public function test_repair_rejection_projection_has_bounded_query_count(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $repairer = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->createRepair($owner, $repairer, true);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createRepair($owner, $repairer, true);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): RepairRejectAttentionAdapter
    {
        return app(RepairRejectAttentionAdapter::class);
    }

    private function createRepair(
        ShopOwner $owner,
        User $repairer,
        bool $requiresOwnerApproval,
        string $status = 'owner_approval_pending',
    ): RepairRequest {
        return RepairRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_name' => 'Action Center Customer',
            'total' => 1500,
            'status' => $status,
            'requires_owner_approval' => $requiresOwnerApproval,
            'repairer_rejected_by' => $repairer->id,
            'repairer_rejected_at' => now()->subHour(),
            'repairer_rejection_reason' => 'Repair cannot be completed safely.',
        ]);
    }
}
