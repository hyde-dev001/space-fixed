<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ErpLogisticsController extends Controller
{
    public function dashboard(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId();

        return Inertia::render('ERP/Logistics/Dashboard', [
            'stats' => $this->stats($shopOwnerId),
        ]);
    }

    public function shipments(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId();

        return Inertia::render('ERP/Logistics/Shipments', [
            'shipments' => Shipment::query()
                ->with('legs')
                ->where('shop_owner_id', $shopOwnerId)
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }

    public function riders(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId();

        return Inertia::render('ERP/Logistics/Riders', [
            'riders' => RiderProfile::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function authorizedShopOwnerId(): int
    {
        $user = Auth::guard('user')->user();
        if (!$user || !$user->shop_owner_id || !$user->can('access-logistics-dashboard')) {
            abort(403);
        }

        return (int) $user->shop_owner_id;
    }

    private function stats(int $shopOwnerId): array
    {
        $query = Shipment::query()->where('shop_owner_id', $shopOwnerId);

        return [
            'requested' => (clone $query)->where('status', 'requested')->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
        ];
    }
}
