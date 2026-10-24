<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\OwnerActionCenter\Adapters\ComplianceDocumentAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ComplianceDocumentAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.shop_timezone' => 'Asia/Manila']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 00:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_projects_only_material_current_documents_for_the_owner(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $renewal = $this->document($owner, $reviewer, 'mayors_permit', 30);
        $urgent = $this->document($owner, $reviewer, 'bir_certificate', 7);
        $today = $this->document($owner, $reviewer, 'valid_id', 0);
        $expired = $this->document($owner, $reviewer, 'supporting_document', -1);
        $this->document($owner, $reviewer, 'business_registration', 31, [
            'document_type' => 'sec_registration',
        ]);
        $this->document($otherOwner, $reviewer, 'mayors_permit', 7);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame(4, $result->qualifyingCount);
        $this->assertSame(
            [$expired->id, $today->id, $urgent->id, $renewal->id],
            array_map(static fn ($item): int => $item->sourceId, $result->items),
        );
        $byId = collect($result->items)->keyBy('sourceId');
        $this->assertSame(['critical', 'critical'], [
            $byId[$expired->id]->priorityTier,
            $byId[$expired->id]->materialityTier,
        ]);
        $this->assertSame(['high', 'high'], [
            $byId[$urgent->id]->priorityTier,
            $byId[$urgent->id]->materialityTier,
        ]);
        $this->assertSame(['normal', 'medium'], [
            $byId[$renewal->id]->priorityTier,
            $byId[$renewal->id]->materialityTier,
        ]);
    }

    public function test_projection_uses_safe_explicit_exception_metadata(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $document = $this->document($owner, $reviewer, 'mayors_permit', 5, [
            'reviewed_at' => '2026-08-15 00:00:00',
            'file_path' => 'private/never-serialize.pdf',
            'checksum_sha256' => hash('sha256', 'private'),
        ]);

        $item = $this->adapter()->read($owner, $this->query())->items[0];
        $serialized = $item->toArray();

        $this->assertSame('compliance_document:'.$document->id.':document_expiry', $item->attentionKey);
        $this->assertSame('compliance_document', $item->sourceType);
        $this->assertSame('compliance', $item->coverageSource);
        $this->assertSame('compliance_documents', $this->adapter()->adapterKey());
        $this->assertSame('document_expiry', $item->category);
        $this->assertSame('urgent_exceptions', $item->primaryBucket);
        $this->assertSame('none', $item->waitingOn);
        $this->assertFalse($item->ownerActionRequired);
        $this->assertSame('/shop-owner/settings/policies-compliance', $item->destinationUrl);
        $this->assertSame('2026-08-20T16:00:00.000000Z', $item->urgencyAt);
        $this->assertSame('2026-08-14T16:00:00.000000Z', $item->actionableSince);
        $this->assertStringNotContainsString('private', json_encode($serialized, JSON_THROW_ON_ERROR));
    }

    public function test_normal_lifecycle_exclusions_are_healthy_empty_results(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $current = $this->document($owner, $reviewer, 'mayors_permit', 7);
        $this->pendingRenewal($owner, $current, 2);
        $this->document($owner, $reviewer, 'bir_certificate', 7, [
            'is_current' => false,
        ]);
        $this->document($owner, $reviewer, 'valid_id', 7, [
            'status' => 'pending',
            'is_current' => null,
            'reviewed_by_super_admin_id' => null,
            'reviewed_at' => null,
        ]);
        $this->document($owner, $reviewer, 'supporting_document', 7, [
            'expiration_mode' => 'none',
            'expires_on' => null,
        ]);

        $result = $this->adapter()->read($owner, $this->query());

        $this->assertSame(0, $result->qualifyingCount);
        $this->assertSame([], $result->items);
    }

    public function test_contradictory_current_or_successor_metadata_fails_the_adapter(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $current = $this->document($owner, $reviewer, 'mayors_permit', 7);
        $this->pendingRenewal($otherOwner, $current, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compliance_document_lifecycle_inconsistent');

        $this->adapter()->read($owner, $this->query());
    }

    public function test_unverified_current_approved_metadata_fails_the_adapter(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $this->document($owner, $reviewer, 'mayors_permit', 7, [
            'reviewed_by_super_admin_id' => null,
            'reviewed_at' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compliance_document_lifecycle_inconsistent');

        $this->adapter()->read($owner, $this->query());
    }

    public function test_read_query_count_is_bounded_as_rows_grow(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $this->document($owner, $reviewer, 'mayors_permit', 7);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, $this->query());
        $oneRowQueries = count(DB::getQueryLog());

        $this->document($owner, $reviewer, 'bir_certificate', 5);
        $this->document($owner, $reviewer, 'valid_id', 3);
        DB::flushQueryLog();
        $this->adapter()->read($owner, $this->query());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): ComplianceDocumentAttentionAdapter
    {
        return app(ComplianceDocumentAttentionAdapter::class);
    }

    private function query(): OwnerAttentionQuery
    {
        return new OwnerAttentionQuery(bucket: 'urgent_exceptions', coverage: 'compliance');
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

    private function pendingRenewal(ShopOwner $owner, ShopDocument $predecessor, int $version): ShopDocument
    {
        return ShopDocument::query()->create([
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
        ]);
    }
}
