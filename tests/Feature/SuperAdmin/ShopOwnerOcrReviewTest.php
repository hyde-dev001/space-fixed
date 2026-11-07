<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\AdminPage;
use App\Models\AdminPagePermission;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\ShopOwnerDocumentScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class ShopOwnerOcrReviewTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_pending_registration_shows_only_current_required_candidates_in_the_aggregate(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = ShopOwner::factory()->pending()->create();
        $this->document($owner, 'business_registration', 'dti_registration', 1, 0, 'approved', true);
        $this->document($owner, 'business_registration', 'dti_registration', 2, 100, 'pending', null);
        $this->document($owner, 'mayors_permit', 'mayors_permit', 1, 100);
        $this->document($owner, 'bir_certificate', 'bir_certificate', 1, null);
        $this->document($owner, 'valid_id', 'valid_id', 1, 100);
        $supporting = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'supporting_document',
            'logical_slot' => 'supporting_document:legacy:1',
            'version_number' => 1,
            'file_path' => 'shop_documents/supporting.png',
            'disk' => 'local',
            'status' => 'pending',
        ]);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.registrations.index'))
            ->assertOk();
        $registration = collect($response->inertiaProps('registrations.data'))->firstWhere('id', $owner->id);

        self::assertSame('assessment_incomplete', $registration['ocrScreeningSummary']['state']);
        self::assertNull($registration['ocrScreeningSummary']['score']);
        self::assertTrue($registration['ocrScreeningSummary']['incomplete']);
        self::assertFalse($registration['ocrScreeningSummary']['historical']);

        $documents = collect($registration['documents']);
        $predecessorId = $owner->documents()
            ->where('logical_slot', 'business_registration')
            ->where('version_number', 1)
            ->firstOrFail()
            ->id;
        $candidateId = $owner->documents()
            ->where('logical_slot', 'business_registration')
            ->where('version_number', 2)
            ->firstOrFail()
            ->id;
        self::assertSame(0, $documents->firstWhere('id', $predecessorId)['ocrScreening']['score']);
        self::assertTrue($documents->firstWhere('id', $predecessorId)['ocrScreening']['historical']);
        self::assertSame(100, $documents->firstWhere('id', $candidateId)['ocrScreening']['score']);
        self::assertSame('not_assessed', $documents->firstWhere('logicalSlot', 'bir_certificate')['ocrScreening']['state']);
        self::assertNull($documents->firstWhere('id', $supporting->id)['ocrScreening']);
        $encoded = json_encode($registration, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('context_hash', $encoded);
        self::assertStringNotContainsString('file_checksum_sha256', $encoded);
        self::assertStringNotContainsString('checksum_sha256', $encoded);
        self::assertStringNotContainsString('file_path', $encoded);
        self::assertStringNotContainsString('ocr_screening', $encoded);
    }

    public function test_changed_registration_context_is_projected_as_needs_rescan_and_excluded_from_score(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = ShopOwner::factory()->pending()->create();
        $document = $this->document($owner, 'business_registration', 'dti_registration', 1, 100);
        $owner->forceFill(['business_name' => 'A changed business identity'])->save();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.registrations.index'))
            ->assertOk();
        $registration = collect($response->inertiaProps('registrations.data'))->firstWhere('id', $owner->id);
        $projected = collect($registration['documents'])->firstWhere('id', $document->id)['ocrScreening'];

        self::assertSame('needs_rescan', $projected['state']);
        self::assertNull($projected['score']);
        self::assertSame('assessment_incomplete', $registration['ocrScreeningSummary']['state']);
        self::assertNull($registration['ocrScreeningSummary']['score']);
    }

    public function test_completed_registration_shows_the_latest_screening_as_historical(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = ShopOwner::factory()->approved()->create();
        $old = $this->document($owner, 'business_registration', 'dti_registration', 1, 0, 'approved', false);
        $new = $this->document($owner, 'business_registration', 'dti_registration', 2, 100, 'approved', true);
        $this->document($owner, 'mayors_permit', 'mayors_permit', 1, 100, 'approved', true);
        $this->document($owner, 'bir_certificate', 'bir_certificate', 1, 100, 'approved', true);
        $this->document($owner, 'valid_id', 'valid_id', 1, 100, 'approved', true);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.registrations.index'))
            ->assertOk();
        $registration = collect($response->inertiaProps('registrations.data'))->firstWhere('id', $owner->id);
        $documents = collect($registration['documents']);

        self::assertSame(100, $registration['ocrScreeningSummary']['score']);
        self::assertTrue($registration['ocrScreeningSummary']['historical']);
        self::assertTrue($documents->firstWhere('id', $new->id)['ocrScreening']['historical']);
        self::assertSame(100, $documents->firstWhere('id', $new->id)['ocrScreening']['score']);
        self::assertTrue($documents->firstWhere('id', $old->id)['ocrScreening']['historical']);
    }

    private function document(
        ShopOwner $owner,
        string $slot,
        string $type,
        int $version,
        ?int $score,
        string $status = 'pending',
        ?bool $isCurrent = null,
    ): ShopDocument {
        $metadata = [
            'logical_slot' => $slot,
            'document_type' => $type,
            'issued_on' => '2026-01-01',
            'expiration_mode' => $slot === 'mayors_permit' ? 'dated' : 'none',
            'expires_on' => $slot === 'mayors_permit' ? '2030-01-01' : null,
        ];
        $checksum = hash('sha256', $owner->id.':'.$slot.':'.$version);
        $document = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => $type,
            'logical_slot' => $slot,
            'version_number' => $version,
            'file_path' => "shop_documents/{$owner->id}-{$slot}-{$version}.png",
            'disk' => 'local',
            'status' => $status,
            'is_current' => $isCurrent,
            'issued_on' => $metadata['issued_on'],
            'expiration_mode' => $metadata['expiration_mode'],
            'expires_on' => $metadata['expires_on'],
            'checksum_sha256' => $checksum,
        ]);

        if ($score !== null) {
            $document->forceFill([
                'ocr_screening' => app(ShopOwnerDocumentScreeningService::class)->createReport(
                    $owner,
                    $metadata,
                    $checksum,
                    $this->entry($slot, $type, $score),
                ),
            ])->save();
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private function entry(string $slot, string $type, int $score): array
    {
        $keys = match ($type) {
            'dti_registration' => ['document_type', 'business_identity', 'proprietor_identity', 'dates'],
            'sec_registration' => ['document_type', 'business_identity', 'dates'],
            'mayors_permit' => ['document_type', 'business_identity', 'business_address', 'dates'],
            'bir_certificate' => ['document_type', 'business_identity', 'business_address', 'dates'],
            default => ['document_type', 'owner_identity', 'dates'],
        };
        $checks = [];
        foreach ($keys as $key) {
            $checks[$key] = [
                'result' => $score === 50 && $key === 'dates'
                    ? 'uncertain'
                    : ($score === 0 && $key === 'business_identity' ? 'mismatch' : 'match'),
                'confidence' => 0.96,
                'complete_value' => true,
                'field_associated' => true,
                'unambiguous' => true,
                'independent_type_signals' => $key === 'document_type',
            ];
        }

        $entry = [
            'assessment_state' => 'assessed',
            'detected_family' => match ($type) {
                'dti_registration', 'sec_registration', 'mayors_permit', 'bir_certificate' => $type,
                default => 'national_id',
            },
            'reference_day' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
            'recognition_confidence' => 0.94,
            'checks' => $checks,
            'reason_codes' => $score === 0 ? ['business_identity_conflict'] : [],
        ];
        if ($type === 'bir_certificate') {
            $entry['relationship'] = 'corporate';
        }

        return $entry;
    }
}
