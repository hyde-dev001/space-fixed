<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopDocument;
use Carbon\CarbonImmutable;

final class ShopDocumentValidityService
{
    public const VALID = 'valid';
    public const VALID_NO_EXPIRATION = 'valid_no_expiration';
    public const EXPIRING_SOON = 'expiring_soon';
    public const EXPIRED = 'expired';
    public const METADATA_UNVERIFIED = 'metadata_unverified';

    public function classify(ShopDocument $document, ?CarbonImmutable $today = null): string
    {
        if (! $this->isReviewerVerifiedCurrent($document)) {
            return self::METADATA_UNVERIFIED;
        }

        $mode = (string) ($document->expiration_mode ?? '');
        if ($mode === 'none') {
            return self::VALID_NO_EXPIRATION;
        }

        if ($mode !== 'dated' || ! $document->expires_on) {
            return self::METADATA_UNVERIFIED;
        }

        $timezone = (string) config('app.shop_timezone', 'Asia/Manila');
        $localToday = ($today ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $expirationDate = CarbonImmutable::parse($document->expires_on->toDateString(), $timezone)->startOfDay();
        $daysRemaining = $localToday->diffInDays($expirationDate, false);

        if ($daysRemaining < 0) {
            return self::EXPIRED;
        }

        return $daysRemaining <= 30 ? self::EXPIRING_SOON : self::VALID;
    }

    private function isReviewerVerifiedCurrent(ShopDocument $document): bool
    {
        return (bool) $document->is_current
            && (string) $document->status === 'approved'
            && $document->reviewed_by_super_admin_id !== null
            && $document->reviewed_at !== null;
    }
}
