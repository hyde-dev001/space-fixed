<?php

namespace Tests\Unit\Services;

use App\Services\IdentityDocumentClassifier;
use Tests\TestCase;

final class IdentityDocumentSubmissionClassifierTest extends TestCase
{
    public function test_random_image_result_is_rejected_without_a_manual_review_fallback(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'national_id',
            [
                'front' => $this->side('front', 'unknown', 'reject_upload'),
                'back' => $this->side('back', 'unknown', 'reject_upload'),
            ],
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame('rejected', $decision['screening_status']);
        $this->assertSame('not_required', $decision['review_status']);
    }

    public function test_foreign_driver_license_result_is_rejected_by_the_browser_admission_envelope(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'foreign_driver_license', 'reject_upload'),
                'back' => $this->side('back', 'foreign_driver_license', 'reject_upload'),
            ],
        );

        $this->assertSame('reject_upload', $decision['outcome']);
        $this->assertSame('rejected', $decision['screening_status']);
    }

    public function test_plausible_low_ocr_back_is_queued_for_manual_review_when_the_browser_marks_it_plausible(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'drivers_license',
            [
                'front' => $this->side('front', 'drivers_license', 'plausible', 'high'),
                'back' => $this->side('back', 'drivers_license', 'plausible', 'low'),
            ],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('manual_review_required', $decision['outcome']);
        $this->assertSame('low', $decision['side_results']['back']['confidence_band']);
        $this->assertSame('manual_review_required', $decision['screening_status']);
        $this->assertSame('pending', $decision['review_status']);
    }

    public function test_passport_submission_uses_only_biodata_and_no_back_requirement(): void
    {
        $decision = app(IdentityDocumentClassifier::class)->classifySubmission(
            'passport',
            ['biodata' => $this->side('biodata', 'passport')],
            'none',
            'physical_card',
            true,
        );

        $this->assertSame('screening_passed', $decision['outcome']);
        $this->assertSame('automated_check_passed', $decision['screening_status']);
        $this->assertSame('pending', $decision['review_status']);
        $this->assertArrayNotHasKey('back', $decision['side_results']);
    }

    /**
     * @return array<string, mixed>
     */
    private function side(
        string $side,
        string $family,
        string $outcome = 'plausible',
        string $confidenceBand = 'high',
    ): array {
        return [
            'side' => $side,
            'outcome' => $outcome,
            'detected_document_family' => $family,
            'detected_anchor_keys' => ['document_family'],
            'confidence_band' => $confidenceBand,
            'qr_detected' => false,
            'fingerprint' => 'fp-'.$side.'-'.$family,
        ];
    }
}
