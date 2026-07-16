<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\DeliveryAttempt;
use App\Services\Logistics\CustomerTrackingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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

    public function attemptProof(Shipment $shipment, DeliveryAttempt $attempt, CustomerTrackingService $tracking)
    {
        $customer = Auth::guard('user')->user();
        $attempt->loadMissing('leg');

        if (!$customer || !$tracking->customerOwnsShipment($shipment, (int) $customer->id)) {
            abort(403);
        }
        if ((int) $attempt->leg?->shipment_id !== (int) $shipment->id) {
            abort(403);
        }
        if (!$attempt->file_path || !Storage::disk('public')->exists($attempt->file_path)) {
            abort(404);
        }

        return Storage::disk('public')->response($attempt->file_path);
    }
}
