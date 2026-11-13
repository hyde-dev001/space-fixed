<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Services\ShopDocumentLifecycleService;
use App\Services\ShopDocumentValidityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShopOwnerOcrPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_batch_persists_a_server_scored_private_report_on_the_immutable_version(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $entry = $this->dtiEntry();
        $version = $this->createDtiVersion($owner, '11111111-1111-4111-8111-111111111111', $entry);

        $report = $version->ocr_screening;

        self::assertSame(1, $report['schema_version']);
        self::assertSame('browser_tesseract', $report['source']);
        self::assertSame('assessed', $report['assessment_state']);
        self::assertSame(100, $report['score']);
        self::assertSame($entry['reference_day'], $report['reference_day']);
        self::assertSame(hash('sha256', 'synthetic registration document'), $version->checksum_sha256);
        self::assertSame($version->checksum_sha256, $report['file_checksum_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['context_hash']);
        self::assertArrayNotHasKey('ocr_text', $report);
        self::assertArrayNotHasKey('extracted_name', $report);
        self::assertArrayNotHasKey('checksum_sha256', $version->toArray());
        self::assertArrayNotHasKey('ocr_screening', $version->toArray());
        self::assertSame('pending', $version->status);
        self::assertSame(
            ShopDocumentValidityService::METADATA_UNVERIFIED,
            app(ShopDocumentValidityService::class)->classify($version),
        );
    }

    public function test_legacy_document_without_a_screening_report_remains_unassessed(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $legacy = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'file_path' => 'shop_documents/legacy-permit.png',
            'disk' => 'local',
            'status' => 'pending',
        ]);

        self::assertNull($legacy->fresh()->ocr_screening);
        self::assertDatabaseHas('shop_documents', [
            'id' => $legacy->id,
            'ocr_screening' => null,
        ]);
    }

    public function test_replay_cannot_overwrite_the_first_report_with_changed_browser_evidence(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $key = '22222222-2222-4222-8222-222222222222';
        $original = $this->createDtiVersion($owner, $key, $this->dtiEntry());
        $changed = $this->dtiEntry();
        $changed['detected_family'] = 'sec_registration';

        $replay = $this->createDtiVersion($owner, $key, $changed);

        self::assertSame($original->id, $replay->id);
        self::assertSame(100, $replay->ocr_screening['score']);
        self::assertSame(1, ShopDocument::query()->where('shop_owner_id', $owner->id)->count());

        $ownerWithoutReport = ShopOwner::factory()->approved()->create();
        $unassessed = $this->createDtiVersion(
            $ownerWithoutReport,
            'abababab-abab-4bab-8bab-abababababab',
            null,
        );
        $attemptedUpgrade = $this->createDtiVersion(
            $ownerWithoutReport,
            'abababab-abab-4bab-8bab-abababababab',
            $this->dtiEntry(),
        );

        self::assertSame($unassessed->id, $attemptedUpgrade->id);
        self::assertNull($attemptedUpgrade->ocr_screening);
    }

    public function test_temporal_checks_require_the_server_reference_day_and_keep_stale_conflicts(): void
    {
        $withoutDay = $this->dtiEntry();
        unset($withoutDay['reference_day']);
        $withoutDay['checks']['dates']['result'] = 'mismatch';
        $owner = ShopOwner::factory()->approved()->create();
        $missingDayReport = $this->createDtiVersion(
            $owner,
            '88888888-8888-4888-8888-888888888888',
            $withoutDay,
        )->ocr_screening;

        $staleDay = $this->dtiEntry();
        $staleDay['reference_day'] = now(config('app.shop_timezone', 'Asia/Manila'))->subDay()->toDateString();
        $staleDay['checks']['dates']['result'] = 'mismatch';
        $ownerWithConflict = ShopOwner::factory()->approved()->create();
        $staleConflictReport = $this->createDtiVersion(
            $ownerWithConflict,
            '99999999-9999-4999-8999-999999999999',
            $staleDay,
        )->ocr_screening;

        $stalePass = $this->dtiEntry();
        $stalePass['reference_day'] = now(config('app.shop_timezone', 'Asia/Manila'))->subDay()->toDateString();
        $ownerWithStalePass = ShopOwner::factory()->approved()->create();
        $stalePassReport = $this->createDtiVersion(
            $ownerWithStalePass,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            $stalePass,
        )->ocr_screening;

        self::assertSame(50, $missingDayReport['score']);
        self::assertSame('uncertain', $missingDayReport['checks']['dates']['result']);
        self::assertSame(0, $staleConflictReport['score']);
        self::assertSame('mismatch', $staleConflictReport['checks']['dates']['result']);
        self::assertSame(50, $stalePassReport['score']);
        self::assertSame('uncertain', $stalePassReport['checks']['dates']['result']);
    }

    public function test_retained_file_inherits_only_a_report_bound_to_the_same_context(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $metadata = $this->dtiMetadata();
        $first = $this->createDtiVersion($owner, '33333333-3333-4333-8333-333333333333', $this->dtiEntry(), $metadata);
        $first->forceFill(['status' => 'rejected'])->save();
        $owner->forceFill([
            'email' => 'different-contact@solespaceph.com',
            'phone' => '09170000000',
        ])->save();

        $second = $this->createDtiVersion(
            $owner->fresh(),
            '44444444-4444-4444-8444-444444444444',
            null,
            $metadata,
            $first->fresh(),
        );

        self::assertSame($first->file_path, $second->file_path);
        self::assertSame(100, $second->ocr_screening['score']);
        self::assertSame($first->checksum_sha256, $second->checksum_sha256);

        $second->forceFill(['status' => 'rejected'])->save();
        $owner->forceFill(['business_name' => 'Changed business identity'])->save();
        $third = $this->createDtiVersion(
            $owner->fresh(),
            '55555555-5555-4555-8555-555555555555',
            null,
            $metadata,
            $second->fresh(),
        );

        self::assertNull($third->ocr_screening);

        $replacementOwner = ShopOwner::factory()->approved()->create();
        $reportedPredecessor = $this->createDtiVersion(
            $replacementOwner,
            'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd',
            $this->dtiEntry(),
        );
        $reportedPredecessor->forceFill(['status' => 'rejected'])->save();
        $replacement = $this->createDtiVersion(
            $replacementOwner,
            'dededede-dede-4ede-8ede-dededededede',
            null,
            $metadata,
            $reportedPredecessor->fresh(),
            true,
        );

        self::assertNotSame($reportedPredecessor->file_path, $replacement->file_path);
        self::assertNotSame($reportedPredecessor->checksum_sha256, $replacement->checksum_sha256);
        self::assertNull($replacement->ocr_screening);
    }

    public function test_comparison_context_tracks_only_fields_relevant_to_each_document(): void
    {
        $owner = ShopOwner::factory()->make();
        $screening = app(\App\Services\ShopOwnerDocumentScreeningService::class);
        $idMetadata = [
            'logical_slot' => 'valid_id',
            'document_type' => 'valid_id',
            'issued_on' => null,
            'expiration_mode' => 'none',
            'expires_on' => null,
        ];
        $idHash = $screening->contextHash($owner, $idMetadata);
        $owner->business_address = 'A different shop address';
        $owner->shop_address = 'A different display address';
        self::assertSame($idHash, $screening->contextHash($owner, $idMetadata));
        $owner->first_name = 'Changed owner';
        self::assertNotSame($idHash, $screening->contextHash($owner, $idMetadata));

        $secMetadata = [
            'logical_slot' => 'business_registration',
            'document_type' => 'sec_registration',
            'issued_on' => null,
            'expiration_mode' => 'none',
            'expires_on' => null,
        ];
        $secHash = $screening->contextHash($owner, $secMetadata);
        $owner->first_name = 'Another representative';
        self::assertSame($secHash, $screening->contextHash($owner, $secMetadata));
        $owner->business_name = 'Changed registered business';
        self::assertNotSame($secHash, $screening->contextHash($owner, $secMetadata));
    }

    public function test_batch_rollback_removes_staged_files_and_does_not_leave_a_report(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $metadata = $this->dtiMetadata();
        $entry = [
            'metadata' => $metadata,
            'file' => UploadedFile::fake()->createWithContent('first.png', 'synthetic registration document'),
            'submission_key' => '66666666-6666-4666-8666-666666666666',
            'ocr_screening' => [
                'assessment_state' => 'unable_to_assess',
                'detected_family' => null,
                'reference_day' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
                'checks' => [],
                'reason_codes' => ['ocr_unavailable'],
            ],
        ];
        $duplicate = [
            ...$entry,
            'file' => UploadedFile::fake()->createWithContent('second.png', 'second synthetic document'),
            'submission_key' => '77777777-7777-4777-8777-777777777777',
        ];

        try {
            app(ShopDocumentLifecycleService::class)->createPendingVersions($owner, [$entry, $duplicate]);
            self::fail('The duplicate slot should fail and roll back the batch.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(0, ShopDocument::query()->where('shop_owner_id', $owner->id)->count());
            self::assertSame([], Storage::disk('local')->allFiles());
        }
    }

    private function createDtiVersion(
        ShopOwner $owner,
        string $key,
        ?array $screening,
        ?array $metadata = null,
        ?ShopDocument $predecessor = null,
        bool $replaceFile = false,
    ): ShopDocument {
        $file = $predecessor === null || $replaceFile
            ? UploadedFile::fake()->createWithContent(
                'dti.png',
                $replaceFile ? 'replacement registration document' : 'synthetic registration document',
            )
            : null;
        $entries = [[
            'metadata' => $metadata ?? $this->dtiMetadata(),
            'file' => $file,
            'predecessor' => $predecessor,
            'submission_key' => $key,
        ]];
        if ($screening !== null) {
            $entries[0]['ocr_screening'] = $screening;
        }

        return app(ShopDocumentLifecycleService::class)
            ->createPendingVersions($owner, $entries, $predecessor !== null)
            ->sole();
    }

    private function dtiMetadata(): array
    {
        return [
            'document_type' => 'dti_registration',
            'logical_slot' => 'business_registration',
            'issued_on' => '2026-01-01',
            'expiration_mode' => 'none',
            'expires_on' => null,
        ];
    }

    private function dtiEntry(): array
    {
        $fixtures = json_decode(
            file_get_contents(base_path('tests/Fixtures/ShopOwnerOcr/screening-contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $entry = $fixtures['cases']['dti100']['entry'];
        $entry['reference_day'] = now(config('app.shop_timezone', 'Asia/Manila'))->toDateString();

        return $entry;
    }
}
