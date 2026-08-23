<?php

declare(strict_types=1);

namespace App\Services\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellFallbackReason;
use App\Enums\OwnerShellSelectionReason;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterRolloutPolicy;
use App\Services\ErpRouteCatalog;
use App\Services\ErpWorkspaceNavigationService;
use App\Services\ShopModuleAccessService;
use App\Support\OwnerShell\OwnerShellGroup;
use App\Support\OwnerShell\OwnerShellItem;
use App\Support\OwnerShell\OwnerShellMetadata;
use App\Support\OwnerShell\OwnerShellSelection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

final class CanonicalOwnerShellService
{
    private const FALLBACK_ROUTE = 'shop-owner.shell.erp-fallback';

    /**
     * @var array<string, array{group: string, module: string, route: string, label: string}>
     */
    private const MODULE_DESTINATIONS = [
        'retail' => [
            'group' => 'operate',
            'module' => 'retail_operations',
            'route' => 'shop-owner.shell.operate.retail',
            'label' => 'Retail',
        ],
        'repair' => [
            'group' => 'operate',
            'module' => 'repair_operations',
            'route' => 'shop-owner.shell.operate.repair',
            'label' => 'Repair',
        ],
        'customers' => [
            'group' => 'operate',
            'module' => 'crm',
            'route' => 'shop-owner.shell.operate.customers',
            'label' => 'Customers',
        ],
        'finance' => [
            'group' => 'oversee',
            'module' => 'finance',
            'route' => 'shop-owner.shell.oversee.finance',
            'label' => 'Finance',
        ],
        'workforce' => [
            'group' => 'oversee',
            'module' => 'hr_employees',
            'route' => 'shop-owner.shell.oversee.workforce',
            'label' => 'Workforce',
        ],
        'inventory' => [
            'group' => 'oversee',
            'module' => 'inventory',
            'route' => 'shop-owner.shell.oversee.inventory',
            'label' => 'Inventory',
        ],
        'procurement' => [
            'group' => 'oversee',
            'module' => 'procurement',
            'route' => 'shop-owner.shell.oversee.procurement',
            'label' => 'Procurement',
        ],
        'logistics' => [
            'group' => 'oversee',
            'module' => 'logistics',
            'route' => 'shop-owner.shell.oversee.logistics',
            'label' => 'Logistics',
        ],
    ];

    public function __construct(
        private readonly OwnerShellRolloutPolicy $rollout,
        private readonly ShopModuleAccessService $moduleAccess,
        private readonly ErpRouteCatalog $catalog,
        private readonly ErpWorkspaceNavigationService $navigation,
        private readonly OwnerActionCenterRolloutPolicy $ownerActionCenterRollout,
    ) {}

    public function forOwner(ShopOwner $owner): OwnerShellMetadata
    {
        $selection = $this->rollout->select($owner);

        if ($selection->presentation !== OwnerShellPresentation::Canonical) {
            return OwnerShellMetadata::existing($selection->reason);
        }

        try {
            return $this->compose($owner, $selection);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('shop_owner_shell_composition_failed', [
                'shop_id' => $owner->getKey(),
                'reason' => OwnerShellSelectionReason::ShellCompositionFailed->value,
            ]);

            return OwnerShellMetadata::existing(OwnerShellSelectionReason::ShellCompositionFailed);
        }
    }

    public function ownerErpFallbackAllowed(ShopOwner $owner): bool
    {
        try {
            $selection = $this->rollout->select($owner);

            return $selection->presentation === OwnerShellPresentation::Canonical
                && $this->workspaceEligible($owner);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function compose(ShopOwner $owner, OwnerShellSelection $selection): OwnerShellMetadata
    {
        $context = $selection->context;
        if ($context === null) {
            throw new RuntimeException('Canonical owner shell composition requires a registration context.');
        }

        $states = $this->moduleAccess->statesFor($owner);
        $groups = [
            $this->homeGroup(),
        ];

        $actionCenter = $this->actionCenterGroup($owner);
        if ($actionCenter !== null) {
            $groups[] = $actionCenter;
        }

        $operate = $this->moduleGroup(
            'operate',
            'Operate',
            $context === 'individual' ? 10 : 20,
            $context === 'individual',
            $states,
        );
        $oversee = $this->moduleGroup(
            'oversee',
            'Oversee',
            $context === 'company' ? 10 : 20,
            $context === 'company',
            $states,
        );

        if ($context === 'individual') {
            if ($operate !== null) {
                $groups[] = $operate;
            }

            if ($oversee !== null) {
                $groups[] = $oversee;
            }
        } else {
            if ($oversee !== null) {
                $groups[] = $oversee;
            }

            if ($operate !== null) {
                $groups[] = $operate;
            }
        }

        $groups[] = $this->reportsGroup();

        return new OwnerShellMetadata(
            OwnerShellPresentation::Canonical,
            $selection->reason,
            $context,
            $groups,
            $this->compatibility($owner),
        );
    }

    private function homeGroup(): OwnerShellGroup
    {
        $homeUrl = $this->canonicalUrl('shop-owner.shell.home');
        $activeMatching = [
            $homeUrl,
            $this->pathForRoute('shop-owner.dashboard'),
        ];

        if (Route::has('shop-owner.erp.retail.dashboard')) {
            $activeMatching[] = $this->pathForRoute('shop-owner.erp.retail.dashboard').'*';
        }

        $items = [new OwnerShellItem(
            'home',
            'Home',
            $homeUrl,
            true,
            null,
            null,
            $activeMatching,
        )];

        return new OwnerShellGroup(
            'home',
            'Home',
            0,
            true,
            $items,
        );
    }

    private function actionCenterGroup(ShopOwner $owner): ?OwnerShellGroup
    {
        if (! $this->ownerActionCenterRollout->select($owner)->selected) {
            return null;
        }

        $actionCenterUrl = $this->canonicalUrl('shop-owner.shell.action-center');

        return new OwnerShellGroup(
            'action-center',
            'Action Center',
            5,
            true,
            [new OwnerShellItem(
                'action-center',
                'Action Center',
                $actionCenterUrl,
                true,
                null,
                null,
                [$actionCenterUrl],
            )],
        );
    }

    /**
     * @param array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}> $states
     */
    private function moduleGroup(
        string $groupKey,
        string $label,
        int $order,
        bool $defaultExpanded,
        array $states,
    ): ?OwnerShellGroup {
        $items = [];

        foreach (self::MODULE_DESTINATIONS as $destinationKey => $destination) {
            if ($destination['group'] !== $groupKey) {
                continue;
            }

            $state = $states[$destination['module']] ?? null;
            if (! is_array($state) || ! ($state['eligible'] ?? false)) {
                continue;
            }

            $canonicalUrl = $this->canonicalUrl($destination['route']);
            $activeMatching = $this->moduleActiveMatching($destination['module']);

            if (($state['accessible'] ?? false) === true) {
                $items[] = new OwnerShellItem(
                    $destinationKey,
                    $destination['label'],
                    $canonicalUrl,
                    true,
                    null,
                    null,
                    $activeMatching,
                );

                continue;
            }

            if (($state['code'] ?? null) !== 'MODULE_DISABLED') {
                continue;
            }

            $items[] = new OwnerShellItem(
                $destinationKey,
                $destination['label'],
                $canonicalUrl,
                false,
                'module_disabled',
                $this->canonicalUrl('shop-owner.shell.settings.modules-team'),
                $activeMatching,
            );
        }

        if ($groupKey === 'operate' && $this->hasAccessibleOperationalPath($states)) {
            $items[] = $this->paymentsItem($states);
        }

        if ($items === []) {
            return null;
        }

        return new OwnerShellGroup($groupKey, $label, $order, $defaultExpanded, $items);
    }

    /**
     * @param array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}> $states
     */
    private function hasAccessibleOperationalPath(array $states): bool
    {
        return ($states['retail_operations']['accessible'] ?? false) === true
            || ($states['repair_operations']['accessible'] ?? false) === true;
    }

    /**
     * @param array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}> $states
     */
    private function paymentsItem(array $states): OwnerShellItem
    {
        $activeMatching = [$this->canonicalUrl('shop-owner.shell.operate.payments')];

        foreach (['retail_operations' => 'retail', 'repair_operations' => 'repair'] as $moduleKey => $slug) {
            if (($states[$moduleKey]['accessible'] ?? false) !== true) {
                continue;
            }

            $routeName = 'shop-owner.erp.'.$slug.'.point-of-sale';
            $this->assertOwnerRoute($routeName);
            $activeMatching[] = $this->pathForRoute($routeName).'*';
        }

        return new OwnerShellItem(
            'payments',
            'Payments',
            $this->canonicalUrl('shop-owner.shell.operate.payments'),
            true,
            null,
            null,
            $activeMatching,
        );
    }

    private function reportsGroup(): OwnerShellGroup
    {
        $reportsUrl = $this->canonicalUrl('shop-owner.shell.reports');
        $auditUrl = $this->canonicalUrl('shop-owner.shell.audit');
        $this->assertOwnerRoute('shop-owner.erp.manager.reports');
        $this->assertOwnerRoute('shop-owner.erp.manager.audit-logs');

        return new OwnerShellGroup(
            'reports',
            'Reports & Audit',
            30,
            false,
            [
                new OwnerShellItem(
                    'reports',
                    'Reports',
                    $reportsUrl,
                    true,
                    null,
                    null,
                    [$reportsUrl, $this->pathForRoute('shop-owner.erp.manager.reports').'*'],
                ),
                new OwnerShellItem(
                    'audit',
                    'Audit',
                    $auditUrl,
                    true,
                    null,
                    null,
                    [$auditUrl, $this->pathForRoute('shop-owner.erp.manager.audit-logs').'*'],
                ),
            ],
        );
    }

    /**
     * @param array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}> $states
     * @return array<int, string>
     */
    private function moduleActiveMatching(string $moduleKey): array
    {
        $definition = $this->navigation->forKey($moduleKey);
        $url = $this->navigation->urlForKey($moduleKey);

        if (! is_array($definition) || ! is_string($url)) {
            throw new RuntimeException("ERP navigation source is missing for {$moduleKey}.");
        }

        return [
            $this->pathForRoute($this->moduleRouteName($moduleKey)),
            $this->path($url).'*',
        ];
    }

    private function moduleRouteName(string $moduleKey): string
    {
        foreach (self::MODULE_DESTINATIONS as $destination) {
            if ($destination['module'] === $moduleKey) {
                return $destination['route'];
            }
        }

        throw new RuntimeException("Canonical module destination is missing for {$moduleKey}.");
    }

    private function compatibility(ShopOwner $owner): array
    {
        if (! $this->workspaceEligible($owner)) {
            return [
                'show_erp_fallback' => false,
                'erp_workspace_url' => null,
                'fallback_url' => null,
            ];
        }

        $workspaceUrl = $this->pathForRoute('shop-owner.erp.workspace');

        return [
            'show_erp_fallback' => true,
            'erp_workspace_url' => $workspaceUrl,
            'fallback_url' => $this->fallbackUrl(),
        ];
    }

    private function fallbackUrl(): string
    {
        if (! Route::has(self::FALLBACK_ROUTE)) {
            throw new RuntimeException('Owner shell ERP fallback route is not registered.');
        }

        $url = route(self::FALLBACK_ROUTE, [
            'reason' => OwnerShellFallbackReason::UserPreference->value,
            'source' => 'home',
        ]);
        $path = $this->path($url);
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }

    private function workspaceEligible(ShopOwner $owner): bool
    {
        if (! (bool) config('shop_modules.owner_erp_workspace_enabled', false)) {
            return false;
        }

        $entry = $this->assertOwnerRoute('shop-owner.erp.workspace');
        $registrationType = strtolower(trim((string) $owner->getRawOriginal('registration_type')));
        $businessType = $this->normalizedBusinessType($owner);
        $status = strtolower(trim((string) $owner->getRawOriginal('status')));

        return $status === 'approved'
            && in_array($registrationType, $entry['registration_types'] ?? [], true)
            && in_array($businessType, $entry['business_types'] ?? [], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertOwnerRoute(string $routeName): array
    {
        if (! Route::has($routeName)) {
            throw new RuntimeException("ERP route {$routeName} is not registered.");
        }

        $entry = $this->catalog->entry($routeName);
        if ($entry === null
            || ! in_array('GET', $entry['methods'] ?? [], true)
            || ($entry['audience'] ?? null) !== 'shop_owner'
            || ($entry['actor_guard'] ?? null) !== 'shop_owner'
            || ($entry['owner_access'] ?? null) !== 'allowed') {
            throw new RuntimeException("ERP route {$routeName} is not an owner-safe GET capability.");
        }

        return $entry;
    }

    private function canonicalUrl(string $routeName): string
    {
        if (! Route::has($routeName)) {
            throw new RuntimeException("Canonical owner shell route {$routeName} is not registered.");
        }

        return $this->path(route($routeName));
    }

    private function pathForRoute(string $routeName): string
    {
        if (! Route::has($routeName)) {
            throw new RuntimeException("Owner shell source route {$routeName} is not registered.");
        }

        return $this->path(route($routeName));
    }

    private function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/shop-owner/')) {
            throw new RuntimeException('Owner shell URLs must remain within the Shop Owner surface.');
        }

        return $path;
    }

    private function normalizedBusinessType(ShopOwner $owner): string
    {
        return match (strtolower(trim((string) $owner->getRawOriginal('business_type')))) {
            'both (retail & repair)' => 'both',
            default => strtolower(trim((string) $owner->getRawOriginal('business_type'))),
        };
    }
}
