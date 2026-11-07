<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\CashierDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class CashierDashboardController extends Controller
{
    public function __construct(
        private readonly CashierDashboardService $dashboard,
    ) {
    }

    public function index(): Response|RedirectResponse
    {
        $user = Auth::guard('user')->user();

        if ($user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $shopOwnerId = (int) ($user?->shop_owner_id ?? 0);
        if (!$user || $shopOwnerId <= 0) {
            abort(403, 'A shop assignment is required to view the Cashier dashboard.');
        }

        return Inertia::render('ERP/cashier/Dashboard', [
            'dashboard' => $this->dashboard->snapshot($shopOwnerId),
        ]);
    }
}
