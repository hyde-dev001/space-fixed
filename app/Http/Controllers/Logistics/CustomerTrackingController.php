<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\Shipment;
use App\Services\Logistics\CustomerTrackingService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CustomerTrackingController extends Controller
{
    public function show(Shipment $shipment, CustomerTrackingService $tracking): Response
    {
        $customer = Auth::guard('user')->user();

        if (!$customer || !$tracking->customerOwnsShipment($shipment, (int) $customer->id)) {
            abort(403);
        }

        return Inertia::render('UserSide/Tracking/ShipmentTracking', [
            'shipment' => $tracking->payload($shipment),
        ]);
    }
}
