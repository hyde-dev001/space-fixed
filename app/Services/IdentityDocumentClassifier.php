<?php

namespace App\Services;

use App\Models\IdentityVerification;

final class IdentityDocumentClassifier
{
    /**
     * Validate the bounded browser admission envelope.
     *
     * Tesseract.js performs semantic document screening in the browser. This
     * adapter validates the shape and workflow consistency of that untrusted
     * result; it cannot repeat semantic OCR without a trusted server OCR
     * engine and never represents authenticity or identity approval.
     *
     * @param mixed $selectedDocumentType
     * @param mixed $sideResults
     * @return array<string, mixed>
     */
    public function classifySubmission(
        mixed $selectedDocumentType,
        mixed $sideResults,
        string $duplicateKind = 'none',
        string $nationalIdFormat = 'physical_card',
        bool $nameMatched = false,
        string $requestedOutcome = 'screening_passed',
    ): array {
        $documentType = is_string($selectedDocumentType) ? trim($selectedDocumentType) : '';
        $definitions = (array) config('identity_verification.documents', []);

        if ($documentType === '' || ! array_key_exists($documentType, $definitions)) {
            return $this->rejection('unsupported_document_type', null, $documentType !== '' ? $documentType : null);
        }

        if (! is_array($sideResults)) {
            return $this->rejection('invalid_screening_metadata', null, $documentType);
        }

        if (! in_array($nationalIdFormat, ['physical_card', 'digital_image'], true)
            || ($documentType !== 'national_id' && $nationalIdFormat !== 'physical_card')) {
            return $this->rejection('invalid_screening_metadata', null, $documentType);
        }

        if (! in_array($requestedOutcome, ['reject_upload', 'screening_passed', 'manual_review_required', 'screening_error'], true)) {
            return $this->rejection('invalid_screening_metadata', null, $documentType);
        }

        $requiredSlots = $this->requiredSlots(
            (array) $definitions[$documentType],
            $documentType,
            $nationalIdFormat,
        );
        $allowedSlots = ['front', 'back', 'biodata'];
        $unexpectedSlots = array_values(array_diff(array_map('strval', array_keys($sideResults)), $allowedSlots));

        if ($unexpectedSlots !== []) {
            return $this->rejection('unexpected_side', $unexpectedSlots[0], $documentType);
        }

        foreach ($requiredSlots as $slot) {
            if (! array_key_exists($slot, $sideResults)) {
                return $this->rejection('missing_required_side', $slot, $documentType);
            }
        }

        foreach (array_keys($sideResults) as $slot) {
            if (! in_array((string) $slot, $requiredSlots, true)) {
                return $this->rejection('unexpected_side', (string) $slot, $documentType);
            }
        }

        if (! in_array($duplicateKind, ['none', 'exact', 'near'], true)) {
            return $this->rejection('invalid_screening_metadata', null, $documentType);
        }

        if ($duplicateKind !== 'none') {
            return $this->rejection('duplicate_sides', 'back', $documentType, $this->sanitizeSideResults($sideResults));
        }

        $normalizedSides = [];
        $hasSemanticConflict = false;
        $hasUncertainSide = false;
        $attentionSide = null;
        foreach ($requiredSlots as $slot) {
            $result = $this->normalizeSideResult($slot, $sideResults[$slot], $documentType);

            if ($result['outcome'] === 'screening_error') {
                return [
                    'outcome' => 'screening_error',
                    'screening_status' => null,
                    'review_status' => null,
                    'document_type' => $documentType,
                    'national_id_format' => $nationalIdFormat,
                    'failure_reason' => 'screening_error',
                    'failure_side' => $slot,
                    'side_results' => $normalizedSides + [$slot => $result],
                ];
            }

            if ($result['outcome'] === 'reject_upload') {
                return $this->rejection(
                    'side_rejected',
                    $slot,
                    $documentType,
                    $normalizedSides + [$slot => $result],
                );
            }

            $detectedFamily = $result['detected_document_family'] ?? null;
            if (is_string($detectedFamily) && $detectedFamily !== $documentType) {
                $hasSemanticConflict = true;
                $attentionSide ??= $slot;
                $result['outcome'] = 'uncertain';
            }

            if (($result['outcome'] ?? null) === 'uncertain') {
                $hasUncertainSide = true;
                $attentionSide ??= $slot;
            }

            if (($result['confidence_band'] ?? null) === 'low') {
                $attentionSide ??= $slot;
            }

            $normalizedSides[$slot] = $result;
        }

        $families = array_values(array_unique(array_filter(array_map(
            static fn (array $side): ?string => $side['detected_document_family'],
            $normalizedSides,
        ))));

        if (count($families) > 1) {
            $hasSemanticConflict = true;
            $firstSide = array_key_first($normalizedSides);
            if (is_string($firstSide)) {
                $attentionSide ??= $firstSide;
            }
        }

        if (! $nameMatched
            || $hasSemanticConflict
            || $hasUncertainSide
            || $this->hasLowConfidenceSide($normalizedSides)
            || $requestedOutcome === 'manual_review_required') {
            $reason = 'low_ocr_confidence';
            if (! $nameMatched) {
                $reason = 'name_unreadable_or_mismatch';
            } elseif ($hasSemanticConflict) {
                $reason = 'document_family_conflict';
            } elseif ($hasUncertainSide) {
                $reason = 'uncertain_screening';
            } elseif ($requestedOutcome === 'manual_review_required') {
                $reason = 'manual_review_required';
            }

            return $this->manualReview(
                $reason,
                $attentionSide ?? ($documentType === 'passport' ? 'biodata' : 'front'),
                $documentType,
                $nationalIdFormat,
                $normalizedSides,
            );
        }

        return [
            'outcome' => 'screening_passed',
            'screening_status' => IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED,
            'review_status' => IdentityVerification::REVIEW_PENDING,
            'document_type' => $documentType,
            'national_id_format' => $nationalIdFormat,
            'ocr_confidence' => $this->confidenceForFront($normalizedSides),
            'classification_confidence' => null,
            'failure_reason' => null,
            'failure_side' => null,
            'side_results' => $normalizedSides,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, string>
     */
    private function requiredSlots(array $definition, string $documentType, string $nationalIdFormat): array
    {
        if ($documentType === 'national_id') {
            $formatDefinition = $definition['formats'][$nationalIdFormat] ?? null;
            $formatSlots = is_array($formatDefinition) ? $formatDefinition['required_slots'] ?? [] : [];
            $formatSlots = array_values(array_filter(
                is_array($formatSlots) ? $formatSlots : [],
                static fn (mixed $slot): bool => is_string($slot) && in_array($slot, ['front', 'back', 'biodata'], true),
            ));

            if ($formatSlots !== []) {
                return $formatSlots;
            }
        }

        $slots = array_values(array_filter(
            (array) ($definition['required_slots'] ?? []),
            static fn (mixed $slot): bool => is_string($slot) && in_array($slot, ['front', 'back', 'biodata'], true),
        ));

        if ($slots !== []) {
            return $slots;
        }

        return ($definition['requires_back'] ?? false) === true
            ? ['front', 'back']
            : ['biodata'];
    }

    /**
     * @param mixed $result
     * @return array<string, mixed>
     */
    private function normalizeSideResult(string $slot, mixed $result, string $documentType): array
    {
        if (! is_array($result)) {
            return [
                'side' => $slot,
                'outcome' => 'reject_upload',
                'detected_document_family' => null,
                'detected_anchor_keys' => [],
                'confidence_band' => null,
                'qr_detected' => false,
                'fingerprint' => null,
            ];
        }

        $allowedKeys = [
            'side',
            'outcome',
            'detected_document_family',
            'detected_anchor_keys',
            'confidence_band',
            'qr_detected',
            'fingerprint',
        ];

        if (array_diff(array_keys($result), $allowedKeys) !== []) {
            return $this->invalidSide($slot);
        }

        $outcome = $result['outcome'] ?? null;
        $family = $result['detected_document_family'] ?? null;
        $anchors = $result['detected_anchor_keys'] ?? null;
        $confidenceBand = $result['confidence_band'] ?? null;
        $qrDetected = $result['qr_detected'] ?? null;
        $fingerprint = $result['fingerprint'] ?? null;

        if (($result['side'] ?? null) !== $slot) {
            return $this->invalidSide($slot);
        }

        if (! is_string($outcome) || ! in_array($outcome, ['plausible', 'uncertain', 'reject_upload', 'screening_error'], true)) {
            return $this->invalidSide($slot);
        }

        if ($family !== null && (! is_string($family) || mb_strlen($family, 'UTF-8') > 64)) {
            return $this->invalidSide($slot);
        }

        if (! is_array($anchors) || count($anchors) > 24 || count(array_filter(
            $anchors,
            static fn (mixed $anchor): bool => ! is_string($anchor) || mb_strlen($anchor, 'UTF-8') > 64,
        )) > 0) {
            return $this->invalidSide($slot);
        }

        if ($confidenceBand !== null && ! in_array($confidenceBand, ['low', 'medium', 'high'], true)) {
            return $this->invalidSide($slot);
        }

        if (! is_bool($qrDetected)) {
            return $this->invalidSide($slot);
        }

        if ($fingerprint !== null && (! is_string($fingerprint) || mb_strlen($fingerprint, 'UTF-8') > 128)) {
            return $this->invalidSide($slot);
        }

        if ($outcome === 'plausible' && ($family === null || $anchors === [])) {
            return [
                'side' => $slot,
                'outcome' => 'uncertain',
                'detected_document_family' => is_string($family) ? $family : null,
                'detected_anchor_keys' => $anchors,
                'confidence_band' => $confidenceBand,
                'qr_detected' => $qrDetected,
                'fingerprint' => $fingerprint,
            ];
        }

        return [
            'side' => $slot,
            'outcome' => $outcome,
            'detected_document_family' => $family,
            'detected_anchor_keys' => array_values($anchors),
            'confidence_band' => $confidenceBand,
            'qr_detected' => $qrDetected,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidSide(string $slot): array
    {
        return [
            'side' => $slot,
            'outcome' => 'reject_upload',
            'detected_document_family' => null,
            'detected_anchor_keys' => [],
            'confidence_band' => null,
            'qr_detected' => false,
            'fingerprint' => null,
        ];
    }

    /**
     * @param mixed $sideResults
     * @return array<string, mixed>
     */
    private function sanitizeSideResults(mixed $sideResults): array
    {
        if (! is_array($sideResults)) {
            return [];
        }

        $safe = [];
        foreach (['front', 'back', 'biodata'] as $slot) {
            if (array_key_exists($slot, $sideResults)) {
                $safe[$slot] = is_array($sideResults[$slot])
                    ? array_intersect_key($sideResults[$slot], array_flip([
                        'side',
                        'outcome',
                        'detected_document_family',
                        'detected_anchor_keys',
                        'confidence_band',
                        'qr_detected',
                        'fingerprint',
                    ]))
                    : [];
            }
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $sideResults
     * @return array<string, mixed>
     */
    private function rejection(
        string $reason,
        ?string $side,
        ?string $documentType,
        array $sideResults = [],
    ): array {
        return [
            'outcome' => 'reject_upload',
            'screening_status' => IdentityVerification::SCREENING_REJECTED,
            'review_status' => IdentityVerification::REVIEW_NOT_REQUIRED,
            'document_type' => $documentType,
            'ocr_confidence' => null,
            'classification_confidence' => null,
            'failure_reason' => $reason,
            'failure_side' => $side,
            'side_results' => $sideResults,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $sideResults
     */
    private function confidenceForFront(array $sideResults): ?float
    {
        $band = $sideResults['front']['confidence_band'] ?? $sideResults['biodata']['confidence_band'] ?? null;

        return match ($band) {
            'high' => 1.0,
            'medium' => 0.75,
            'low' => 0.5,
            default => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $sideResults
     */
    private function hasLowConfidenceSide(array $sideResults): bool
    {
        foreach ($sideResults as $side) {
            if (($side['confidence_band'] ?? null) === 'low') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $sideResults
     * @return array<string, mixed>
     */
    private function manualReview(
        string $reason,
        ?string $side,
        string $documentType,
        string $nationalIdFormat,
        array $sideResults,
    ): array {
        return [
            'outcome' => 'manual_review_required',
            'screening_status' => IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED,
            'review_status' => IdentityVerification::REVIEW_PENDING,
            'document_type' => $documentType,
            'national_id_format' => $nationalIdFormat,
            'ocr_confidence' => $this->confidenceForFront($sideResults),
            'classification_confidence' => null,
            'failure_reason' => $reason,
            'failure_side' => $side,
            'side_results' => $sideResults,
        ];
    }
}
