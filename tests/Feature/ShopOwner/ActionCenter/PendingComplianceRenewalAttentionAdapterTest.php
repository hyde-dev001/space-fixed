<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\OwnerActionCenter\Adapters\PendingComplianceRenewalAttentionAdapter;
use App\Services\ShopDocumentValidityService;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PendingComplianceRenewalAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.shop_timezone' => 'Asia/Manila']);
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 16, 0, 0, 0, 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_projects_only_material_pending_renewals_with_authoritative_urgency(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $pendingByDays = [];

        foreach ([31, 30, 8, 7, 1, 0, -1] as $daysRemaining) {
            $predecessor = $this->document($owner, $reviewer, 'slot_'.$daysRemaining, $daysRemaining);
            $pendingByDays[$daysRemaining] = $this->pendingRenewal($owner, $predecessor, 2);
        }

        $result = $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        );

        $this->assertSame(6, $result->qualifyingCount);
        $this->assertSame([
            $pendingByDays[-1]->id,
            $pendingByDays[0]->id,
            $pendingByDays[1]->id,
            $pendingByDays[7]->id,
            $pendingByDays[8]->id,
            $pendingByDays[30]->id,
        ], array_map(static fn ($item): int => $item->sourceId, $result->items));

        $byDays = [];
        foreach ($result->items as $item) {
            $byDays[array_search($item->sourceId, array_map(
                static fn (ShopDocument $document): int => $document->id,
                $pendingByDays,
            ), true)] = $item;
        }

        $this->assertSame('critical', $byDays[-1]->priorityTier);
        $this->assertSame('critical', $byDays[0]->materialityTier);
        $this->assertSame('high', $byDays[1]->priorityTier);
        $this->assertSame('high', $byDays[7]->materialityTier);
        $this->assertSame('normal', $byDays[8]->priorityTier);
        $this->assertSame('medium', $byDays[30]->materialityTier);
        $this->assertFalse($byDays[30]->ownerActionRequired);
        $this->assertSame('super_admin', $byDays[30]->waitingOn);
        $this->assertSame('/shop-owner/settings/policies-compliance', $byDays[30]->destinationUrl);
        $this->assertSame(
            'compliance_document:'.$pendingByDays[30]->id.':renewal_review_waiting',
            $byDays[30]->attentionKey,
        );
        $this->assertSame('2026-09-14T16:00:00.000000Z', $byDays[30]->urgencyAt);
    }

    public function test_actionable_since_is_the_later_of_window_opening_and_submission(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $predecessor = $this->document($owner, $reviewer, 'mayors_permit', 30);
        $pending = $this->pendingRenewal($owner, $predecessor, 2, [
            'created_at' => '2026-08-17 09:30:00',
        ]);

        $item = $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        )->items[0];

        $this->assertSame($pending->id, $item->sourceId);
        $this->assertSame('2026-08-17T09:30:00.000000Z', $item->actionableSince);
    }

    public function test_approved_rejected_and_withdrawn_successors_exit_waiting(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();

        foreach (['approved', 'rejected', 'withdrawn'] as $index => $status) {
            $predecessor = $this->document($owner, $reviewer, 'slot_'.$status, 7);
            $this->pendingRenewal($owner, $predecessor, $index + 2, [
                'status' => $status,
            ]);
        }

        $result = $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        );

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->qualifyingCount);
    }

    public function test_pending_reviewer_owned_renewal_is_not_an_owner_decision_item(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $predecessor = $this->document($owner, $reviewer, 'mayors_permit', 7);
        $pending = $this->pendingRenewal($owner, $predecessor, 2);

        $item = $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        )->items[0];

        $this->assertSame($pending->id, $item->sourceId);
        $this->assertSame('waiting_on_others', $item->primaryBucket);
        $this->assertSame('super_admin', $item->waitingOn);
        $this->assertFalse($item->ownerActionRequired);
    }

    public function test_multiple_pending_successors_fail_health_without_emitting_an_item(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $predecessor = $this->document($owner, $reviewer, 'mayors_permit', 7);
        $this->pendingRenewal($owner, $predecessor, 2);
        $this->pendingRenewal($owner, $predecessor, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compliance_document_lifecycle_inconsistent');

        $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        );
    }

    public function test_cross_tenant_pending_successor_fails_health_without_emitting_an_item(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $predecessor = $this->document($owner, $reviewer, 'mayors_permit', 7);
        $this->pendingRenewal($otherOwner, $predecessor, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compliance_document_lifecycle_inconsistent');

        $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        );
    }

    public function test_query_work_is_bounded_when_more_pending_renewals_are_present(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $this->documentWithPendingRenewal($owner, $reviewer, 'first', 7, 2);

        $oneCandidateQueries = $this->queryCountFor($owner);

        foreach (['second', 'third', 'fourth', 'fifth'] as $slot) {
            $this->documentWithPendingRenewal($owner, $reviewer, $slot, 7, 2);
        }

        $manyCandidateQueries = $this->queryCountFor($owner);

        $this->assertLessThanOrEqual($oneCandidateQueries + 1, $manyCandidateQueries);
    }

    private function adapter(): PendingComplianceRenewalAttentionAdapter
    {
        return app(PendingComplianceRenewalAttentionAdapter::class);
    }

    private function queryCountFor(ShopOwner $owner): int
    {
        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $this->adapter()->read(
            $owner,
            new OwnerAttentionQuery(bucket: 'waiting_on_others', coverage: 'compliance'),
        );

        return $queries;
    }

    private function documentWithPendingRenewal(
        ShopOwner $owner,
        SuperAdmin $reviewer,
        string $logicalSlot,
        int $daysRemaining,
        int $version,
    ): ShopDocument {
        $predecessor = $this->document($owner, $reviewer, $logicalSlot, $daysRemaining);
        $this->pendingRenewal($owner, $predecessor, $version);

        return $predecessor;
    }

    /** @param array<string, mixed> $overrides */
    private function document(
        ShopOwner $owner,
        SuperAdmin $reviewer,
        string $logicalSlot,
        int $daysRemaining,
        array $overrides = [],
    ): ShopDocument {
        return ShopDocument::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'document_type' => $logicalSlot,
            'logical_slot' => $logicalSlot,
            'version_number' => 1,
            'file_path' => 'compliance/'.$owner->id.'/'.$logicalSlot.'.pdf',
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'dated',
            'expires_on' => CarbonImmutable::now('Asia/Manila')->startOfDay()->addDays($daysRemaining)->toDateString(),
            'reviewed_by_super_admin_id' => $reviewer->id,
            'reviewed_at' => '2026-08-01 00:00:00',
            'checksum_sha256' => hash('sha256', $logicalSlot),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function pendingRenewal(
        ShopOwner $owner,
        ShopDocument $predecessor,
        int $version,
        array $overrides = [],
    ): ShopDocument {
        $document = ShopDocument::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'document_type' => $predecessor->document_type,
            'logical_slot' => $predecessor->logical_slot,
            'version_number' => $version,
            'predecessor_document_id' => $predecessor->id,
            'file_path' => 'compliance/'.$owner->id.'/renewal-'.$version.'.pdf',
            'disk' => 'local',
            'status' => 'pending',
            'is_current' => null,
            'expiration_mode' => 'dated',
            'expires_on' => '2027-08-16',
        ], $overrides));

        if (array_key_exists('created_at', $overrides)) {
            $document->created_at = $overrides['created_at'];
            $document->saveQuietly();
        }

        return $document->refresh();
    }
}
