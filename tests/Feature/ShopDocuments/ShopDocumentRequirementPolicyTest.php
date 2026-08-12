<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Services\ShopDocumentValidityService;
use App\Services\ShopOwnerDocumentRequirementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShopDocumentRequirementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_submission_requires_one_business_registration_and_fixed_metadata(): void
    {
        $requirements = app(ShopOwnerDocumentRequirementService::class);

        $valid = [
            [
                'document_type' => 'dti_registration',
                'logical_slot' => 'business_registration',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-01-01',
            ],
            [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-01-01',
            ],
            [
                'document_type' => 'bir_certificate',
                'logical_slot' => 'bir_certificate',
                'expiration_mode' => 'none',
                'expires_on' => null,
            ],
            [
                'document_type' => 'valid_id',
                'logical_slot' => 'valid_id',
                'expiration_mode' => 'none',
                'expires_on' => null,
            ],
        ];

        $this->assertSame([], $requirements->validateSubmission($valid));
        $this->assertSame(
            'supporting_document:legacy:42',
            $requirements->slotForType('supporting_document:legacy:42'),
        );
        $this->assertNull($requirements->slotForType('supporting_document:legacy:0'));

        $missingBusinessRegistration = $valid;
        array_shift($missingBusinessRegistration);
        $missingBusinessErrors = $requirements->validateSubmission($missingBusinessRegistration);
        $this->assertArrayHasKey('business_registration', $missingBusinessErrors);

        $bothBusinessRegistration = array_merge($valid, [[
            'document_type' => 'sec_registration',
            'logical_slot' => 'business_registration',
            'expiration_mode' => 'dated',
            'expires_on' => '2027-01-01',
        ]]);
        $this->assertArrayHasKey('business_registration', $requirements->validateSubmission($bothBusinessRegistration));
    }

    public function test_expiration_metadata_rejects_unknown_missing_dates_and_mayor_no_expiration(): void
    {
        $requirements = app(ShopOwnerDocumentRequirementService::class);

        $errors = $requirements->validateSubmission([
            [
                'document_type' => 'dti_registration',
                'logical_slot' => 'business_registration',
                'expiration_mode' => 'unknown',
            ],
            [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'expiration_mode' => 'none',
            ],
            [
                'document_type' => 'bir_certificate',
                'logical_slot' => 'bir_certificate',
                'expiration_mode' => 'dated',
            ],
            [
                'document_type' => 'valid_id',
                'logical_slot' => 'valid_id',
                'expiration_mode' => 'none',
                'expires_on' => '2027-01-01',
            ],
        ]);

        $this->assertArrayHasKey('business_registration', $errors);
        $this->assertArrayHasKey('mayors_permit', $errors);
        $this->assertArrayHasKey('bir_certificate', $errors);
        $this->assertArrayHasKey('valid_id', $errors);

        $invalidDateOrder = [
            [
                'document_type' => 'dti_registration',
                'logical_slot' => 'business_registration',
                'issued_on' => '2027-02-01',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-01-01',
            ],
            [
                'document_type' => 'mayors_permit',
                'logical_slot' => 'mayors_permit',
                'expiration_mode' => 'dated',
                'expires_on' => '2027-01-01',
            ],
            [
                'document_type' => 'bir_certificate',
                'logical_slot' => 'bir_certificate',
                'expiration_mode' => 'none',
            ],
            [
                'document_type' => 'valid_id',
                'logical_slot' => 'valid_id',
                'expiration_mode' => 'none',
            ],
        ];

        $this->assertArrayHasKey('business_registration', $requirements->validateSubmission($invalidDateOrder));
    }

    public function test_validity_is_derived_from_reviewer_verified_current_rows_and_local_calendar_boundaries(): void
    {
        $today = CarbonImmutable::create(2026, 8, 13, 0, 0, 0, 'Asia/Manila');
        $validity = app(ShopDocumentValidityService::class);

        $document = fn (array $attributes): ShopDocument => new ShopDocument(array_merge([
            'status' => 'approved',
            'is_current' => true,
            'reviewed_at' => $today,
            'reviewed_by_super_admin_id' => 1,
            'expiration_mode' => 'dated',
            'expires_on' => $today->addDays(31)->toDateString(),
        ], $attributes));

        $this->assertSame('valid', $validity->classify($document([]), $today));
        $this->assertSame('expiring_soon', $validity->classify($document([
            'expires_on' => $today->addDays(30)->toDateString(),
        ]), $today));
        $this->assertSame('expiring_soon', $validity->classify($document([
            'expires_on' => $today->toDateString(),
        ]), $today));
        $this->assertSame('expired', $validity->classify($document([
            'expires_on' => $today->subDay()->toDateString(),
        ]), $today));
        $this->assertSame('valid_no_expiration', $validity->classify($document([
            'expiration_mode' => 'none',
            'expires_on' => null,
        ]), $today));
        $this->assertSame('metadata_unverified', $validity->classify($document([
            'is_current' => false,
        ]), $today));
        $this->assertSame('metadata_unverified', $validity->classify($document([
            'expiration_mode' => 'unknown',
        ]), $today));
    }

    public function test_requirement_evaluation_does_not_mutate_shop_owner_status(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $requirements = app(ShopOwnerDocumentRequirementService::class);

        $requirements->evaluate(collect());

        $this->assertSame('approved', (string) $owner->fresh()->status->value);
    }
}
