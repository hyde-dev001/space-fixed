<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryIncidentController extends Controller
{
    public function store(Request $request, ShipmentLeg $leg, DeliveryIncidentService $service): JsonResponse
    {
        $user = Auth::guard('user')->user();
        abort_unless($user instanceof User, 403);
        $rider = RiderProfile::where('linked_type', User::class)->where('linked_id', $user->id)->firstOrFail();
        $data = $request->validate(['type' => ['required', 'in:damaged,lost,vehicle_problem,customer_dispute,other'], 'notes' => ['required', 'string'], 'photo_paths' => ['required', 'array', 'min:1'], 'photo_paths.*' => ['string']]);
        return response()->json(['incident' => $service->report($leg, $rider, $data)], 201);
    }

    public function resolve(Request $request, DeliveryIncident $incident, DeliveryIncidentService $service): JsonResponse
    {
        $shop = Auth::guard('shop_owner')->user();
        if (!$shop) {
            $user = Auth::guard('user')->user();
            abort_unless($user?->shop_owner_id && $user->can('resolve-logistics-exceptions'), 403);
            $shop = ShopOwner::findOrFail($user->shop_owner_id);
        }
        $data = $request->validate(['resolution' => ['required', 'string'], 'note' => ['required', 'string'], 'evidence' => ['array'], 'evidence.*' => ['string']]);
        return response()->json(['incident' => $service->resolve($incident, $shop, $data['resolution'], $data['note'], $data['evidence'] ?? [])]);
    }
}
