<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;
use JsonException;

final class ShopOwnerDocumentScreeningService
{
    private const MAX_ENVELOPE_BYTES = 32768;

    private const SLOTS = [
        'business_registration', 'mayors_permit', 'bir_certificate', 'valid_id',
    ];

    private const REQUIRED_CHECKS = [
        'dti_registration' => ['document_type', 'business_identity', 'proprietor_identity', 'dates'],
        'sec_registration' => ['document_type', 'business_identity', 'dates'],
        'mayors_permit' => ['document_type', 'business_identity', 'business_address', 'dates'],
        'bir_certificate' => ['document_type', 'business_identity', 'business_address', 'dates'],
        'valid_id' => ['document_type', 'owner_identity', 'dates'],
    ];

    private const FAMILIES = [
        'dti_registration', 'sec_registration', 'mayors_permit', 'bir_certificate',
        'national_id', 'drivers_license', 'passport', 'umid', 'other_document',
    ];

    private const ID_FAMILIES = ['national_id', 'drivers_license', 'passport', 'umid'];

    private const REASON_CODES = [
        'wrong_document_type', 'business_identity_conflict', 'owner_identity_conflict',
        'proprietor_identity_conflict', 'business_address_conflict', 'date_conflict',
        'uncertain_field', 'low_recognition_confidence', 'unsupported_layout',
        'ocr_unavailable', 'ocr_failed', 'ocr_timeout', 'scan_unfinished',
    ];

    private const ENTRY_KEYS = [
        'assessment_state', 'detected_family', 'relationship', 'reference_day',
        'recognition_confidence', 'checks', 'reason_codes',
    ];

    private const CHECK_KEYS = [
        'result', 'confidence', 'complete_value', 'field_associated',
        'unambiguous', 'independent_type_signals',
    ];

    /** @return array{score: int|null, state: string} */
    public function scoreEntry(string $slot, string $selectedDocumentType, array $entry): array
    {
        $this->assertValidEntry($slot, $selectedDocumentType, $entry);

        $required = self::REQUIRED_CHECKS[$selectedDocumentType];
        if ($selectedDocumentType === 'bir_certificate' && ($entry['relationship'] ?? null) === 'sole_proprietor') {
            $required[] = 'proprietor_identity';
        }

        $results = array_map(
            fn (string $key): string => $this->normalizedCheckResult($entry, $selectedDocumentType, $key),
            $required,
        );

        if ($entry['assessment_state'] === 'unable_to_assess') {
            if (array_intersect($results, ['match', 'mismatch']) !== []) {
                $this->invalid('Unable-to-assess evidence contains an eligible check.');
            }

            return ['score' => null, 'state' => 'unable_to_assess'];
        }

        if (in_array('mismatch', $results, true)) {
            return ['score' => 0, 'state' => 'assessed'];
        }
        if (count($results) === count(array_filter($results, static fn (string $result): bool => $result === 'match'))) {
            return ['score' => 100, 'state' => 'assessed'];
        }

        return ['score' => 50, 'state' => 'assessed'];
    }

    /**
     * Validate applicant-supplied JSON before persistence. The selected document type comes from
     * the ordinary registration fields and is deliberately not accepted from inside the envelope.
     *
     * @param array<string, string> $selectedDocumentTypes
     * @return array<string, array<string, mixed>>
     */
    public function validateEnvelope(string $raw, array $selectedDocumentTypes): array
    {
        if (strlen($raw) > self::MAX_ENVELOPE_BYTES) {
            $this->invalid('Screening evidence exceeds the allowed size.');
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid('Screening evidence must be valid JSON.');
        }

        if (! is_object($decoded)) {
            $this->invalid('Screening evidence must be a JSON object.');
        }

        $entries = get_object_vars($decoded);
        if (count($entries) > 4) {
            $this->invalid('Screening evidence contains too many documents.');
        }

        $validated = [];
        foreach ($entries as $slot => $rawEntry) {
            if (! in_array($slot, self::SLOTS, true) || ! is_object($rawEntry)) {
                $this->invalid('Screening evidence contains an invalid document entry.');
            }
            if (! isset($selectedDocumentTypes[$slot]) || ! is_string($selectedDocumentTypes[$slot])) {
                $this->invalid('Screening evidence does not match the submitted document selection.');
            }

            $entry = get_object_vars($rawEntry);
            if (! isset($entry['checks']) || ! is_object($entry['checks'])) {
                $this->invalid('Screening checks must be a JSON object.');
            }

            $checks = [];
            foreach (get_object_vars($entry['checks']) as $key => $rawCheck) {
                if (! is_object($rawCheck)) {
                    $this->invalid('A screening check is invalid.');
                }
                $checks[$key] = get_object_vars($rawCheck);
            }
            $entry['checks'] = $checks;

            $this->scoreEntry($slot, $selectedDocumentTypes[$slot], $entry);
            $validated[$slot] = $entry;
        }

        return $validated;
    }

    /** @param array<string, int|null> $scores */
    public function summarizeScores(array $scores): array
    {
        $values = [];
        foreach (self::SLOTS as $slot) {
            $score = $scores[$slot] ?? null;
            if ($score !== null && ! in_array($score, [0, 50, 100], true)) {
                $this->invalid('A document screening score is invalid.');
            }
            $values[] = $score;
        }

        $incomplete = in_array(null, $values, true);
        $score = in_array(0, $values, true) ? 0 : ($incomplete ? null : min($values));

        return [
            'incomplete' => $incomplete,
            'score' => $score,
            'state' => $incomplete ? 'assessment_incomplete' : 'assessed',
        ];
    }

    public function confidenceBand(mixed $confidence): string
    {
        if (! $this->isConfidence($confidence)) {
            $this->invalid('Screening confidence is invalid.');
        }

        return $confidence >= 0.85 ? 'high' : ($confidence >= 0.65 ? 'medium' : 'low');
    }

    private function assertValidEntry(string $slot, string $selectedDocumentType, array $entry): void
    {
        if (! in_array($slot, self::SLOTS, true) || ! isset(self::REQUIRED_CHECKS[$selectedDocumentType])) {
            $this->invalid('Screening evidence uses an invalid document selection.');
        }
        if (($slot === 'business_registration' && ! in_array($selectedDocumentType, ['dti_registration', 'sec_registration'], true))
            || ($slot !== 'business_registration' && $slot !== $selectedDocumentType)) {
            $this->invalid('Screening evidence does not match the submitted document selection.');
        }
        if (array_diff(array_keys($entry), self::ENTRY_KEYS) !== []) {
            $this->invalid('Screening evidence contains an unsupported field.');
        }
        if (! isset($entry['assessment_state']) || ! in_array($entry['assessment_state'], ['assessed', 'unable_to_assess'], true)) {
            $this->invalid('Screening evidence has an invalid assessment state.');
        }
        if (! array_key_exists('detected_family', $entry)
            || ($entry['detected_family'] !== null && ! in_array($entry['detected_family'], self::FAMILIES, true))) {
            $this->invalid('Screening evidence has an invalid detected document family.');
        }
        if (array_key_exists('reference_day', $entry) && ! $this->isCalendarDay($entry['reference_day'])) {
            $this->invalid('Screening evidence has an invalid reference day.');
        }
        if (array_key_exists('recognition_confidence', $entry) && ! $this->isConfidence($entry['recognition_confidence'])) {
            $this->invalid('Screening evidence has an invalid recognition confidence.');
        }

        if ($slot === 'bir_certificate') {
            if (array_key_exists('relationship', $entry) && ! in_array($entry['relationship'], ['sole_proprietor', 'corporate', 'unclear'], true)) {
                $this->invalid('Screening evidence has an invalid business relationship.');
            }
        } elseif (array_key_exists('relationship', $entry)) {
            $this->invalid('Screening evidence contains a relationship for an unrelated document.');
        }

        if (! isset($entry['checks']) || ! is_array($entry['checks'])) {
            $this->invalid('Screening checks must be an object.');
        }
        $allowedChecks = self::REQUIRED_CHECKS[$selectedDocumentType];
        if ($selectedDocumentType === 'bir_certificate' && ($entry['relationship'] ?? null) === 'sole_proprietor') {
            $allowedChecks[] = 'proprietor_identity';
        }
        if (array_diff(array_keys($entry['checks']), $allowedChecks) !== []) {
            $this->invalid('Screening evidence contains a check that does not apply.');
        }

        foreach ($entry['checks'] as $check) {
            if (! is_array($check) || array_diff(array_keys($check), self::CHECK_KEYS) !== []) {
                $this->invalid('A screening check contains an unsupported field.');
            }
            if (array_diff(self::CHECK_KEYS, array_keys($check)) !== []) {
                $this->invalid('A screening check is incomplete.');
            }
            if (! in_array($check['result'], ['match', 'mismatch', 'uncertain'], true)) {
                $this->invalid('A screening check has an invalid result.');
            }
            if ($check['confidence'] !== null && ! $this->isConfidence($check['confidence'])) {
                $this->invalid('A screening check has invalid confidence.');
            }
            foreach (array_slice(self::CHECK_KEYS, 2) as $flag) {
                if (! is_bool($check[$flag])) {
                    $this->invalid('A screening check has an invalid evidence flag.');
                }
            }
        }

        $reasons = array_key_exists('reason_codes', $entry) ? $entry['reason_codes'] : [];
        if (! is_array($reasons) || count($reasons) > 8
            || count(array_filter($reasons, 'is_string')) !== count($reasons)
            || count(array_unique($reasons)) !== count($reasons)
            || array_diff($reasons, self::REASON_CODES) !== []) {
            $this->invalid('Screening evidence contains invalid reason codes.');
        }
    }

    private function normalizedCheckResult(array $entry, string $selectedDocumentType, string $key): string
    {
        $check = $entry['checks'][$key] ?? null;
        if (! is_array($check) || ! $this->isReliable($check, $key)) {
            return 'uncertain';
        }
        if ($key === 'dates' && ! $this->isCalendarDay($entry['reference_day'] ?? null)) {
            return 'uncertain';
        }
        if ($selectedDocumentType === 'bir_certificate' && $key === 'business_identity'
            && ! in_array($entry['relationship'] ?? 'unclear', ['sole_proprietor', 'corporate'], true)) {
            return 'uncertain';
        }
        if ($key === 'document_type') {
            $family = $entry['detected_family'];
            if ($family === null) {
                return 'uncertain';
            }
            if ($this->familyConflicts($selectedDocumentType, $family)) {
                return 'mismatch';
            }
        }

        return $check['result'];
    }

    private function isReliable(array $check, string $key): bool
    {
        return $this->isConfidence($check['confidence'])
            && $check['confidence'] >= 0.85
            && $check['complete_value'] === true
            && $check['field_associated'] === true
            && $check['unambiguous'] === true
            && ($key !== 'document_type' || $check['independent_type_signals'] === true);
    }

    private function familyConflicts(string $selectedDocumentType, string $family): bool
    {
        return $selectedDocumentType === 'valid_id'
            ? ! in_array($family, self::ID_FAMILIES, true)
            : $family !== $selectedDocumentType;
    }

    private function isConfidence(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= 0
            && $value <= 1;
    }

    private function isCalendarDay(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['ocr_screening' => $message]);
    }
}
