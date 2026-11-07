<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Enums\AdminPage;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\AdminPagePermission;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\ShopDocumentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class ShopOwnerOcrDecisionIsolationTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    #[DataProvider('screeningStates')]
    public function test_explicit_approval_remains_available_at_every_score_or_without_screening(string $screening): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = $this->registrationWithDocuments($screening);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $this->approvalPayload($owner));

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('applied', true);
        DB::commit();

        self::assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'approved']);
        self::assertSame(4, ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('status', 'approved')
            ->where('is_current', true)
            ->count());
        Queue::assertPushed(
            SendPrivilegedWorkflowMail::class,
            fn (SendPrivilegedWorkflowMail $job): bool => $job->deliveryType === PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED,
        );
    }

    #[DataProvider('screeningStates')]
    public function test_explicit_rejection_remains_available_at_every_score_or_without_screening(string $screening): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = $this->registrationWithDocuments($screening);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner), [
                'rejection_reason' => 'The original documents require further review.',
            ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('applied', true);
        DB::commit();

        self::assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'rejected']);
        self::assertSame(4, ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('status', 'rejected')
            ->count());
        Queue::assertPushed(
            SendPrivilegedWorkflowMail::class,
            fn (SendPrivilegedWorkflowMail $job): bool => $job->deliveryType === PrivilegedDeliveryType::SHOP_REGISTRATION_REJECTED,
        );
    }

    public function test_report_cannot_replace_the_existing_viewed_document_or_reviewer_metadata_requirements(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = $this->registrationWithDocuments('zero');
        $payload = $this->approvalPayload($owner);
        $payload['documents'][0]['viewed'] = false;
        $payload['ocr_screening'] = ['score' => 100, 'approval' => true];

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.0.viewed']);

        self::assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
        self::assertSame(0, ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('status', 'approved')
            ->count());

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.approve', $owner), ['documents' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
    }

    public function test_rejection_still_requires_its_existing_reason_at_zero_percent(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        AdminPagePermission::grant($admin, AdminPage::SHOP_MANAGEMENT);
        $owner = $this->registrationWithDocuments('zero');

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.registrations.reject', $owner), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);

        self::assertDatabaseHas('shop_owners', ['id' => $owner->id, 'status' => 'pending']);
    }

    public static function screeningStates(): array
    {
        return [
            '100 percent' => ['all_match'],
            '50 percent' => ['uncertain'],
            '0 percent' => ['zero'],
            'unable to assess' => ['unavailable'],
            'not assessed' => ['not_assessed'],
        ];
    }

    private function registrationWithDocuments(string $screening): ShopOwner
    {
        $owner = ShopOwner::factory()->pending()->create();
        $entries = [];
        $documents = [
            ['dti_registration', 'business_registration', 'none', null],
            ['mayors_permit', 'mayors_permit', 'dated', '2027-01-01'],
            ['bir_certificate', 'bir_certificate', 'none', null],
            ['valid_id', 'valid_id', 'none', null],
        ];

        foreach ($documents as $index => [$type, $slot, $expirationMode, $expiresOn]) {
            $entry = [
                'metadata' => [
                    'document_type' => $type,
                    'logical_slot' => $slot,
                    'issued_on' => '2026-01-01',
                    'expiration_mode' => $expirationMode,
                    'expires_on' => $expiresOn,
                ],
                'file' => UploadedFile::fake()->createWithContent("{$slot}.png", 'synthetic OCR decision test document'),
                'submission_key' => sprintf('11111111-1111-4111-8111-%012d', $index + 1),
            ];
            if ($screening !== 'not_assessed') {
                $entry['ocr_screening'] = $this->evidence($type, $screening, $index === 0);
            }
            $entries[] = $entry;
        }

        app(ShopDocumentLifecycleService::class)->createPendingVersions($owner, $entries);

        return $owner;
    }

    /** @return array<string, mixed> */
    private function evidence(string $type, string $screening, bool $firstDocument): array
    {
        if ($screening === 'unavailable') {
            return [
                'assessment_state' => 'unable_to_assess',
                'detected_family' => null,
                'reference_day' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
                'checks' => [],
                'reason_codes' => ['ocr_unavailable'],
            ];
        }

        $keys = match ($type) {
            'dti_registration' => ['document_type', 'business_identity', 'proprietor_identity', 'dates'],
            'mayors_permit' => ['document_type', 'business_identity', 'business_address', 'dates'],
            'bir_certificate' => ['document_type', 'business_identity', 'business_address', 'dates'],
            default => ['document_type', 'owner_identity', 'dates'],
        };
        $checks = [];
        foreach ($keys as $key) {
            $result = $screening === 'uncertain' && $firstDocument && $key === 'dates'
                ? 'uncertain'
                : ($screening === 'zero' && $firstDocument && $key === 'business_identity' ? 'mismatch' : 'match');
            $checks[$key] = [
                'result' => $result,
                'confidence' => 0.96,
                'complete_value' => true,
                'field_associated' => true,
                'unambiguous' => true,
                'independent_type_signals' => $key === 'document_type',
            ];
        }

        $entry = [
            'assessment_state' => 'assessed',
            'detected_family' => $type === 'valid_id' ? 'national_id' : $type,
            'reference_day' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
            'recognition_confidence' => 0.94,
            'checks' => $checks,
            'reason_codes' => $screening === 'zero' && $firstDocument ? ['business_identity_conflict'] : [],
        ];
        if ($type === 'bir_certificate') {
            $entry['relationship'] = 'corporate';
        }

        return $entry;
    }

    /** @return array{documents: list<array<string, mixed>>} */
    private function approvalPayload(ShopOwner $owner): array
    {
        return [
            'documents' => $owner->documents()->orderBy('id')->get()->map(static fn (ShopDocument $document): array => [
                'id' => (int) $document->getKey(),
                'document_type' => (string) $document->document_type,
                'logical_slot' => (string) $document->logical_slot,
                'version_number' => (int) $document->version_number,
                'issued_on' => $document->issued_on?->toDateString(),
                'expiration_mode' => (string) $document->expiration_mode,
                'expires_on' => $document->expires_on?->toDateString(),
                'viewed' => true,
            ])->all(),
        ];
    }
}
