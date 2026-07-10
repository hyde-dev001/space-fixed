<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Services\Logistics\RiderProfileSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ErpLogisticsController extends Controller
{
    public function dashboard(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('access-logistics-dashboard');

        return Inertia::render('ERP/Logistics/Dashboard', [
            'stats' => $this->stats($shopOwnerId),
        ]);
    }

    public function shipments(Request $request): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('view-logistics-shipments');
        $user = Auth::guard('user')->user();
        $isDispatcher = $user && (
            $user->can('assign-logistics-deliveries') ||
            $user->can('manage-logistics-riders')
        );
        $canAssign = $user && $user->can('assign-logistics-deliveries');
        $status = $request->query('status', 'all');
        $purpose = $request->query('purpose', 'all');

        return Inertia::render('ERP/Logistics/Shipments', [
            'shipments' => Shipment::query()
                ->with(['legs' => function ($query) use ($user, $isDispatcher) {
                    $query->with(['assignments.riderProfile', 'proofs']);

                    if (!$isDispatcher) {
                        $query->whereHas('assignments', function ($assignments) use ($user) {
                            $assignments->whereIn('status', ['assigned', 'accepted'])
                                ->whereHas('riderProfile', function ($riders) use ($user) {
                                    $riders->where('linked_type', User::class)
                                        ->where('linked_id', $user->id);
                                });
                        });
                    }
                }])
                ->where('shop_owner_id', $shopOwnerId)
                ->when($status === 'incomplete', function ($query) {
                    $query->whereNotIn('status', ['completed', 'cancelled']);
                })
                ->when(in_array($status, ['requested', 'active', 'completed', 'cancelled'], true), function ($query) use ($status) {
                    $query->where('status', $status);
                })
                ->when($purpose !== 'all', function ($query) use ($purpose) {
                    $query->where('purpose', $purpose);
                })
                ->when(!$isDispatcher, function ($query) use ($user) {
                    $query->whereHas('legs.assignments', function ($assignments) use ($user) {
                        $assignments->whereIn('status', ['assigned', 'accepted'])
                            ->whereHas('riderProfile', function ($riders) use ($user) {
                                $riders->where('linked_type', User::class)
                                    ->where('linked_id', $user->id);
                            });
                    });
                })
                ->latest()
                ->paginate(10)
                ->withQueryString(),
            'filters' => [
                'status' => $status,
                'purpose' => $purpose,
            ],
            'canAssign' => $canAssign,
            'canUpdateStatus' => $user && $user->can('update-logistics-status'),
            'canRecordProof' => $user && $user->can('record-logistics-proof'),
            'canApproveProof' => $user && $user->can('approve-proof-of-delivery'),
            'assignableRiders' => $canAssign
                ? RiderProfile::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('active', true)
                    ->where('availability_status', 'available')
                    ->orderBy('name')
                    ->get(['id', 'name', 'phone', 'rider_type', 'availability_status'])
                : [],
        ]);
    }

    public function riders(Request $request): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('manage-logistics-riders');
        $availability = $request->query('availability', 'all');
        $type = $request->query('type', 'all');
        app(RiderProfileSyncService::class)->syncShop($shopOwnerId);

        return Inertia::render('ERP/Logistics/Riders', [
            'riders' => RiderProfile::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->when(in_array($availability, ['available', 'busy', 'inactive'], true), function ($query) use ($availability) {
                    $query->where('availability_status', $availability);
                })
                ->when(in_array($type, ['employee', 'contractor', 'shop_owner'], true), function ($query) use ($type) {
                    $query->where('rider_type', $type);
                })
                ->orderBy('name')
                ->paginate(10)
                ->withQueryString(),
            'filters' => [
                'availability' => $availability,
                'type' => $type,
            ],
        ]);
    }

    private function authorizedShopOwnerId(string $permission): int
    {
        $user = Auth::guard('user')->user();
        $hasAccess = $user && $user->can($permission);

        if (!$user || !$user->shop_owner_id || !$hasAccess) {
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
