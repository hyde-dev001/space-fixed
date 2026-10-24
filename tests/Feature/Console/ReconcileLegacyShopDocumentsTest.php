<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReconcileLegacyShopDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_dry_run_reports_safe_ids_and_does_not_change_legacy_rows(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reliable = $this->legacyDocument($owner, 'mayors_permit', 'local', 'legacy/permit.png', 'approved');
        $ambiguous = $this->legacyDocument($owner, 'dti_registration', 'local', 'legacy/dti-a.png', 'approved');
        $this->legacyDocument($owner, 'sec_registration', 'local', 'legacy/dti-b.png', 'approved');

        $this->assertSame(0, Artisan::call('shop-documents:reconcile-legacy', ['--chunk' => '100']));
        $output = Artisan::output();
        $this->assertStringContainsString('Mode: dry-run', $output);
        $this->assertStringContainsString('Shop owner '.$owner->id, $output);
        $this->assertStringContainsString('unresolved', $output);
        $this->assertStringContainsString((string) $ambiguous->id, $output);
        $this->assertStringNotContainsString('legacy/permit.png', $output);
        $this->assertStringNotContainsString((string) $owner->business_name, $output);
        $this->assertDatabaseHas('shop_documents', ['id' => $reliable->id, 'logical_slot' => null]);
        $this->assertDatabaseMissing('shop_documents', ['id' => $reliable->id, 'logical_slot' => 'mayors_permit']);
        $this->assertNull($reliable->fresh()->logical_slot);
        $this->assertNull($reliable->fresh()->version_number);
        $this->assertNull($reliable->fresh()->expiration_mode);
    }

    public function test_apply_assigns_deterministic_versions_and_current_markers_only_for_reliable_private_evidence(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $oldPermit = $this->legacyDocument($owner, 'mayors_permit', 'local', 'legacy/permit-old.png', 'approved', '2026-01-01 00:00:00');
        $rejectedPermit = $this->legacyDocument($owner, 'mayors_permit', 'local', 'legacy/permit-rejected.png', 'rejected', '2026-02-01 00:00:00');
        $business = $this->legacyDocument($owner, 'dti_registration', 'local', 'legacy/dti.png', 'approved');
        $supporting = $this->legacyDocument($owner, 'other_supporting_document', 'local', 'legacy/supporting.pdf', 'approved');

        $this->artisan('shop-documents:reconcile-legacy', ['--apply' => true])
            ->assertExitCode(0);

        $oldPermit = $oldPermit->fresh();
        $rejectedPermit = $rejectedPermit->fresh();
        $business = $business->fresh();
        $supporting = $supporting->fresh();

        $this->assertSame('mayors_permit', $oldPermit->logical_slot);
        $this->assertSame(1, $oldPermit->version_number);
        $this->assertTrue((bool) $oldPermit->is_current);
        $this->assertSame('unknown', $oldPermit->expiration_mode);
        $this->assertSame('mayors_permit', $rejectedPermit->logical_slot);
        $this->assertSame(2, $rejectedPermit->version_number);
        $this->assertNull($rejectedPermit->is_current);
        $this->assertSame($oldPermit->id, $rejectedPermit->predecessor_document_id);

        $this->assertSame('legacy_dti_sec_registration', $business->document_type);
        $this->assertSame('business_registration', $business->logical_slot);
        $this->assertSame(1, $business->version_number);
        $this->assertTrue((bool) $business->is_current);
        $this->assertSame('unknown', $business->expiration_mode);

        $this->assertSame('supporting_document:legacy:'.$supporting->id, $supporting->logical_slot);
        $this->assertSame(1, $supporting->version_number);
        $this->assertTrue((bool) $supporting->is_current);
        $this->assertNull($supporting->reviewed_by_super_admin_id);
        $this->assertNotSame($reviewer->id, $supporting->reviewed_by_super_admin_id);
    }

    public function test_apply_does_not_guess_duplicate_public_missing_or_unorderable_candidates_and_rerun_is_inert(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $duplicateA = $this->legacyDocument($owner, 'dti_registration', 'local', 'legacy/dti-a.png', 'approved', '2026-01-01 00:00:00');
        $duplicateB = $this->legacyDocument($owner, 'sec_registration', 'local', 'legacy/dti-b.png', 'approved', '2026-02-01 00:00:00');
        $public = $this->legacyDocument($owner, 'bir_certificate', 'public', 'legacy/bir.png', 'approved');
        Storage::disk('local')->delete('legacy/bir.png');
        $unorderableA = $this->legacyDocument($owner, 'valid_id', 'local', 'legacy/id-a.png', 'approved');
        $unorderableB = $this->legacyDocument($owner, 'valid_id', 'local', 'legacy/id-b.png', 'rejected');
        $unorderableA->updateQuietly(['created_at' => null]);
        $unorderableB->updateQuietly(['created_at' => null]);

        $this->artisan('shop-documents:reconcile-legacy', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertNull($duplicateA->fresh()->is_current);
        $this->assertNull($duplicateB->fresh()->is_current);
        $this->assertNull($public->fresh()->is_current);
        $this->assertNull($unorderableA->fresh()->logical_slot);
        $this->assertNull($unorderableB->fresh()->version_number);

        $snapshot = ShopDocument::query()->orderBy('id')->get()->map(fn (ShopDocument $document): array => [
            'id' => $document->id,
            'type' => $document->document_type,
            'slot' => $document->logical_slot,
            'version' => $document->version_number,
            'current' => $document->is_current,
            'expiration_mode' => $document->expiration_mode,
        ])->all();

        $this->artisan('shop-documents:reconcile-legacy', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame($snapshot, ShopDocument::query()->orderBy('id')->get()->map(fn (ShopDocument $document): array => [
            'id' => $document->id,
            'type' => $document->document_type,
            'slot' => $document->logical_slot,
            'version' => $document->version_number,
            'current' => $document->is_current,
            'expiration_mode' => $document->expiration_mode,
        ])->all());
    }

    public function test_owner_filter_and_chunk_are_bounded_and_invalid_options_fail_closed(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $document = $this->legacyDocument($owner, 'mayors_permit', 'local', 'legacy/owner-permit.png', 'approved');
        $otherDocument = $this->legacyDocument($otherOwner, 'mayors_permit', 'local', 'legacy/other-permit.png', 'approved');

        $this->artisan('shop-documents:reconcile-legacy', [
            '--apply' => true,
            '--shop-owner-id' => (string) $owner->id,
            '--chunk' => '99999',
        ])->assertExitCode(0);

        $this->assertNotNull($document->fresh()->logical_slot);
        $this->assertNull($otherDocument->fresh()->logical_slot);
        $this->artisan('shop-documents:reconcile-legacy', ['--shop-owner-id' => 'bad'])
            ->assertExitCode(1);
        $this->artisan('shop-documents:reconcile-legacy', ['--chunk' => '0'])
            ->assertExitCode(1);
    }

    private function legacyDocument(
        ShopOwner $owner,
        string $type,
        string $disk,
        string $path,
        string $status,
        ?string $createdAt = null,
    ): ShopDocument {
        if ($disk === 'local') {
            Storage::disk('local')->put($path, 'legacy-'.$type.'-'.$path);
        } else {
            Storage::disk('public')->put($path, 'legacy-'.$type.'-'.$path);
        }

        $document = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => $type,
            'file_path' => $path,
            'disk' => $disk,
            'status' => $status,
        ]);
        if ($createdAt !== null) {
            $document->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }

        return $document->fresh();
    }
}
