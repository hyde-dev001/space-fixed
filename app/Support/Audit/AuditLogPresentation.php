<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Support\Str;

final class AuditLogPresentation
{
    /** @var array<string, string> */
    private const SUBJECT_LABELS = [
        'shop_owner_subscription' => 'Subscription',
        'shop_owner_subscription_payment' => 'Subscription payment',
        'shop_owner_subscription_refund' => 'Subscription refund',
        'repair_request' => 'Repair request',
        'repair_service' => 'Repair service',
        'leave_request' => 'Leave request',
        'attendance_record' => 'Attendance record',
        'price_change_request' => 'Price change request',
        'inventory_item' => 'Inventory item',
        'user' => 'Employee account',
    ];

    public static function actionLabel(?string $action): string
    {
        $normalized = trim((string) $action);

        return $normalized === ''
            ? 'Activity'
            : Str::headline(str_replace(['.', '_', '-'], ' ', $normalized));
    }

    public static function subjectTypeLabel(mixed $type): string
    {
        $normalized = trim((string) $type);

        if ($normalized === '') {
            return 'Record';
        }

        $baseName = Str::afterLast(str_replace('/', '\\', $normalized), '\\');
        $key = Str::snake($baseName);

        return self::SUBJECT_LABELS[$key] ?? Str::headline($key);
    }

    public static function description(?string $description, ?string $action): string
    {
        $value = trim((string) $description);
        $normalizedAction = trim((string) $action);

        if ($value === '' || self::isInternalDescription($value, $normalizedAction)) {
            return self::actionLabel($normalizedAction);
        }

        return $value;
    }

    private static function isInternalDescription(string $description, string $action): bool
    {
        if (str_contains($description, '\\') || str_contains($description, 'App\\Models\\')) {
            return true;
        }

        $normalize = static fn (string $value): string => Str::lower(trim(str_replace(['.', '_', '-'], ' ', $value)));

        return ($action !== '' && $normalize($description) === $normalize($action))
            || (bool) preg_match('/^[a-z0-9]+(?:[._:-][a-z0-9]+)+$/i', $description);
    }
}
