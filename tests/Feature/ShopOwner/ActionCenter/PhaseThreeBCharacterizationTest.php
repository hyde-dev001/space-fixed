<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\ShopDocumentValidityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PhaseThreeBCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_validity_is_derived_from_current_reviewer_verified_metadata(): void
    {
        $today = CarbonImmutable::create(2026, 8, 16, 0, 0, 0, 'Asia/Manila');
        $validity = app(ShopDocumentValidityService::class);

        $expiring = $this->document([
            'expires_on' => '2026-09-15',
        ]);
        $nonCurrent = $this->document([
            'is_current' => false,
            'expires_on' => '2026-09-15',
        ]);
        $unapproved = $this->document([
            'status' => 'pending',
            'is_current' => false,
            'reviewed_by_super_admin_id' => null,
            'reviewed_at' => null,
            'expires_on' => '2026-09-15',
        ]);
        $nonExpiring = $this->document([
            'expiration_mode' => 'none',
            'expires_on' => null,
        ]);
        $malformedDated = $this->document([
            'expiration_mode' => 'dated',
            'expires_on' => null,
        ]);

        $this->assertSame(ShopDocumentValidityService::EXPIRING_SOON, $validity->classify($expiring, $today));
        $this->assertSame(ShopDocumentValidityService::METADATA_UNVERIFIED, $validity->classify($nonCurrent, $today));
        $this->assertSame(ShopDocumentValidityService::METADATA_UNVERIFIED, $validity->classify($unapproved, $today));
        $this->assertSame(ShopDocumentValidityService::VALID_NO_EXPIRATION, $validity->classify($nonExpiring, $today));
        $this->assertSame(ShopDocumentValidityService::METADATA_UNVERIFIED, $validity->classify($malformedDated, $today));
    }

    public function test_reminder_candidates_are_current_approved_verified_and_dated(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();

        $candidate = $this->persistDocument($owner, $reviewer, 'mayors_permit', [
            'expires_on' => '2026-09-15',
        ]);
        $nonCurrent = $this->persistDocument($owner, $reviewer, 'bir_certificate', [
            'is_current' => false,
            'expires_on' => '2026-09-15',
        ]);
        $unapproved = $this->persistDocument($owner, $reviewer, 'valid_id', [
            'status' => 'pending',
            'is_current' => null,
            'reviewed_by_super_admin_id' => null,
            'reviewed_at' => null,
            'expires_on' => '2026-09-15',
        ]);
        $nonExpiring = $this->persistDocument($owner, $reviewer, 'business_registration', [
            'document_type' => 'sec_registration',
            'expiration_mode' => 'none',
            'expires_on' => null,
        ]);

        $candidateIds = ShopDocument::query()->datedReminderCandidates()->pluck('id')->all();

        $this->assertSame([$candidate->id], $candidateIds);
        $this->assertNotContains($nonCurrent->id, $candidateIds);
        $this->assertNotContains($unapproved->id, $candidateIds);
        $this->assertNotContains($nonExpiring->id, $candidateIds);
    }

    public function test_pending_renewal_scope_exposes_links_but_does_not_validate_cross_owner_responsibility(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $current = $this->persistDocument($owner, $reviewer, 'mayors_permit', [
            'expires_on' => '2026-09-15',
        ]);
        $validSuccessor = $this->persistPendingRenewal($owner, $current, 2);
        $contradictorySuccessor = $this->persistPendingRenewal($otherOwner, $current, 3);

        $pending = ShopDocument::query()->pendingRenewals()->orderBy('id')->get();

        $this->assertSame([$validSuccessor->id, $contradictorySuccessor->id], $pending->pluck('id')->all());
        $this->assertSame($owner->id, $validSuccessor->predecessor->shop_owner_id);
        $this->assertNotSame($contradictorySuccessor->shop_owner_id, $contradictorySuccessor->predecessor->shop_owner_id);
    }

    public function test_database_rejects_conflicting_current_documents_for_one_slot(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $this->persistDocument($owner, $reviewer, 'mayors_permit');

        $this->expectException(QueryException::class);
        $this->persistDocument($owner, $reviewer, 'mayors_permit', [
            'version_number' => 2,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function document(array $overrides = []): ShopDocument
    {
        return new ShopDocument(array_merge([
            'status' => 'approved',
            'is_current' => true,
            'reviewed_by_super_admin_id' => 1,
            'reviewed_at' => '2026-08-01 00:00:00',
            'expiration_mode' => 'dated',
            'expires_on' => '2027-08-16',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function persistDocument(
        ShopOwner $owner,
        SuperAdmin $reviewer,
        string $logicalSlot,
        array $overrides = [],
    ): ShopDocument {
        return ShopDocument::query()->create(array_merge([
            'shop_owner_id' => $owner->id,
            'document_type' => $logicalSlot,
            'logical_slot' => $logicalSlot,
            'version_number' => 1,
            'file_path' => 'characterization/'.$owner->id.'/'.$logicalSlot.'.pdf',
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'dated',
            'expires_on' => '2027-08-16',
            'reviewed_by_super_admin_id' => $reviewer->id,
            'reviewed_at' => '2026-08-01 00:00:00',
        ], $overrides));
    }

    private function persistPendingRenewal(ShopOwner $owner, ShopDocument $predecessor, int $version): ShopDocument
    {
        return ShopDocument::query()->create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => $version,
            'predecessor_document_id' => $predecessor->id,
            'file_path' => 'characterization/'.$owner->id.'/renewal-'.$version.'.pdf',
            'disk' => 'local',
            'status' => 'pending',
            'is_current' => null,
            'expiration_mode' => 'dated',
            'expires_on' => '2027-09-15',
        ]);
    }
}
