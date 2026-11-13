<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminPage;
use App\Models\AdminPagePermission;
use App\Models\Notification;
use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class AdminPageAccessService
{
    /** @var array<string, AdminPage> */
    private const NOTIFICATION_PAGE_BY_TYPE = [
        'shop_registration_pending' => AdminPage::SHOP_MANAGEMENT,
        'shop_document_renewal_pending' => AdminPage::DOCUMENT_RENEWALS,
        'shop_document_renewal_reviewed' => AdminPage::DOCUMENT_RENEWALS,
        'shop_document_expiring' => AdminPage::DOCUMENT_RENEWALS,
        'business_upgrade_request_pending' => AdminPage::BUSINESS_UPGRADE_REQUESTS,
        'business_upgrade_request_approved' => AdminPage::BUSINESS_UPGRADE_REQUESTS,
        'business_upgrade_request_rejected' => AdminPage::BUSINESS_UPGRADE_REQUESTS,
        'shop_report_filed' => AdminPage::SHOP_REPORTS,
        'review_reported' => AdminPage::SHOP_REPORTS,
        'suspension_appeal_submitted' => AdminPage::SUSPENSION_APPEALS,
        'platform_balance_alert' => AdminPage::PLATFORM_FEES,
    ];

    /** @var array<string, array<int, string>> */
    private const NOTIFICATION_URL_PREFIXES_BY_PAGE = [
        'user_management' => ['/admin/users', '/admin/user-management', '/admin/identity-verification-reviews'],
        'shop_management' => ['/admin/registrations', '/admin/shop-owner-registration-view', '/admin/shop-owners'],
        'document_renewals' => ['/admin/document-renewals'],
        'business_upgrade_requests' => ['/admin/business-upgrade-requests'],
        'shop_reports' => ['/admin/shop-reports', '/admin/flagged-accounts'],
        'suspension_appeals' => ['/admin/appeals'],
        'platform_fees' => ['/admin/platform-fees'],
        'audit_history' => ['/admin/audit', '/admin/data-reports'],
        'registered_shops' => ['/admin/shops', '/admin/registered-shops'],
        'subscription_management' => ['/admin/subscriptions', '/admin/subscription-management', '/admin/subscription-payments', '/admin/plans'],
        'system_maintenance' => ['/admin/maintenance'],
    ];

    /** @return array<int, string> */
    public function pageKeys(SuperAdmin $admin): array
    {
        if ($admin->role === SuperAdmin::ROLE_SUPER_ADMIN) {
            return AdminPage::assignableKeys();
        }

        $permissions = $admin->relationLoaded('pagePermissions')
            ? $admin->getRelation('pagePermissions')
            : $admin->pagePermissions()->get();

        return $permissions
            ->pluck('page_key')
            ->filter(static fn (mixed $key): bool => is_string($key) && AdminPage::isAssignable($key))
            ->unique()
            ->values()
            ->all();
    }

    public function allows(SuperAdmin $admin, AdminPage|string $page): bool
    {
        $page = $page instanceof AdminPage ? $page : AdminPage::tryFrom($page);

        if (! $page instanceof AdminPage) {
            return false;
        }

        if (! $admin->isActive()) {
            return false;
        }

        if ($admin->role === SuperAdmin::ROLE_SUPER_ADMIN) {
            return true;
        }

        // The dashboard is the common landing page for every active admin.
        // Other pages remain explicitly assigned.
        if ($page === AdminPage::DASHBOARD) {
            return true;
        }

        if (! AdminPage::isAssignable($page)) {
            return false;
        }

        return $admin->pagePermissions()
            ->where('page_key', $page->value)
            ->exists();
    }

    /**
     * Keep notification pagination and unread counts on the same page-aware
     * query so restricted admin work is not leaked through the shared bell.
     */
    public function visibleAdminNotifications(SuperAdmin $admin): Builder
    {
        $query = Notification::query()->forSuperAdmin((int) $admin->getKey());

        if ($admin->role === SuperAdmin::ROLE_SUPER_ADMIN) {
            return $query;
        }

        $restrictedTypes = array_keys(self::NOTIFICATION_PAGE_BY_TYPE);
        $allowedTypes = array_keys(array_filter(
            self::NOTIFICATION_PAGE_BY_TYPE,
            fn (AdminPage $page): bool => $this->allows($admin, $page),
        ));
        $allowedPrefixes = [];
        foreach ($this->pageKeys($admin) as $pageKey) {
            foreach (self::NOTIFICATION_URL_PREFIXES_BY_PAGE[$pageKey] ?? [] as $prefix) {
                $allowedPrefixes[] = $prefix;
            }
        }

        return $query->where(function (Builder $visible) use ($allowedPrefixes, $allowedTypes, $restrictedTypes): void {
            if ($allowedTypes !== []) {
                $visible->whereIn('type', $allowedTypes);
            }

            foreach ($allowedPrefixes as $prefix) {
                $visible->orWhere('action_url', 'like', $prefix.'%');
            }

            $visible->orWhere(function (Builder $generic) use ($restrictedTypes): void {
                $generic
                    ->whereNotIn('type', $restrictedTypes)
                    ->where(function (Builder $nonAdminLink): void {
                        $nonAdminLink
                            ->whereNull('action_url')
                            ->orWhere(function (Builder $safeUrl): void {
                                $safeUrl
                                    ->where('action_url', 'not like', '/admin/%')
                                    ->where('action_url', 'not like', '/superAdmin/%');
                            });
                    });
            });
        });
    }

    /** @param array<int, mixed> $pageKeys @return array<int, string> */
    public function normalize(array $pageKeys): array
    {
        $normalized = [];

        foreach ($pageKeys as $pageKey) {
            if (! is_string($pageKey)) {
                throw new InvalidArgumentException('Administrator page keys must be strings.');
            }

            $page = AdminPage::tryFrom($pageKey);
            if (! $page instanceof AdminPage || ! AdminPage::isAssignable($page)) {
                throw new InvalidArgumentException('The administrator page is not assignable.');
            }

            $normalized[$page->value] = $page->value;
        }

        $keys = array_values($normalized);
        sort($keys);

        return $keys;
    }

    /** @param array<int, mixed> $pageKeys @return array{old: array<int, string>, new: array<int, string>, added: array<int, string>, removed: array<int, string>} */
    public function replace(SuperAdmin $admin, array $pageKeys): array
    {
        $newKeys = $this->normalize($pageKeys);
        $oldKeys = $this->pageKeys($admin);
        sort($oldKeys);

        $admin->pagePermissions()->delete();

        if ($newKeys !== []) {
            $now = now();
            $admin->pagePermissions()->createMany(array_map(
                static fn (string $pageKey): array => [
                    'page_key' => $pageKey,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $newKeys,
            ));
        }

        return [
            'old' => $oldKeys,
            'new' => $newKeys,
            'added' => array_values(array_diff($newKeys, $oldKeys)),
            'removed' => array_values(array_diff($oldKeys, $newKeys)),
        ];
    }
}
