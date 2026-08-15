<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopDocument;
use Carbon\CarbonImmutable;

final class ShopDocumentValidityService
{
    public const RENEWAL_WINDOW_DAYS = 30;
    public const URGENT_WINDOW_DAYS = 7;

    public const VALID = 'valid';
    public const VALID_NO_EXPIRATION = 'valid_no_expiration';
    public const EXPIRING_SOON = 'expiring_soon';
    public const EXPIRED = 'expired';
    public const METADATA_UNVERIFIED = 'metadata_unverified';

    public const OUTSIDE_WINDOW = 'outside_window';
    public const RENEWAL_WINDOW = 'renewal_window';
    public const URGENT_WINDOW = 'urgent_window';
    public const EXPIRES_TODAY = 'expires_today';
    public const NON_EXPIRING = 'non_expiring';

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

        $daysRemaining = $this->daysUntilExpiry($document, $today);
        if ($daysRemaining === null) {
            return self::METADATA_UNVERIFIED;
        }

        if ($daysRemaining < 0) {
            return self::EXPIRED;
        }

        return $daysRemaining <= self::RENEWAL_WINDOW_DAYS ? self::EXPIRING_SOON : self::VALID;
    }

    public function expiryWindow(ShopDocument $document, ?CarbonImmutable $today = null): string
    {
        if (! $this->isReviewerVerifiedCurrent($document)) {
            return self::METADATA_UNVERIFIED;
        }

        $mode = (string) ($document->expiration_mode ?? '');
        if ($mode === 'none') {
            return self::NON_EXPIRING;
        }

        if ($mode !== 'dated') {
            return self::METADATA_UNVERIFIED;
        }

        $daysRemaining = $this->daysUntilExpiry($document, $today);
        if ($daysRemaining === null) {
            return self::METADATA_UNVERIFIED;
        }

        return match (true) {
            $daysRemaining < 0 => self::EXPIRED,
            $daysRemaining === 0 => self::EXPIRES_TODAY,
            $daysRemaining <= self::URGENT_WINDOW_DAYS => self::URGENT_WINDOW,
            $daysRemaining <= self::RENEWAL_WINDOW_DAYS => self::RENEWAL_WINDOW,
            default => self::OUTSIDE_WINDOW,
        };
    }

    /** @return array<int, int> */
    public function milestoneDays(): array
    {
        return [self::RENEWAL_WINDOW_DAYS, self::URGENT_WINDOW_DAYS, 0];
    }

    public function daysUntilExpiry(ShopDocument $document, ?CarbonImmutable $today = null): ?int
    {
        if (! $document->expires_on) {
            return null;
        }

        $timezone = (string) config('app.shop_timezone', 'Asia/Manila');
        $localToday = ($today ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();

        try {
            $expirationDate = CarbonImmutable::parse(
                $document->expires_on->toDateString(),
                $timezone,
            )->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $days = $localToday->diffInDays($expirationDate, false);
        $wholeDays = (int) $days;

        return (float) $days === (float) $wholeDays ? $wholeDays : null;
    }

    private function isReviewerVerifiedCurrent(ShopDocument $document): bool
    {
        return (bool) $document->is_current
            && (string) $document->status === 'approved'
            && $document->reviewed_by_super_admin_id !== null
            && $document->reviewed_at !== null;
    }
}
