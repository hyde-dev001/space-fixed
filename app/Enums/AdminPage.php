<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminPage: string
{
    case DASHBOARD = 'dashboard';
    case ADMIN_MANAGEMENT = 'admin_management';
    case USER_MANAGEMENT = 'user_management';
    case SHOP_MANAGEMENT = 'shop_management';
    case DOCUMENT_RENEWALS = 'document_renewals';
    case BUSINESS_UPGRADE_REQUESTS = 'business_upgrade_requests';
    case SHOP_REPORTS = 'shop_reports';
    case SUSPENSION_APPEALS = 'suspension_appeals';
    case PLATFORM_FEES = 'platform_fees';
    case AUDIT_HISTORY = 'audit_history';
    case REGISTERED_SHOPS = 'registered_shops';
    case SUBSCRIPTION_MANAGEMENT = 'subscription_management';
    case SYSTEM_MAINTENANCE = 'system_maintenance';

    /** @return array<int, self> */
    public static function assignable(): array
    {
        return [
            self::DASHBOARD,
            self::USER_MANAGEMENT,
            self::SHOP_MANAGEMENT,
            self::DOCUMENT_RENEWALS,
            self::BUSINESS_UPGRADE_REQUESTS,
            self::SHOP_REPORTS,
            self::SUSPENSION_APPEALS,
            self::PLATFORM_FEES,
            self::AUDIT_HISTORY,
            self::REGISTERED_SHOPS,
            self::SUBSCRIPTION_MANAGEMENT,
        ];
    }

    /** @return array<int, string> */
    public static function assignableKeys(): array
    {
        return array_map(
            static fn (self $page): string => $page->value,
            self::assignable(),
        );
    }

    public static function isAssignable(self|string $page): bool
    {
        $key = $page instanceof self ? $page->value : $page;

        return in_array($key, self::assignableKeys(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Dashboard',
            self::ADMIN_MANAGEMENT => 'Admin Management',
            self::USER_MANAGEMENT => 'User Management',
            self::SHOP_MANAGEMENT => 'Shop Management',
            self::DOCUMENT_RENEWALS => 'Document Renewals',
            self::BUSINESS_UPGRADE_REQUESTS => 'Business Upgrade Requests',
            self::SHOP_REPORTS => 'Shop Reports',
            self::SUSPENSION_APPEALS => 'Suspension Appeals',
            self::PLATFORM_FEES => 'Platform Fees',
            self::AUDIT_HISTORY => 'Audit History',
            self::REGISTERED_SHOPS => 'Registered Shops',
            self::SUBSCRIPTION_MANAGEMENT => 'Subscription Management',
            self::SYSTEM_MAINTENANCE => 'System Maintenance',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Dashboard',
            self::ADMIN_MANAGEMENT => 'Account Management',
            self::USER_MANAGEMENT,
            self::SHOP_MANAGEMENT,
            self::DOCUMENT_RENEWALS,
            self::BUSINESS_UPGRADE_REQUESTS,
            self::SHOP_REPORTS,
            self::SUSPENSION_APPEALS => 'Account Management',
            self::PLATFORM_FEES,
            self::SUBSCRIPTION_MANAGEMENT => 'Platform',
            self::AUDIT_HISTORY => 'Governance',
            self::REGISTERED_SHOPS => 'Shops',
            self::SYSTEM_MAINTENANCE => 'Platform',
        };
    }

    /** @return array<int, array{key: string, label: string, group: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $page): array => [
                'key' => $page->value,
                'label' => $page->label(),
                'group' => $page->group(),
            ],
            self::assignable(),
        );
    }
}
