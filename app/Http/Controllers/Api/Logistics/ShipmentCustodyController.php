<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentCustodyTransfer;
use App\Models\Logistics\ShipmentLeg;
use App\Models\User;
use App\Services\Logistics\ShipmentCustodyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ShipmentCustodyController extends Controller
{
    public function store(Request $request, ShipmentLeg $leg, ShipmentCustodyService $custody): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'to_type' => ['required', 'in:rider,shop'],
            'to_rider_profile_id' => ['required_if:to_type,rider', 'nullable', 'integer'],
            'request_key' => ['required', 'uuid'],
        ]);
        return response()->json(['transfer' => $custody->request($leg, $actor, $data['to_type'], $data['to_rider_profile_id'] ?? null, $data['request_key'])], 201);
    }

    public function release(Request $request, ShipmentCustodyTransfer $transfer, ShipmentCustodyService $custody): JsonResponse
    {
        return $this->confirm($request, $transfer, $custody, false);
    }

    public function cancel(Request $request, ShipmentCustodyTransfer $transfer, ShipmentCustodyService $custody): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return response()->json(['transfer' => $custody->cancel($transfer, $actor, $data['reason'])]);
    }

    public function receive(Request $request, ShipmentCustodyTransfer $transfer, ShipmentCustodyService $custody): JsonResponse
    {
        return $this->confirm($request, $transfer, $custody, true);
    }

    private function confirm(Request $request, ShipmentCustodyTransfer $transfer, ShipmentCustodyService $custody, bool $receipt): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        $path = $request->file('photo')->store('logistics-proof/custody', 'local');
        abort_unless(is_string($path) && $path !== '', 500);
        try {
            $result = $receipt ? $custody->receive($transfer, $actor, $path) : $custody->release($transfer, $actor, $path);
            return response()->json(['transfer' => $result]);
        } finally {
            // A replay or rejected request must not leak a new upload or delete committed evidence.
            if (! HandoffProof::where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
