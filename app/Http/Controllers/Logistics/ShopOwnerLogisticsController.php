<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Services\Logistics\RiderProfileSyncService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ShopOwnerLogisticsController extends Controller
{
    public function dashboard(): Response
    {
        $shop = Auth::guard('shop_owner')->user() ?? abort(403);

        return Inertia::render('ShopOwner/Logistics/Dashboard', [
            'stats' => $this->stats((int) $shop->id),
        ]);
    }

    public function shipments(): Response
    {
        $shop = Auth::guard('shop_owner')->user() ?? abort(403);

        return Inertia::render('ShopOwner/Logistics/Shipments', [
            'shipments' => Shipment::query()
                ->with('legs')
                ->where('shop_owner_id', $shop->id)
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }

    public function riders(): Response
    {
        $shop = Auth::guard('shop_owner')->user() ?? abort(403);
        app(RiderProfileSyncService::class)->syncShop((int) $shop->id);

        return Inertia::render('ShopOwner/Logistics/Riders', [
            'riders' => RiderProfile::query()
                ->where('shop_owner_id', $shop->id)
                ->orderBy('name')
                ->get(),
        ]);
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
