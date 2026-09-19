<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OwnerApprovalHistoryItem
{
    /** @var array<int, string> */
    private const SOURCE_TYPES = [
        'order_refund',
        'repair_refund',
        'product_price_change',
        'repair_price_change',
        'repair_package_price_change',
        'payslip',
        'salary_change',
        'purchase_request',
        'suspension_request',
        'termination_request',
        'rehire_request',
        'expense',
        'repair_rejection',
    ];

    /** @var array<int, string> */
    private const COVERAGE_SOURCES = [
        'refunds',
        'prices',
        'payslips',
        'salary_changes',
        'purchase_requests',
        'suspensions',
        'terminations',
        'rehires',
        'expenses',
        'repair_rejections',
    ];

    public string $attentionKey;

    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $title,
        public string $conciseSummary,
        public string $coverageSource,
        public string $status,
        public string $decisionAt,
        public ?string $requestedAt,
        public ?float $comparableMonetaryExposure,
        public ?string $comments,
        public ?string $reviewedBy,
        public string $destinationUrl,
    ) {
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException('Owner approval history source type is invalid.');
        }

        if ($sourceId < 1) {
            throw new InvalidArgumentException('Owner approval history source ID must be positive.');
        }

        $expectedCoverage = match ($sourceType) {
            'order_refund', 'repair_refund' => 'refunds',
            'product_price_change', 'repair_price_change', 'repair_package_price_change' => 'prices',
            'payslip' => 'payslips',
            'salary_change' => 'salary_changes',
            'purchase_request' => 'purchase_requests',
            'suspension_request' => 'suspensions',
            'termination_request' => 'terminations',
            'rehire_request' => 'rehires',
            'expense' => 'expenses',
            'repair_rejection' => 'repair_rejections',
        };

        if ($coverageSource !== $expectedCoverage || ! in_array($coverageSource, self::COVERAGE_SOURCES, true)) {
            throw new InvalidArgumentException('Owner approval history coverage is invalid.');
        }

        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Owner approval history status is invalid.');
        }

        self::validateText($title, 'title', 160);
        self::validateText($conciseSummary, 'summary', 500);
        self::validateTimestamp($decisionAt, 'decision');
        self::validateTimestamp($requestedAt, 'requested');
        self::validateOptionalText($comments, 'comments', 1000);
        self::validateOptionalText($reviewedBy, 'reviewer', 160);

        if ($comparableMonetaryExposure !== null
            && ($comparableMonetaryExposure < 0 || ! is_finite($comparableMonetaryExposure))) {
            throw new InvalidArgumentException('Owner approval history amount is invalid.');
        }

        if ($destinationUrl === ''
            || strlen($destinationUrl) > 2048
            || ! str_starts_with($destinationUrl, '/shop-owner/')
            || preg_match('/[\x00-\x20\x7F]/', $destinationUrl) === 1
            || str_contains($destinationUrl, '*')) {
            throw new InvalidArgumentException('Owner approval history destination is invalid.');
        }

        $this->attentionKey = implode(':', ['history', $sourceType, (string) $sourceId, $status]);
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'attention_key' => $this->attentionKey,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'concise_summary' => $this->conciseSummary,
            'coverage_source' => $this->coverageSource,
            'status' => $this->status,
            'decision_at' => $this->decisionAt,
            'requested_at' => $this->requestedAt,
            'comparable_monetary_exposure' => $this->comparableMonetaryExposure,
            'comments' => $this->comments,
            'reviewed_by' => $this->reviewedBy,
            'destination_url' => $this->destinationUrl,
        ];
    }

    private static function validateText(string $value, string $field, int $maxLength): void
    {
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Owner approval history {$field} is invalid.");
        }
    }

    private static function validateOptionalText(?string $value, string $field, int $maxLength): void
    {
        if ($value !== null) {
            self::validateText($value, $field, $maxLength);
        }
    }

    private static function validateTimestamp(?string $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Owner approval history {$field} timestamp is invalid.");
        }

        try {
            new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException("Owner approval history {$field} timestamp is invalid.", 0, $exception);
        }
    }
}
