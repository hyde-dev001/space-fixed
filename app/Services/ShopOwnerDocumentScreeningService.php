<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ShopOwnerDocumentScreeningService
{
    public const REPORT_SCHEMA_VERSION = 1;

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

    /**
     * @param array<string, mixed> $registration
     * @return array<string, array<string, mixed>>
     */
    public function validateRegistrationInput(?string $raw, array $registration): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        return $this->validateEnvelope($raw, [
            'business_registration' => (string) ($registration['business_registration_type'] ?? ''),
            'mayors_permit' => 'mayors_permit',
            'bir_certificate' => 'bir_certificate',
            'valid_id' => 'valid_id',
        ]);
    }

    /**
     * Turn validated browser claims into a private, server-scored snapshot.
     * No extracted OCR values are accepted or persisted by this boundary.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function createReport(
        ShopOwner $owner,
        array $metadata,
        string $checksum,
        array $entry,
    ): array {
        $slot = (string) ($metadata['logical_slot'] ?? '');
        $documentType = (string) ($metadata['document_type'] ?? '');
        $this->assertValidEntry($slot, $documentType, $entry);
        $serverDay = $this->referenceDay();
        $evidenceDay = $entry['reference_day'] ?? null;

        if (isset($entry['checks']['dates'])
            && ((! $this->isCalendarDay($evidenceDay) || $serverDay === null)
                || ($evidenceDay !== $serverDay
                    && $entry['checks']['dates']['result'] === 'match'))) {
            $entry['checks']['dates']['result'] = 'uncertain';
        }
        if ($serverDay === null) {
            unset($entry['reference_day']);
        } else {
            $entry['reference_day'] = $serverDay;
        }

        $score = $this->scoreEntry($slot, $documentType, $entry);
        $requiredChecks = $this->requiredChecks($documentType, $entry);
        $checks = [];

        foreach ($requiredChecks as $key) {
            $claim = $entry['checks'][$key] ?? [];
            $checks[$key] = [
                'result' => $this->normalizedCheckResult($entry, $documentType, $key),
                'confidence' => $claim['confidence'] ?? null,
                'complete_value' => $claim['complete_value'] ?? false,
                'field_associated' => $claim['field_associated'] ?? false,
                'unambiguous' => $claim['unambiguous'] ?? false,
                'independent_type_signals' => $claim['independent_type_signals'] ?? false,
            ];
        }

        $reasonCodes = $entry['reason_codes'] ?? [];
        if (in_array('uncertain', array_column($checks, 'result'), true)
            && ! in_array('uncertain_field', $reasonCodes, true)
            && count($reasonCodes) < 8) {
            $reasonCodes[] = 'uncertain_field';
        }

        $report = [
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'source' => 'browser_tesseract',
            'file_checksum_sha256' => $checksum,
            'reference_day' => $serverDay,
            'assessment_state' => $score['state'],
            'detected_family' => $entry['detected_family'],
            'recognition_confidence' => $entry['recognition_confidence'] ?? null,
            'checks' => $checks,
            'reason_codes' => $reasonCodes,
            'score' => $score['score'],
            'context_hash' => $this->contextHash($owner, $metadata, $entry['relationship'] ?? null),
        ];

        if (isset($entry['relationship'])) {
            $report['relationship'] = $entry['relationship'];
        }

        return $report;
    }

    /**
     * Inherit only a report whose immutable file, type, context, and schema still agree.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    public function inheritReport(
        ShopOwner $owner,
        ShopDocument $predecessor,
        array $metadata,
        string $checksum,
    ): ?array {
        $report = $predecessor->ocr_screening;
        if (! is_array($report)
            || ($report['schema_version'] ?? null) !== self::REPORT_SCHEMA_VERSION
            || ($report['source'] ?? null) !== 'browser_tesseract'
            || ! is_string($report['file_checksum_sha256'] ?? null)
            || ! hash_equals($report['file_checksum_sha256'], $checksum)
            || ! is_string($predecessor->checksum_sha256)
            || ! hash_equals($predecessor->checksum_sha256, $checksum)
            || (string) $predecessor->document_type !== (string) ($metadata['document_type'] ?? '')
            || (string) $predecessor->logical_slot !== (string) ($metadata['logical_slot'] ?? '')) {
            return null;
        }

        $expectedContext = $this->contextHash($owner, $metadata, $report['relationship'] ?? null);
        if (! is_string($report['context_hash'] ?? null)
            || ! hash_equals($report['context_hash'], $expectedContext)) {
            return null;
        }

        return $report;
    }

    public function referenceDay(): ?string
    {
        try {
            return CarbonImmutable::now((string) config('app.shop_timezone', 'Asia/Manila'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $metadata */
    public function contextHash(ShopOwner $owner, array $metadata, ?string $relationship = null): string
    {
        $slot = (string) ($metadata['logical_slot'] ?? '');
        $documentType = (string) ($metadata['document_type'] ?? '');
        $context = [
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'logical_slot' => $slot,
            'document_type' => $documentType,
            'issued_on' => $this->dateValue($metadata['issued_on'] ?? null),
            'expiration_mode' => $metadata['expiration_mode'] ?? null,
            'expires_on' => $this->dateValue($metadata['expires_on'] ?? null),
        ];

        if ($slot !== 'valid_id') {
            $context['business_name'] = $this->normalizeContextText($owner->business_name);
            $context['business_type'] = $this->normalizeContextText($owner->business_type);
        }

        if (in_array($slot, ['mayors_permit', 'bir_certificate'], true)) {
            $context['business_address'] = $this->normalizeContextText($owner->business_address);
        }

        if ($documentType === 'dti_registration' || $slot === 'valid_id'
            || ($slot === 'bir_certificate' && $relationship === 'sole_proprietor')) {
            $context['first_name'] = $this->normalizeContextText($owner->first_name);
            $context['last_name'] = $this->normalizeContextText($owner->last_name);
        }

        if ($documentType === 'dti_registration') {
            $context['registration_type'] = $this->normalizeContextText($owner->registration_type);
        }
        if ($slot === 'bir_certificate') {
            $context['relationship'] = $relationship;
        }

        try {
            return hash('sha256', json_encode(
                $context,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException) {
            $this->invalid('The screening comparison context is invalid.');
        }
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

    /** @param array<string, mixed> $entry @return array<int, string> */
    private function requiredChecks(string $documentType, array $entry): array
    {
        $required = self::REQUIRED_CHECKS[$documentType];
        if ($documentType === 'bir_certificate' && ($entry['relationship'] ?? null) === 'sole_proprietor') {
            $required[] = 'proprietor_identity';
        }

        return $required;
    }

    private function normalizeContextText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return Str::lower(is_string($collapsed) ? $collapsed : $trimmed);
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? $value : null;
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
