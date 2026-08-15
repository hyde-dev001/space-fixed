<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\PurchaseRequestAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseRequestAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_queue_requires_same_shop_and_pending_shop_owner_state(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);

        $actionable = $this->createRequest($owner, $requester);
        $this->createRequest($otherOwner, User::factory()->create(['shop_owner_id' => $otherOwner->id]));
        $this->createRequest($owner, $requester, ['status' => 'pending_finance']);
        $this->createRequest($owner, $requester, ['status' => 'rejected']);
        $this->createRequest($owner, $requester, ['status' => 'approved']);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('purchase_request:'.$actionable->id.':purchase_request_approval', $item->attentionKey);
        $this->assertSame('procurement', $item->module);
        $this->assertSame((float) $actionable->total_cost, $item->comparableMonetaryExposure);
        $this->assertStringContainsString('purchase_request='.$actionable->id, $item->destinationUrl);
    }

    public function test_individual_shop_owner_has_no_purchase_request_decision_coverage(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->createRequest($owner, $requester);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame(0, $result->qualifyingCount);
        $this->assertSame([], $result->items);
    }

    public function test_projection_uses_quantity_times_unit_cost_when_persisted_total_is_zero(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $request = $this->createRequest($owner, $requester, [
            'quantity' => 3,
            'unit_cost' => 250,
            'total_cost' => 0,
        ]);
        $before = [
            'status' => $request->status,
            'quantity' => $request->quantity,
            'unit_cost' => (string) $request->unit_cost,
            'total_cost' => (string) $request->total_cost,
            'updated_at' => $request->updated_at?->toISOString(),
        ];

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame(750.0, $result->items[0]->comparableMonetaryExposure);
        $fresh = $request->fresh();
        $this->assertSame($before['status'], $fresh->status);
        $this->assertSame($before['quantity'], $fresh->quantity);
        $this->assertSame($before['unit_cost'], (string) $fresh->unit_cost);
        $this->assertSame($before['total_cost'], (string) $fresh->total_cost);
        $this->assertSame($before['updated_at'], $fresh->updated_at?->toISOString());
    }

    public function test_read_query_count_does_not_grow_with_qualifying_rows(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->createRequest($owner, $requester);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createRequest($owner, $requester);
        $this->createRequest($owner, $requester);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): PurchaseRequestAttentionAdapter
    {
        return app(PurchaseRequestAttentionAdapter::class);
    }

    private function createRequest(ShopOwner $owner, User $requester, array $overrides = []): PurchaseRequest
    {
        return PurchaseRequest::factory()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'quantity' => 2,
            'unit_cost' => 500,
            'total_cost' => 1000,
            'priority' => 'high',
            'status' => 'pending_shop_owner',
            'requested_date' => now()->subDay(),
        ], $overrides));
    }
}
