<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\ShopDocumentLifecycleService;
use App\Services\ShopDocumentValidityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShopDocumentInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_pending_and_rejected_versions_never_overwrite_the_current_approved_history(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $path = 'shop_documents/'.$owner->id.'/current-permit.png';
        Storage::disk('local')->put($path, 'original-document');
        $current = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'issued_on' => '2026-01-01',
            'expiration_mode' => 'dated',
            'expires_on' => '2026-12-31',
            'reviewed_by_super_admin_id' => $reviewer->id,
            'reviewed_at' => '2026-01-02 00:00:00',
        ]);
        $before = $current->fresh()->getAttributes();

        $lifecycle = app(ShopDocumentLifecycleService::class);
        $pending = $lifecycle->createPendingVersion(
            $owner,
            [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'issued_on' => '2026-08-13',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-08-13',
            ],
            UploadedFile::fake()->create('renewal.png', 10, 'image/png'),
            $current,
        );
        $lifecycle->rejectPendingVersion($pending, $reviewer->id, 'Unreadable file.');

        $this->assertSame($before, $current->fresh()->getAttributes());
        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->is_current);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame('approved', $owner->fresh()->status->value);
    }

    public function test_dti_to_sec_promotion_keeps_one_current_business_registration(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $reviewer = SuperAdmin::factory()->admin()->create();
        $path = 'shop_documents/'.$owner->id.'/current-dti.png';
        Storage::disk('local')->put($path, 'dti-document');
        $current = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'dti_registration',
            'logical_slot' => 'business_registration',
            'version_number' => 1,
            'file_path' => $path,
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
            'expiration_mode' => 'none',
            'reviewed_by_super_admin_id' => $reviewer->id,
            'reviewed_at' => '2026-01-02 00:00:00',
        ]);
        $pending = app(ShopDocumentLifecycleService::class)->createPendingVersion(
            $owner,
            [
                'document_type' => 'sec_registration',
                'logical_slot' => 'business_registration',
                'expiration_mode' => 'none',
                'expires_on' => null,
            ],
            UploadedFile::fake()->create('renewal.png', 10, 'image/png'),
            $current,
        );

        $approved = app(ShopDocumentLifecycleService::class)->approvePendingVersion(
            $pending,
            $reviewer->id,
            [
                'document_type' => 'sec_registration',
                'logical_slot' => 'business_registration',
                'version_number' => 2,
                'issued_on' => null,
                'expiration_mode' => 'none',
                'expires_on' => null,
            ],
        );

        $currentRows = ShopDocument::query()
            ->where('shop_owner_id', $owner->id)
            ->where('logical_slot', 'business_registration')
            ->where('is_current', true)
            ->get();

        $this->assertCount(1, $currentRows);
        $this->assertSame($approved->id, $currentRows->first()->id);
        $this->assertSame('sec_registration', $approved->document_type);
        $this->assertSame('approved', $approved->status);
        $this->assertFalse((bool) $current->fresh()->is_current);
    }

    public function test_database_rejects_a_second_current_version_for_one_owner_and_logical_slot(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 1,
            'file_path' => 'invariant/first.png',
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
        ]);

        $this->expectException(QueryException::class);
        ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'mayors_permit',
            'logical_slot' => 'mayors_permit',
            'version_number' => 2,
            'file_path' => 'invariant/second.png',
            'disk' => 'local',
            'status' => 'approved',
            'is_current' => true,
        ]);
    }

    public function test_expired_validity_is_derived_without_changing_shop_status(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $document = new ShopDocument([
            'status' => 'approved',
            'is_current' => true,
            'reviewed_by_super_admin_id' => 1,
            'reviewed_at' => '2026-01-01 00:00:00',
            'expiration_mode' => 'dated',
            'expires_on' => '2026-08-12',
        ]);

        $this->assertSame(
            'expired',
            app(ShopDocumentValidityService::class)->classify(
                $document,
                CarbonImmutable::create(2026, 8, 13, 0, 0, 0, 'Asia/Manila'),
            ),
        );
        $this->assertSame('approved', $owner->fresh()->status->value);
    }
}
