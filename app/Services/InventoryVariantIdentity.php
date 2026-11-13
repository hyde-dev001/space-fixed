<?php

namespace App\Services;

use App\Models\InventorySize;

final class InventoryVariantIdentity
{
    private const SIZE_SYSTEMS = ['US', 'UK', 'EU', 'AU', 'CN'];

    public static function normalizeColor(?string $color): ?string
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim((string) $color)) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeSize(?string $size, ?string $sizeSystem = null): ?string
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $size)) ?? '';
        if ($value === '') {
            return null;
        }

        $system = strtoupper(trim((string) $sizeSystem));
        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $value, $matches)) {
            $system = strtoupper($matches[1]);
            $value = trim($matches[2]);
        }

        if ($system === '' && preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            $system = 'US';
        }

        $value = strtoupper($value);
        if (in_array($system, self::SIZE_SYSTEMS, true)) {
            return $system . ' ' . $value;
        }

        return $value;
    }

    public static function fromSize(InventorySize $size): ?string
    {
        return self::normalizeSize($size->size, $size->size_system);
    }
}
