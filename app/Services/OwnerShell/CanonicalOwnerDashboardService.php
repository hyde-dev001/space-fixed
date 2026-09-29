<?php

declare(strict_types=1);

namespace App\Services\OwnerShell;

use App\Http\Controllers\Api\CRM\CRMDashboardController;
use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Logistics\ErpLogisticsController;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

final class CanonicalOwnerDashboardService
{
    public function __construct(
        private readonly ReadPageController $readPages,
        private readonly CRMDashboardController $crmDashboard,
        private readonly ErpLogisticsController $logisticsDashboard,
        private readonly CanonicalOwnerOverviewService $overview,
    ) {}

    /**
     * @param  array<string, mixed>  $activeModule
     */
    public function render(string $moduleKey, int $shopOwnerId, array $activeModule): Response|RedirectResponse
    {
        $response = match ($moduleKey) {
            'retail_operations' => Inertia::render('ShopOwner/Dashboard', [
                'erpMode' => true,
            ]),
            'repair_operations' => $this->readPages->repairDashboard(),
            'crm' => $this->crmDashboard->indexPage(),
            'finance' => $this->readPages->financeDashboard(),
            'hr_employees' => $this->readPages->hrDashboard(),
            'inventory' => $this->readPages->inventoryDashboard(),
            'procurement' => $this->procurementDashboard($moduleKey, $shopOwnerId),
            'logistics' => $this->logisticsDashboard->dashboard(),
            default => throw new LogicException("Canonical owner dashboard is missing for {$moduleKey}."),
        };

        if ($response instanceof Response) {
            return $response->with([
                'tenantOwnerId' => $shopOwnerId,
                'activeModule' => $activeModule,
                'navigationMode' => 'module',
                'urls' => [
                    'portal' => route('shop-owner.dashboard'),
                    'settings' => route('shop-owner.settings'),
                ],
            ]);
        }

        return $response;
    }

    /**
     * @return Response
     */
    private function procurementDashboard(string $moduleKey, int $shopOwnerId): Response
    {
        $dashboard = $this->overview->forModule($moduleKey, $shopOwnerId);
        $dashboard['links'] = [
            'purchase_requests' => route('shop-owner.erp.procurement.purchase-request'),
            'purchase_orders' => route('shop-owner.erp.procurement.purchase-orders'),
        ];

        return Inertia::render('ERP/Procurement/Dashboard', compact('dashboard'));
    }
}
