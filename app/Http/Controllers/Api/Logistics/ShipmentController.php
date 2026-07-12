<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\AssignShipmentLegRequest;
use App\Http\Requests\Logistics\RecordHandoffProofRequest;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\ProofService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShipmentController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = $this->authorizedShop('view-logistics-shipments');

        $shipments = Shipment::query()
            ->with(['legs.shippingMethod', 'legs.assignments.riderProfile'])
            ->where('shop_owner_id', $shop->id)
            ->latest()
            ->paginate(20);

        return response()->json($shipments);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        $shop = $this->authorizedShop('view-logistics-shipments');
        $this->abortUnlessTenant($shipment->shop_owner_id, $shop);

        return response()->json([
            'shipment' => $shipment->load(['legs.proofs', 'legs.assignments.riderProfile', 'events']),
        ]);
    }

    public function assign(
        AssignShipmentLegRequest $request,
        ShipmentLeg $leg,
        AssignmentService $assignments
    ): JsonResponse {
        $shop = $this->authorizedShop('assign-logistics-deliveries');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        $rider = RiderProfile::query()->findOrFail($request->integer('rider_profile_id'));
        $assignment = $assignments->assignInternalRider($leg, $rider, $shop);

        return response()->json([
            'assignment' => $assignment->load('riderProfile'),
            'leg' => $leg->fresh(),
        ]);
    }

    public function proof(
        RecordHandoffProofRequest $request,
        ShipmentLeg $leg,
        ProofService $proofs
    ): JsonResponse {
        $shop = $this->authorizedShop('record-logistics-proof');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        $payload = $request->validated();
        if ($request->hasFile('proof_file')) {
            $payload['file_path'] = $request->file('proof_file')->store('logistics-proof/' . $leg->id, 'public');
        }

        return response()->json([
            'proof' => $proofs->recordProof($leg, $payload),
        ], 201);
    }

    public function pickedUp(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);

        return response()->json(['leg' => $legs->markPickedUp($leg)]);
    }

    public function inTransit(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);

        return response()->json(['leg' => $legs->markInTransit($leg)]);
    }

    public function delivered(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);

        return response()->json(['leg' => $legs->markDelivered($leg)]);
    }

    public function approveProof(HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShopForProofApproval();
        $proof->loadMissing('leg.shipment');
        $this->abortUnlessTenant($proof->leg->shipment->shop_owner_id, $shop);
        abort_unless(Auth::guard('shop_owner')->check() || $this->canApproveProof(Auth::guard('user')->user()), 403);
        abort_unless($proof->review_status === 'pending', 422);

        $actor = Auth::guard('user')->user() ?? $shop;
        $proof->update(['review_status' => 'approved', 'reviewed_by_type' => $actor::class, 'reviewed_by_id' => $actor->id, 'reviewed_at' => now()]);

        return response()->json(['leg' => $legs->markDelivered($proof->leg->fresh())]);
    }

    public function attempts(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);
        $payload = $request->validate([
            'attempt_type' => ['nullable', 'in:pickup,delivery'],
            'reason_code' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'attempted_at' => ['nullable', 'date'],
            'next_attempt_at' => ['nullable', 'date'],
        ]);

        return response()->json([
            'attempt' => $legs->recordFailedAttempt($leg, $payload),
        ], 201);
    }

    public function reportIssue(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $actor = $this->authorizeIssueReport($leg);
        $payload = $request->validate([
            'reason_code' => ['required', 'in:recipient_unavailable,wrong_or_incomplete_address,recipient_refused,vehicle_or_delivery_problem,other'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json([
            'attempt' => $legs->recordFailedAttempt($leg, [
                ...$payload,
                'attempt_type' => 'delivery',
                'recorded_by_type' => $actor::class,
                'recorded_by_id' => $actor->id,
            ], true),
        ], 201);
    }

    public function cancel(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShop('assign-logistics-deliveries');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        $attempt = $leg->attempts()
            ->where('attempt_type', 'delivery')
            ->where('status', 'failed')
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->first();
        abort_unless($attempt, 422);

        $message = [
            'recipient_unavailable' => 'Recipient was unavailable',
            'wrong_or_incomplete_address' => 'Address could not be completed',
            'recipient_refused' => 'Recipient refused the delivery',
            'vehicle_or_delivery_problem' => 'A delivery problem prevented completion',
            'other' => 'Delivery could not be completed',
        ][$attempt->reason_code] ?? 'Delivery could not be completed';

        return response()->json([
            'leg' => $legs->cancel($leg, $message),
            'message' => $message,
        ]);
    }

    private function authorizeLegUpdate(ShipmentLeg $leg): ShopOwner
    {
        $shop = $this->authorizedShop('update-logistics-status');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);
        $this->abortIfUserCannotOperateLeg($leg);

        return $shop;
    }

    private function authorizeIssueReport(ShipmentLeg $leg): User
    {
        $user = Auth::guard('user')->user();
        if (!$user instanceof User || !$user->can('update-logistics-status') || !$user->shopOwner) {
            abort(403);
        }

        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $user->shopOwner);
        abort_unless($this->userHasActiveAssignment($leg, $user), 403);

        return $user;
    }

    private function authorizedShop(string $permission): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }

        $user = Auth::guard('user')->user();
        if (!$user instanceof User || !$user->shopOwner) {
            abort(403);
        }

        if (!$user->can($permission)) {
            abort(403);
        }

        return $user->shopOwner;
    }

    private function authorizedShopForProofApproval(): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }

        $user = Auth::guard('user')->user();
        if (!$user instanceof User || !$user->shopOwner || !$this->canApproveProof($user)) {
            abort(403);
        }

        return $user->shopOwner;
    }

    private function canApproveProof(?User $user): bool
    {
        return $user && ($user->can('approve-proof-of-delivery') || $user->can('assign-logistics-deliveries'));
    }

    private function abortUnlessTenant(int $shopOwnerId, ShopOwner $shop): void
    {
        if ((int) $shopOwnerId !== (int) $shop->id) {
            abort(403);
        }
    }

    private function abortIfUserCannotOperateLeg(ShipmentLeg $leg): void
    {
        if (Auth::guard('shop_owner')->check()) {
            return;
        }

        $user = Auth::guard('user')->user();
        if (!$user instanceof User) {
            abort(403);
        }

        if ($user->can('assign-logistics-deliveries') || $user->can('manage-logistics-riders')) {
            return;
        }

        if (!$this->userHasActiveAssignment($leg, $user)) {
            abort(403);
        }
    }

    private function userHasActiveAssignment(ShipmentLeg $leg, User $user): bool
    {
        return $leg->assignments()
            ->whereIn('status', ['assigned', 'accepted'])
            ->whereHas('riderProfile', function ($query) use ($user) {
                $query->where('linked_type', User::class)
                    ->where('linked_id', $user->id);
            })
            ->exists();
    }
}
