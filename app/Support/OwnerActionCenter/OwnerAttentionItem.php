<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OwnerAttentionItem
{
    public string $attentionKey;

    public string $primaryBucket;

    public string $waitingOn;

    public bool $ownerActionRequired;

    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $category,
        public string $module,
        public string $title,
        public string $conciseSummary,
        public string $priorityTier,
        public string $materialityTier,
        public ?float $comparableMonetaryExposure,
        public ?string $urgencyAt,
        public string $actionableSince,
        public string $destinationUrl,
    ) {
        if (! in_array($sourceType, ['order_refund', 'repair_refund', 'expense', 'purchase_request'], true)) {
            throw new InvalidArgumentException('Owner attention source type is not supported.');
        }

        if ($sourceId < 1) {
            throw new InvalidArgumentException('Owner attention source ID must be positive.');
        }

        self::validateToken($category, 'category', 80);
        self::validateToken($module, 'module', 80);
        self::validateText($title, 'title', 160);
        self::validateText($conciseSummary, 'concise summary', 500);

        if (! in_array($priorityTier, ['urgent', 'high', 'normal', 'low'], true)) {
            throw new InvalidArgumentException('Owner attention priority tier is invalid.');
        }

        if (! in_array($materialityTier, ['high', 'medium', 'low', 'none'], true)) {
            throw new InvalidArgumentException('Owner attention materiality tier is invalid.');
        }

        if ($comparableMonetaryExposure !== null
            && ($comparableMonetaryExposure < 0 || ! is_finite($comparableMonetaryExposure))) {
            throw new InvalidArgumentException('Owner attention monetary exposure must be non-negative and finite.');
        }

        self::validateTimestamp($urgencyAt, 'urgency');
        self::validateTimestamp($actionableSince, 'actionable');
        self::validateLocalPath($destinationUrl);

        $this->attentionKey = implode(':', [$sourceType, (string) $sourceId, $category]);
        $this->primaryBucket = 'needs_my_decision';
        $this->waitingOn = 'shop_owner';
        $this->ownerActionRequired = true;
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
            'category' => $this->category,
            'primary_bucket' => $this->primaryBucket,
            'module' => $this->module,
            'title' => $this->title,
            'concise_summary' => $this->conciseSummary,
            'priority_tier' => $this->priorityTier,
            'materiality_tier' => $this->materialityTier,
            'comparable_monetary_exposure' => $this->comparableMonetaryExposure,
            'urgency_at' => $this->urgencyAt,
            'actionable_since' => $this->actionableSince,
            'waiting_on' => $this->waitingOn,
            'owner_action_required' => $this->ownerActionRequired,
            'destination_url' => $this->destinationUrl,
        ];
    }

    private static function validateToken(string $value, string $field, int $maxLength): void
    {
        if ($value === '' || strlen($value) > $maxLength || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new InvalidArgumentException("Owner attention {$field} is invalid.");
        }
    }

    private static function validateText(string $value, string $field, int $maxLength): void
    {
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Owner attention {$field} is invalid.");
        }
    }

    private static function validateTimestamp(?string $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Owner attention {$field} timestamp is invalid.");
        }

        try {
            new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException("Owner attention {$field} timestamp is invalid.", 0, $exception);
        }
    }

    private static function validateLocalPath(string $value): void
    {
        if ($value === ''
            || strlen($value) > 2048
            || ! str_starts_with($value, '/shop-owner/')
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
            || str_contains($value, '*')) {
            throw new InvalidArgumentException('Owner attention destination must be a local Shop Owner path.');
        }
    }
}
