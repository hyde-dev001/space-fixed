<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ReadPageController extends Controller
{
    public function hrAuditLogs(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/HR/AuditLogs');
    }

    public function financeAuditLogs(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/Finance/AuditLogs');
    }

    public function managerReports(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/Manager/Reports');
    }

    public function managerAuditLogs(): Response|RedirectResponse
    {
        return $this->renderEmployeePage('ERP/Manager/AuditLogs');
    }

    public function inventoryDashboard(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $shopOwnerId = $this->shopOwnerId();
        $initialData = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(200);
        $initialMetrics = [
            'total_items' => InventoryItem::where('shop_owner_id', $shopOwnerId)->where('is_active', true)->count(),
            'low_stock_count' => InventoryItem::where('shop_owner_id', $shopOwnerId)->lowStock()->count(),
            'out_of_stock_count' => InventoryItem::where('shop_owner_id', $shopOwnerId)->outOfStock()->count(),
        ];

        return Inertia::render('ERP/inventory/InventoryDashboard', compact('initialData', 'initialMetrics'));
    }

    public function productInventory(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $this->shopOwnerId())
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(200);

        return Inertia::render('ERP/inventory/ProductInventory', compact('initialData'));
    }

    public function stockMovement(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = StockMovement::with(['inventoryItem', 'performer'])
            ->whereHas('inventoryItem', fn ($query) => $query->where('shop_owner_id', $this->shopOwnerId()))
            ->orderBy('performed_at', 'desc')
            ->paginate(200);

        return Inertia::render('ERP/inventory/StockMovement', compact('initialData'));
    }

    public function procurementSuppliers(): Response|RedirectResponse
    {
        if ($redirect = $this->employeePasswordRedirect()) {
            return $redirect;
        }

        $initialData = Supplier::where('shop_owner_id', $this->shopOwnerId())
            ->orderBy('name')
            ->paginate(100);

        return Inertia::render('ERP/Procurement/SuppliersManagement', compact('initialData'));
    }

    private function renderEmployeePage(string $component): Response|RedirectResponse
    {
        return $this->employeePasswordRedirect() ?? Inertia::render($component);
    }

    private function employeePasswordRedirect(): ?RedirectResponse
    {
        $context = $this->erpContext();
        $user = Auth::guard('user')->user();

        if (! $context instanceof ErpActorContext && $user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return null;
    }

    private function shopOwnerId(): int
    {
        $context = $this->erpContext();
        if ($context instanceof ErpActorContext) {
            return (int) $context->tenantOwner()->getKey();
        }

        $user = Auth::guard('user')->user();
        if (! $user) {
            abort(403);
        }

        return (int) ($user->shop_owner_id ?? $user->id);
    }

    private function erpContext(): ?ErpActorContext
    {
        $context = request()->attributes->get('erp.actor_context');

        return $context instanceof ErpActorContext ? $context : null;
    }
}
