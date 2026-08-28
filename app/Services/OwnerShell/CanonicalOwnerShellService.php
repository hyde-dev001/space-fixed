<?php

declare(strict_types=1);

namespace App\Services\OwnerShell;

use App\Enums\OwnerShellPresentation;
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

    private function compose(ShopOwner $owner, OwnerShellSelection $selection): OwnerShellMetadata
    {
        $context = $selection->context;
        if ($context === null) {
            throw new RuntimeException('Canonical owner shell composition requires a registration context.');
        }

        $states = $this->moduleAccess->statesFor(
            $owner,
            (bool) config('shop_modules.enforcement_enabled', false),
        );
        $groups = [
            $this->homeGroup($context === 'individual'),
        ];

        $actionCenter = $this->actionCenterGroup($owner);
        if ($actionCenter !== null) {
            $groups[] = $actionCenter;
        }

        $operate = $context === 'individual'
            ? $this->individualOperateGroup($states)
            : $this->moduleGroup('operate', 'Operate', 20, false, $states);
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

        if ($context === 'company') {
            $groups[] = $this->reportsGroup();
        }

        return new OwnerShellMetadata(
            OwnerShellPresentation::Canonical,
            $selection->reason,
            $context,
            $groups,
        );
    }

    private function homeGroup(bool $includeAssistCenter): OwnerShellGroup
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

        if ($includeAssistCenter) {
            $assistCenterUrl = $this->canonicalUrl('shop-owner.dss-insights');
            $this->assertOwnerRoute('shop-owner.dss-insights');
            $items[] = new OwnerShellItem(
                'assist-center',
                'Assist Center',
                $assistCenterUrl,
                true,
                null,
                null,
                [$assistCenterUrl],
            );
        }

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
            'Approval Center',
            5,
            true,
            [new OwnerShellItem(
                'action-center',
                'Approval Center',
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

        if ($items === []) {
            return null;
        }

        return new OwnerShellGroup($groupKey, $label, $order, $defaultExpanded, $items);
    }

    /**
     * Individual owners operate the shop themselves. Their sidebar therefore
     * exposes the actual retail, repair, customer, and cashier workspaces
     * instead of company-only oversight modules. Each module owns its own
     * page tabs; the shell stays at the same high-level density as the
     * company owner navigation.
     *
     * @param array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}> $states
     */
    private function individualOperateGroup(array $states): ?OwnerShellGroup
    {
        $items = [];

        if (($states['retail_operations']['eligible'] ?? false) === true) {
            $items[] = $this->individualModuleItem('retail', $states);
        }

        if (($states['repair_operations']['eligible'] ?? false) === true) {
            $items[] = $this->individualModuleItem('repair', $states);
        }

        if (($states['crm']['eligible'] ?? false) === true) {
            $items[] = $this->individualModuleItem('customers', $states, 'Customer Management');
        }

        $cashierActiveMatching = [$this->canonicalUrl('shop-owner.shell.operate.payments')];
        foreach (['retail_operations' => 'retail', 'repair_operations' => 'repair'] as $moduleKey => $slug) {
            if (($states[$moduleKey]['accessible'] ?? false) !== true) {
                continue;
            }

            $routeName = 'shop-owner.erp.'.$slug.'.point-of-sale';
            $this->assertOwnerRoute($routeName);
            $cashierActiveMatching[] = $this->pathForRoute($routeName).'*';
        }

        if (count($cashierActiveMatching) > 1) {
            $items[] = new OwnerShellItem(
                'cashier',
                'Cashier',
                $cashierActiveMatching[0],
                true,
                null,
                null,
                $cashierActiveMatching,
            );
        }

        $items = array_values(array_filter($items));
        if ($items === []) {
            return null;
        }

        return new OwnerShellGroup(
            'operate',
            'Operate',
            10,
            true,
            $items,
        );
    }

    /**
     * @param array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}> $states
     */
    private function individualModuleItem(
        string $destinationKey,
        array $states,
        ?string $label = null,
    ): ?OwnerShellItem
    {
        $destination = self::MODULE_DESTINATIONS[$destinationKey];
        $state = $states[$destination['module']] ?? null;
        if (! is_array($state) || ($state['eligible'] ?? false) !== true) {
            return null;
        }

        $canonicalUrl = $this->canonicalUrl($destination['route']);
        $activeMatching = $this->moduleActiveMatching($destination['module']);

        if (($state['accessible'] ?? false) === true) {
            return new OwnerShellItem(
                $destinationKey,
                $label ?? $destination['label'],
                $canonicalUrl,
                true,
                null,
                null,
                $activeMatching,
            );
        }

        if (($state['code'] ?? null) !== 'MODULE_DISABLED') {
            return null;
        }

        return new OwnerShellItem(
            $destinationKey,
            $label ?? $destination['label'],
            $canonicalUrl,
            false,
            'module_disabled',
            $this->canonicalUrl('shop-owner.shell.settings.modules-team'),
            $activeMatching,
        );
    }

    private function reportsGroup(): OwnerShellGroup
    {
        $reportsUrl = $this->canonicalUrl('shop-owner.shell.reports');
        $auditUrl = $this->canonicalUrl('shop-owner.shell.audit');
        $this->assertOwnerRoute('shop-owner.erp.manager.reports');
        $this->assertOwnerRoute('shop-owner.shell.audit');

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
                    [$auditUrl],
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

}
