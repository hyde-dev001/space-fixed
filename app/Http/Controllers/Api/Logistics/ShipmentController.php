<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\AssignShipmentLegRequest;
use App\Http\Requests\Logistics\RecordHandoffProofRequest;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\DeliveryDispute;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ArrivalService;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\DeliveryEventService;
use App\Services\Logistics\ProofService;
use App\Services\Logistics\ShipmentLegService;
use App\Services\RepairDeliveryService;
use App\Services\DeliveryDisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

    public function investigateDispute(DeliveryDispute $dispute, DeliveryDisputeService $disputes): JsonResponse
    {
        $shop = $this->authorizedShop('resolve-logistics-exceptions');
        $this->abortUnlessTenant($dispute->shop_owner_id, $shop);

        $actor = Auth::guard('user')->user() ?? $shop;

        return response()->json([
            'dispute' => $disputes->investigate($dispute, $actor),
        ]);
    }

    public function resolveDispute(Request $request, DeliveryDispute $dispute, DeliveryDisputeService $disputes): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => ['required', 'string', Rule::in(DeliveryDisputeService::RESOLUTIONS)],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $shop = $this->authorizedShop('resolve-logistics-exceptions');
        $this->abortUnlessTenant($dispute->shop_owner_id, $shop);

        $actor = Auth::guard('user')->user() ?? $shop;

        return response()->json($disputes->resolve(
            $dispute,
            $actor,
            (string) $validated['resolution'],
            $validated['resolution_note'] ?? null,
        ));
    }

    public function assign(
        AssignShipmentLegRequest $request,
        ShipmentLeg $leg,
        AssignmentService $assignments
    ): JsonResponse {
        $shop = $this->authorizedShop('assign-logistics-deliveries');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        $rider = RiderProfile::query()
            ->where('shop_owner_id', $shop->id)
            ->findOrFail($request->integer('rider_profile_id'));
        $assignment = $assignments->assignInternalRider($leg, $rider, $shop);

        return response()->json([
            'assignment' => $assignment->load('riderProfile'),
            'leg' => $leg->fresh(),
        ]);
    }

    public function acceptOffer(ShipmentLeg $leg, AssignmentService $assignments): JsonResponse
    {
        return response()->json([
            'assignment' => $assignments->respondToOffer($leg, $this->assignedRiderProfile($leg), true),
            'leg' => $leg->fresh(),
        ]);
    }

    public function rejectOffer(Request $request, ShipmentLeg $leg, AssignmentService $assignments): JsonResponse
    {
        $reason = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ])['rejection_reason'];

        return response()->json([
            'assignment' => $assignments->respondToOffer($leg, $this->assignedRiderProfile($leg, true), false, $reason),
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
        $user = Auth::guard('user')->user();
        $backOffice = $user && ($user->can('assign-logistics-deliveries') || $user->can('approve-proof-of-delivery'));
        $rider = $backOffice ? null : $this->riderProfileIfAssigned($leg);
        if ($user && ! $backOffice && ! $rider) {
            abort(403);
        }
        $storedPath = $request->file('proof_file')
            ?->store("logistics-proof/{$leg->id}", 'local');
        if ($storedPath) {
            $payload['file_path'] = $storedPath;
        }

        try {
            $proof = $proofs->recordProof($leg, $payload, $rider);
            if ($storedPath && $proof->file_path !== $storedPath) {
                $this->cleanupStoredProof($storedPath);
            }

            $proof->setAttribute('proof_url', $this->proofUrl($proof));

            return response()->json(['proof' => $proof], $proof->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $exception) {
            if ($storedPath) {
                $this->cleanupStoredProof($storedPath);
            }
            throw $exception;
        }
    }

    public function proofFile(HandoffProof $proof)
    {
        $proof->loadMissing('leg.shipment');
        $shipment = $proof->leg->shipment;
        $isRefundReturn = $shipment->source_type === 'order_refund'
            && $shipment->purpose === 'refund_return'
            && in_array($proof->leg->leg_type, ['inbound', 'return_to_shop'], true);
        $user = Auth::guard('user')->user();
        $isStaffJobOrderProof = $user?->can('access-staff-job-orders')
            && ($isRefundReturn || ($shipment->source_type === 'order' && $shipment->purpose === 'retail_delivery'));
        $shop = $this->authorizedShop($isStaffJobOrderProof
            ? 'access-staff-job-orders'
            : 'assign-logistics-deliveries');
        $this->abortUnlessTenant((int) $proof->leg->shipment->shop_owner_id, $shop);
        abort_unless($proof->file_path && Storage::disk('local')->exists($proof->file_path), 404);

        return Storage::disk('local')->response($proof->file_path);
    }

    public function attemptFile(DeliveryAttempt $attempt)
    {
        $shop = $this->authorizedAttemptEvidenceShop();
        $attempt->loadMissing('leg.shipment');
        $shipment = $attempt->leg?->shipment;
        abort_unless($shipment, 404);
        $this->abortUnlessTenant((int) $shipment->shop_owner_id, $shop);

        $path = (string) $attempt->getRawOriginal('file_path');
        abort_unless($this->isSafeAttemptEvidencePath($path), 404);

        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $private = Storage::disk('local');
        abort_unless($private->exists($path), 404);

        return $private->response($path, null, $headers);
    }

    public function pickedUp(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);
        $user = Auth::guard('user')->user();
        $rider = ! $leg->delivery_batch_id || ($user instanceof User && $this->userHasActiveAssignment($leg, $user))
            ? $this->assignedRiderProfile($leg)
            : null;
        return response()->json(['leg' => $legs->markPickedUp($leg, $rider)]);
    }

    public function inTransit(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);
        return response()->json(['leg' => $legs->markInTransit($leg, $this->riderProfileIfAssigned($leg))]);
    }

    public function confirmPickup(ShipmentLeg $leg, HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        return response()->json(['leg' => $legs->confirmPickup($leg, $proof, $this->assignedRiderProfile($leg))]);
    }

    public function rejectPickup(Request $request, ShipmentLeg $leg, HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return response()->json(['leg' => $legs->rejectPickup($leg, $proof, $this->assignedRiderProfile($leg), $reason)]);
    }

    public function outForDelivery(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        return response()->json(['leg' => $legs->markOutForDelivery($leg, $this->assignedRiderProfile($leg))]);
    }

    public function delivered(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $this->authorizeLegUpdate($leg);
        return response()->json(['leg' => $legs->markDelivered($leg, $this->riderProfileIfAssigned($leg))]);
    }

    public function approveProof(HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShopForProofApproval();
        $proof->loadMissing('leg.shipment');
        $this->abortUnlessTenant($proof->leg->shipment->shop_owner_id, $shop);
        abort_unless(Auth::guard('shop_owner')->check() || $this->canApproveProof(Auth::guard('user')->user()), 403);
        abort_unless($proof->review_status === 'pending', 422);

        $actor = Auth::guard('user')->user() ?? $shop;
        $leg = DB::transaction(function () use ($proof, $actor, $legs) {
            $locked = HandoffProof::query()->with('leg')->lockForUpdate()->findOrFail($proof->id);
            abort_unless($locked->review_status === 'pending', 422);
            abort_unless(in_array($locked->handoff_type, ['delivery', 'receive'], true), 422);
            abort_unless($locked->leg->status->value === 'awaiting_proof_approval', 422);
            $locked->update(['review_status' => 'approved', 'reviewed_by_type' => $actor::class, 'reviewed_by_id' => $actor->id, 'reviewed_at' => now()]);

            return $legs->markDelivered($locked->leg);
        });

        return response()->json(['leg' => $leg]);
    }

    public function rejectProof(Request $request, HandoffProof $proof, DeliveryEventService $events): JsonResponse
    {
        $reason = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ])['rejection_reason'];
        $shop = $this->authorizedShopForProofApproval();
        $proof->loadMissing('leg.shipment');
        $this->abortUnlessTenant($proof->leg->shipment->shop_owner_id, $shop);
        abort_unless(Auth::guard('shop_owner')->check() || $this->canApproveProof(Auth::guard('user')->user()), 403);

        $actor = Auth::guard('user')->user() ?? $shop;
        [$leg, $proof] = DB::transaction(function () use ($proof, $actor, $reason, $events) {
            $locked = HandoffProof::query()->with('leg.shipment')->lockForUpdate()->findOrFail($proof->id);
            abort_unless($locked->review_status === 'pending', 422);
            abort_unless(in_array($locked->handoff_type, ['delivery', 'receive'], true), 422);
            abort_unless($locked->leg->status->value === 'awaiting_proof_approval', 422);

            $locked->update([
                'review_status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by_type' => $actor::class,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $locked->leg->update(['status' => 'in_transit']);
            $riderProfileId = $locked->leg->assignments()->whereIn('status', ['assigned', 'accepted'])->value('rider_profile_id');
            $events->record($locked->leg->shipment, $locked->leg, [
                'event_type' => 'proof_rejected',
                'message' => 'Delivery proof rejected. Submit a replacement proof.',
                'metadata' => ['rider_profile_id' => $riderProfileId, 'rejection_reason' => $reason],
                'created_by_type' => $actor::class,
                'created_by_id' => $actor->id,
            ]);

            return [$locked->leg->fresh(), $locked->fresh()];
        });

        return response()->json(['leg' => $leg, 'proof' => $proof]);
    }

    public function attempts(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $actor = $this->authorizeAttemptActor($leg);
        $payload = $request->validate([
            'attempt_type' => ['nullable', 'in:pickup,delivery'],
            'delivery_assignment_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
            'reason_code' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'attempted_at' => ['nullable', 'date'],
            'next_attempt_at' => ['nullable', 'date'],
        ]);
        $this->authorizeAttemptAssignment($leg, $actor, (int) $payload['delivery_assignment_id']);
        $payload['recorded_by_type'] = $actor::class;
        $payload['recorded_by_id'] = $actor->id;

        return response()->json([
            'attempt' => $legs->recordFailedAttempt($leg, $payload),
        ], 201);
    }

    public function arrival(Request $request, ShipmentLeg $leg, ArrivalService $arrivals): JsonResponse
    {
        $actor = $this->authorizeAttemptActor($leg);
        abort_unless($this->userHasActiveAssignment($leg, $actor), 403);
        $payload = $request->validate([
            'arrival_type' => ['required', 'in:pickup,dropoff'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'captured_at' => ['nullable', 'date'],
            'exception_reason' => ['nullable', 'in:gps_inaccurate,pin_incorrect,alternate_meeting_point,access_restriction,safety_concern,other'],
            'exception_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $event = $arrivals->record($leg, $actor, $payload);

        return response()->json([
            'arrival' => [
                'id' => $event->id,
                'arrival_type' => $event->event_type === 'pickup_arrived' ? 'pickup' : 'dropoff',
                'result' => data_get($event->metadata, 'result'),
                'distance_m' => data_get($event->metadata, 'distance_m'),
                'radius_m' => data_get($event->metadata, 'radius_m'),
                'accuracy_m' => data_get($event->metadata, 'accuracy_m'),
                'exception_reason' => data_get($event->metadata, 'exception_reason'),
                'exception_notes' => data_get($event->metadata, 'exception_notes'),
                'recorded_at' => $event->created_at?->toISOString(),
            ],
        ], $event->wasRecentlyCreated ? 201 : 200);
    }

    public function reportIssue(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $actor = $this->authorizeAttemptActor($leg);
        $assignmentId = (int) $request->input('delivery_assignment_id');
        if ($assignmentId > 0) {
            $this->authorizeAttemptAssignment($leg, $actor, $assignmentId);
        } else {
            abort_unless($this->userHasActiveAssignment($leg, $actor), 403);
        }
        $attemptType = $request->validate([
            'attempt_type' => ['nullable', 'in:pickup,delivery'],
        ])['attempt_type'] ?? 'delivery';
        $isPickup = $attemptType === 'pickup';
        $payload = $request->validate([
            'attempt_type' => ['nullable', 'in:pickup,delivery'],
            'delivery_assignment_id' => ['required', 'integer'],
            'idempotency_key' => [
                Rule::requiredIf($isPickup),
                'nullable',
                'uuid',
            ],
            'reason_code' => [
                'required',
                Rule::in($isPickup
                    ? ShipmentLegService::PICKUP_REASONS
                    : [
                        ...ShipmentLegService::PHOTO_REQUIRED_REASONS,
                        ...ShipmentLegService::NOTES_REQUIRED_REASONS,
                    ]),
            ],
            'notes' => [
                Rule::requiredIf($isPickup
                    ? $request->input('reason_code') === 'other'
                    : in_array($request->input('reason_code'), ShipmentLegService::NOTES_REQUIRED_REASONS, true)),
                'nullable',
                'string',
                'max:1000',
            ],
            'proof_file' => [
                Rule::requiredIf($isPickup
                    || in_array($request->input('reason_code'), ShipmentLegService::PHOTO_REQUIRED_REASONS, true)),
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240',
            ],
        ]);
        $storedPath = null;
        if ($request->hasFile('proof_file')) {
            $storedPath = $request->file('proof_file')->store('logistics-attempt/'.$leg->id, 'local');
            $payload['file_path'] = $storedPath;
        }

        try {
            $attempt = $legs->recordFailedAttempt($leg, [
                ...$payload,
                'attempt_type' => $attemptType,
                'recorded_by_type' => $actor::class,
                'recorded_by_id' => $actor->id,
            ], $isPickup);
            if ($storedPath && $attempt->file_path !== $storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }

        return response()->json(['attempt' => $attempt], 201);
    }

    public function cancel(
        Request $request,
        ShipmentLeg $leg,
        ShipmentLegService $legs,
        RepairDeliveryService $repairDeliveries,
    ): JsonResponse
    {
        $shop = $this->authorizedShop('assign-logistics-deliveries');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        if ($leg->shipment->source_type === 'repair_request'
            && $leg->shipment->purpose === 'repair_pickup'
            && $leg->resolution_type === 'pickup_failed') {
            $reason = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ])['reason'];
            $repair = RepairRequest::query()
                ->whereKey($leg->shipment->source_id)
                ->where('shop_owner_id', $shop->id)
                ->firstOrFail();
            $target = $repairDeliveries->intakeHandoff($repair, true)['cancellation_target'];
            abort_unless(filled($target['plan_token'] ?? null), 422);
            $result = $repairDeliveries->cancelPaidDeliveryLeg(
                $repair,
                'intake',
                $reason,
                (int) (Auth::guard('user')->id() ?? Auth::guard('shop_owner')->id()),
                $leg->id,
                $target['plan_token'],
                requireFailedPickup: true,
            );

            return response()->json([
                'leg' => $leg->fresh(),
                'message' => 'Pickup cancelled.',
                'reconciliation' => $result['reconciliation'],
            ]);
        }

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
            'item_damaged' => 'The item was damaged',
            'unsafe_location' => 'The delivery location was unsafe',
            'vehicle_or_delivery_problem' => 'A delivery problem prevented completion',
            'other' => 'Delivery could not be completed',
        ][$attempt->reason_code] ?? 'Delivery could not be completed';

        return response()->json([
            'leg' => $legs->cancel($leg, $message),
            'message' => $message,
        ]);
    }

    public function retryResolution(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShop('assign-logistics-deliveries');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->resolveRetry($leg, $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'])]);
    }

    public function returnResolution(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShop('assign-logistics-deliveries');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->requireReturn($leg, $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'])]);
    }

    public function createReturn(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShop('resolve-logistics-exceptions');
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->createReturnToShop($leg)], 201);
    }

    public function confirmReturnHandoff(ShipmentLeg $leg, HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        return response()->json(['leg' => $legs->confirmReturnHandoff($leg, $proof, $this->assignedRiderProfile($leg))]);
    }

    public function confirmReturnReceipt(ShipmentLeg $leg, HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizedShopForProofApproval();
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->confirmReturnReceipt($leg, $proof, $shop)]);
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
        if (! $user instanceof User || ! $user->can('update-logistics-status') || ! $user->shopOwner) {
            abort(403);
        }

        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $user->shopOwner);
        abort_unless($this->userHasActiveAssignment($leg, $user), 403);

        return $user;
    }

    private function authorizeAttemptActor(ShipmentLeg $leg): User
    {
        if (Auth::guard('shop_owner')->check()) {
            abort(403);
        }

        $user = Auth::guard('user')->user();
        if (! $user instanceof User || ! $user->can('update-logistics-status') || ! $user->shopOwner) {
            abort(403);
        }

        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $user->shopOwner);

        return $user;
    }

    private function authorizeAttemptAssignment(ShipmentLeg $leg, User $user, int $assignmentId): void
    {
        $leg->loadMissing('shipment');
        abort_unless($assignmentId > 0 && $leg->assignments()
            ->whereKey($assignmentId)
            ->whereHas('riderProfile', fn ($query) => $query
                ->where('shop_owner_id', $leg->shipment->shop_owner_id)
                ->where('linked_type', User::class)
                ->where('linked_id', $user->id))
            ->exists(), 403);
    }

    private function authorizedShop(string $permission): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }

        $user = Auth::guard('user')->user();
        if (! $user instanceof User || ! $user->shopOwner) {
            abort(403);
        }

        if (! $user->can($permission)) {
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
        if (! $user instanceof User || ! $user->shopOwner || ! $this->canApproveProof($user)) {
            abort(403);
        }

        return $user->shopOwner;
    }

    private function authorizedAttemptEvidenceShop(): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }

        $user = Auth::guard('user')->user();
        if (! $user instanceof User || ! $user->shopOwner
            || (! $user->can('assign-logistics-deliveries') && ! $user->can('resolve-logistics-exceptions'))) {
            abort(403);
        }

        return $user->shopOwner;
    }

    private function isSafeAttemptEvidencePath(string $path): bool
    {
        return str_starts_with($path, 'logistics-attempt/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }

    private function proofUrl(HandoffProof $proof): ?string
    {
        $path = $proof->getRawOriginal('file_path');
        if (! is_string($path)
            || ! str_starts_with($path, 'logistics-proof/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return '/api/logistics/proofs/' . $proof->id . '/file';
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
        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->can('assign-logistics-deliveries') || $user->can('manage-logistics-riders')) {
            return;
        }

        if (! $this->userHasActiveAssignment($leg, $user)) {
            abort(403);
        }
    }

    private function userHasActiveAssignment(ShipmentLeg $leg, User $user): bool
    {
        $leg->loadMissing('shipment');
        abort_unless($leg->shipment, 404);

        return $leg->assignments()
            ->whereIn('status', ['assigned', 'accepted'])
            ->whereHas('riderProfile', function ($query) use ($user, $leg) {
                $query->where('shop_owner_id', $leg->shipment->shop_owner_id)
                    ->where('linked_type', User::class)
                    ->where('linked_id', $user->id);
            })
            ->exists();
    }

    private function assignedRiderProfile(ShipmentLeg $leg, bool $includeRejected = false): RiderProfile
    {
        $user = Auth::guard('user')->user();
        abort_unless($user instanceof User, 403);
        $leg->loadMissing('shipment');
        abort_unless($leg->shipment, 404);
        abort_unless((int) $user->shop_owner_id === (int) $leg->shipment->shop_owner_id, 403);
        $statuses = $includeRejected ? ['assigned', 'accepted', 'rejected'] : ['assigned', 'accepted'];
        $profile = RiderProfile::query()
            ->where('shop_owner_id', $leg->shipment->shop_owner_id)
            ->where('linked_type', User::class)
            ->where('linked_id', $user->id)
            ->whereHas('assignments', fn ($query) => $query->where('shipment_leg_id', $leg->id)->whereIn('status', $statuses))->first();
        abort_unless($profile, 403);

        return $profile;
    }

    private function riderProfileIfAssigned(ShipmentLeg $leg): ?RiderProfile
    {
        if (Auth::guard('shop_owner')->check()) {
            return null;
        }

        $user = Auth::guard('user')->user();
        if ($user instanceof User && $this->userHasActiveAssignment($leg, $user)) {
            return $this->assignedRiderProfile($leg);
        }

        return null;
    }

    private function cleanupStoredProof(string $path): void
    {
        $disk = Storage::disk('local');
        $disk->delete($path);

        $directory = str_replace('\\', '/', dirname($path));
        if ($directory !== '.' && $disk->allFiles($directory) === []) {
            $disk->deleteDirectory($directory);
        }
    }
}
