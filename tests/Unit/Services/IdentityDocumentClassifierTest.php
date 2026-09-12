<?php

namespace Tests\Unit\Services;

use App\Models\IdentityVerification;
use App\Services\IdentityDocumentClassifier;
use Tests\TestCase;

final class IdentityDocumentClassifierTest extends TestCase
{
    public function test_a_bounded_passed_envelope_maps_to_screening_passed(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'drivers_license'),
                'back' => $this->side('back', 'drivers_license'),
            ],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('screening_passed', $decision['outcome']);
        $this->assertSame(IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED, $decision['screening_status']);
        $this->assertSame(IdentityVerification::REVIEW_PENDING, $decision['review_status']);
        $this->assertSame('drivers_license', $decision['document_type']);
    }

    public function test_a_rejected_side_maps_to_reject_upload_without_manual_review(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'national_id',
            [
                'front' => [
                    ...$this->side('front', 'national_id'),
                    'outcome' => 'reject_upload',
                ],
                'back' => $this->side('back', 'national_id'),
            ],
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame(IdentityVerification::SCREENING_REJECTED, $decision['screening_status']);
        $this->assertSame(IdentityVerification::REVIEW_NOT_REQUIRED, $decision['review_status']);
        $this->assertNotSame(IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED, $decision['screening_status']);
    }

    public function test_screening_error_is_retryable_and_does_not_become_a_review_status(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'passport',
            [
                'biodata' => [
                    ...$this->side('biodata', 'passport'),
                    'outcome' => 'screening_error',
                ],
            ],
        );

        $this->assertSame('screening_error', $decision['outcome']);
        $this->assertNull($decision['screening_status']);
        $this->assertNull($decision['review_status']);
    }

    public function test_umid_accepts_front_only_and_rejects_an_unexpected_back(): void
    {
        $frontOnly = app(IdentityDocumentClassifier::class)->classifySubmission(
            'umid',
            ['front' => $this->side('front', 'umid')],
            'none',
            'physical_card',
            true,
        );
        $withBack = app(IdentityDocumentClassifier::class)->classifySubmission(
            'umid',
            [
                'front' => $this->side('front', 'umid'),
                'back' => $this->side('back', 'umid'),
            ],
        );

        $this->assertSame('screening_passed', $frontOnly['outcome']);
        $this->assertSame('reject_upload', $withBack['outcome']);
        $this->assertSame('unexpected_side', $withBack['failure_reason']);
        $this->assertSame('back', $withBack['failure_side']);
    }

    public function test_passport_accepts_only_the_biodata_slot(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'passport',
            [
                'biodata' => $this->side('biodata', 'passport'),
            ],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('screening_passed', $decision['outcome']);
        $this->assertArrayNotHasKey('back', $decision['side_results']);
    }

    public function test_passport_with_a_card_back_is_rejected(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'passport',
            [
                'biodata' => $this->side('biodata', 'passport'),
                'back' => $this->side('back', 'passport'),
            ],
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame('unexpected_side', $decision['failure_reason']);
        $this->assertSame('back', $decision['failure_side']);
    }

    public function test_digital_national_id_requires_front_and_back_screens(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'national_id',
            [
                'front' => $this->side('front', 'national_id'),
                'back' => $this->side('back', 'national_id'),
            ],
            'none',
            'digital_image',
            true,
        );

        $this->assertSame('screening_passed', $decision['outcome']);
        $this->assertSame('digital_image', $decision['national_id_format']);
        $this->assertArrayHasKey('back', $decision['side_results']);
    }

    public function test_paper_national_id_format_is_rejected(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'national_id',
            [
                'front' => $this->side('front', 'national_id'),
            ],
            'none',
            'paper_document',
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame('invalid_screening_metadata', $decision['failure_reason']);
    }

    public function test_client_duplicate_declaration_is_rejected(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'drivers_license'),
                'back' => $this->side('back', 'drivers_license'),
            ],
            duplicateKind: 'near',
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame('duplicate_sides', $decision['failure_reason']);
        $this->assertSame(IdentityVerification::REVIEW_NOT_REQUIRED, $decision['review_status']);
    }

    public function test_unknown_or_oversized_metadata_is_rejected_without_semantic_ocr(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'national_id',
            [
                'front' => [
                    ...$this->side('front', 'national_id'),
                    'unknown' => str_repeat('x', 100),
                ],
                'back' => $this->side('back', 'national_id'),
            ],
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame('side_rejected', $decision['failure_reason']);
    }

    public function test_a_passed_document_without_a_positive_name_match_is_queued_for_manual_review(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'umid',
            ['front' => $this->side('front', 'umid')],
            'none',
            'physical_card',
            false,
        );

        $this->assertSame('manual_review_required', $decision['outcome']);
        $this->assertSame(IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED, $decision['screening_status']);
        $this->assertSame(IdentityVerification::REVIEW_PENDING, $decision['review_status']);
        $this->assertSame('name_unreadable_or_mismatch', $decision['failure_reason']);
    }

    public function test_an_uncertain_side_is_queued_for_manual_review(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'drivers_license', 'uncertain', []),
                'back' => $this->side('back', 'drivers_license'),
            ],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('manual_review_required', $decision['outcome']);
        $this->assertSame(IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED, $decision['screening_status']);
        $this->assertSame(IdentityVerification::REVIEW_PENDING, $decision['review_status']);
        $this->assertSame('uncertain_screening', $decision['failure_reason']);
        $this->assertSame('front', $decision['failure_side']);
    }

    public function test_semantic_family_conflict_is_queued_for_manual_review(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'umid'),
                'back' => $this->side('back', 'drivers_license'),
            ],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('manual_review_required', $decision['outcome']);
        $this->assertSame('document_family_conflict', $decision['failure_reason']);
        $this->assertSame('front', $decision['failure_side']);
    }

    public function test_low_confidence_manual_review_identifies_the_actual_side(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'drivers_license'),
                'back' => $this->side('back', 'drivers_license', 'plausible', ['document_family'], 'low'),
            ],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('manual_review_required', $decision['outcome']);
        $this->assertSame('low_ocr_confidence', $decision['failure_reason']);
        $this->assertSame('back', $decision['failure_side']);
    }

    /**
     * @return array<string, mixed>
     */
    private function side(
        string $side,
        string $family,
        string $outcome = 'plausible',
        array $anchors = ['document_family'],
        string $confidenceBand = 'high',
    ): array
    {
        return [
            'side' => $side,
            'outcome' => $outcome,
            'detected_document_family' => $family,
            'detected_anchor_keys' => $anchors,
            'confidence_band' => $confidenceBand,
            'qr_detected' => false,
            'fingerprint' => 'fp-'.$side.'-'.$family,
        ];
    }
}
