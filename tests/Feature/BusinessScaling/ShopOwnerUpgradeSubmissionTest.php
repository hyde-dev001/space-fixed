<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShopOwnerUpgradeSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_approved_owner_can_submit_a_combined_upgrade_with_private_immutable_evidence(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $response = $this->actingAs($owner, 'shop_owner')->post(
            route('shop-owner.upgrade-requests.store'),
            $this->uploadPayload(),
            ['Accept' => 'application/json'],
        );

        $response->assertCreated()->assertJsonPath('request.status', 'pending');
        $request = ShopOwnerUpgradeRequest::query()->with('documents')->firstOrFail();

        $this->assertSame('individual', $request->current_registration_type);
        $this->assertSame('retail', $request->current_business_type);
        $this->assertSame('company', $request->requested_registration_type);
        $this->assertSame('both', $request->requested_business_type);
        $this->assertCount(4, $request->documents);

        foreach ($request->documents as $document) {
            Storage::disk('local')->assertExists($document->path);
            $this->assertArrayNotHasKey('path', $document->toArray());
            $this->assertSame(hash('sha256', Storage::disk('local')->get($document->path)), $document->checksum_sha256);
        }
    }

    public function test_approved_documents_can_be_reused_as_private_snapshots_without_touching_sources(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $sourcePath = 'shop_documents/approved-dti.pdf';
        Storage::disk('public')->put($sourcePath, 'approved-source-bytes');
        $source = ShopDocument::create([
            'shop_owner_id' => $owner->id,
            'document_type' => 'dti_registration',
            'file_path' => $sourcePath,
            'status' => 'approved',
        ]);

        $payload = $this->uploadPayload();
        unset($payload['documents']['dti_registration']);
        $payload['reuse_document_ids'] = ['dti_registration' => $source->id];

        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $payload, ['Accept' => 'application/json'])
            ->assertCreated();

        $snapshot = ShopOwnerUpgradeRequest::firstOrFail()->documents()->where('document_type', 'dti_registration')->firstOrFail();
        Storage::disk('local')->assertExists($snapshot->path);
        $this->assertSame('approved-source-bytes', Storage::disk('local')->get($snapshot->path));
        $this->assertSame('approved-source-bytes', Storage::disk('public')->get($sourcePath));
    }

    public function test_noop_invalid_transition_missing_evidence_and_duplicate_pending_requests_are_rejected(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $noOp = $this->uploadPayload([
            'requested_registration_type' => 'individual',
            'requested_business_type' => 'retail',
        ]);
        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $noOp, ['Accept' => 'application/json'])
            ->assertStatus(422);

        $invalid = $this->uploadPayload(['requested_business_type' => 'repair']);
        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $invalid, ['Accept' => 'application/json'])
            ->assertStatus(422);

        $missing = $this->uploadPayload();
        unset($missing['documents']['valid_id']);
        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $missing, ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $this->uploadPayload(), ['Accept' => 'application/json'])
            ->assertCreated();
        $this->actingAs($owner, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $this->uploadPayload(), ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(1, ShopOwnerUpgradeRequest::query()->count());
    }

    public function test_suspended_or_pending_owners_cannot_submit_upgrade_requests(): void
    {
        foreach (['suspended', 'pending'] as $status) {
            $owner = ShopOwner::factory()->create([
                'status' => $status,
                'registration_type' => 'individual',
                'business_type' => 'retail',
            ]);

            $this->actingAs($owner, 'shop_owner')
                ->post(route('shop-owner.upgrade-requests.store'), $this->uploadPayload(), ['Accept' => 'application/json'])
                ->assertForbidden();
        }
    }

    public function test_account_only_and_capability_only_transitions_are_allowed_without_forcing_both_changes(): void
    {
        $accountOnly = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $this->actingAs($accountOnly, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $this->uploadPayload([
                'requested_registration_type' => 'company',
                'requested_business_type' => 'retail',
            ]), ['Accept' => 'application/json'])
            ->assertCreated();

        $capabilityOnly = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $this->actingAs($capabilityOnly, 'shop_owner')
            ->post(route('shop-owner.upgrade-requests.store'), $this->uploadPayload([
                'requested_registration_type' => 'individual',
                'requested_business_type' => 'both',
            ]), ['Accept' => 'application/json'])
            ->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function uploadPayload(array $overrides = []): array
    {
        return array_merge([
            'requested_registration_type' => 'company',
            'requested_business_type' => 'both',
            'documents' => [
                'dti_registration' => UploadedFile::fake()->createWithContent('dti_registration.pdf', 'dti-bytes'),
                'mayors_permit' => UploadedFile::fake()->createWithContent('mayors_permit.pdf', 'permit-bytes'),
                'bir_certificate' => UploadedFile::fake()->createWithContent('bir_certificate.pdf', 'bir-bytes'),
                'valid_id' => UploadedFile::fake()->createWithContent('valid_id.pdf', 'id-bytes'),
            ],
        ], $overrides);
    }
}
