<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\AssignShipmentLegRequest;
use App\Http\Requests\Logistics\RecordHandoffProofRequest;
use App\Enums\Logistics\LogisticsAction;
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
use App\Services\Logistics\ProofService;
use App\Services\Logistics\ProofReviewService;
use App\Services\Logistics\LogisticsActorPolicy;
use App\Services\Logistics\ShipmentLegService;
use App\Services\RepairDeliveryService;
use App\Services\DeliveryDisputeService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    public function __construct(
        private LogisticsActorPolicy $policy,
    ) {}

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
        $shop = $this->authorizeAction(LogisticsAction::ASSIGN_RIDER, $leg);
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
        $shop = $this->authorizeAction(LogisticsAction::SUBMIT_PROOF, $leg, null, false);
        $leg->loadMissing('shipment');

        $payload = $request->validated();
        $actor = $this->authenticatedActor(false);
        $rider = $this->assignedRiderProfile($leg);
        $payload['confirmed_by_type'] = $actor::class;
        $payload['confirmed_by_id'] = $actor->getAuthIdentifier();
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
        $rider = $this->authorizeLegUpdate($leg);
        return response()->json(['leg' => $legs->markPickedUp($leg, $rider)]);
    }

    public function inTransit(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $rider = $this->authorizeLegUpdate($leg);
        return response()->json(['leg' => $legs->markInTransit($leg, $rider)]);
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
        $rider = $this->authorizeLegUpdate($leg);
        return response()->json(['leg' => $legs->markDelivered($leg, $rider)]);
    }

    public function approveProof(HandoffProof $proof, ProofReviewService $reviews): JsonResponse
    {
        $proof->loadMissing('leg.shipment');
        $shop = $this->authorizeAction(LogisticsAction::REVIEW_PROOF, $proof->leg, $proof);
        $actor = $this->authenticatedActor();
        $result = $reviews->approve($proof, $actor);

        return response()->json(['leg' => $result['leg']]);
    }

    public function rejectProof(Request $request, HandoffProof $proof, ProofReviewService $reviews): JsonResponse
    {
        $reason = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ])['rejection_reason'];
        $proof->loadMissing('leg.shipment');
        $shop = $this->authorizeAction(LogisticsAction::REVIEW_PROOF, $proof->leg, $proof);

        $actor = $this->authenticatedActor();
        $result = $reviews->reject($proof, $actor, $reason);

        return response()->json(['leg' => $result['leg'], 'proof' => $result['proof']]);
    }

    public function attempts(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $actor = $this->authenticatedActor(false);
        $replayed = $this->replayedAttempt(
            $leg,
            $actor,
            (int) $request->input('delivery_assignment_id'),
            (string) ($request->input('attempt_type') ?: 'delivery'),
            $request->input('idempotency_key'),
        );
        if ($replayed) {
            return response()->json(['attempt' => $replayed], 201);
        }

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
        $actor = $this->authenticatedActor(false);
        $assignmentId = (int) $request->input('delivery_assignment_id');
        $attemptType = (string) ($request->input('attempt_type') ?: 'delivery');
        $replayed = $this->replayedAttempt(
            $leg,
            $actor,
            $assignmentId,
            $attemptType,
            $request->input('idempotency_key'),
        );
        if ($replayed) {
            return response()->json(['attempt' => $replayed], 201);
        }

        $actor = $this->authorizeAttemptActor($leg);
        if ($assignmentId > 0) {
            $this->authorizeAttemptAssignment($leg, $actor, $assignmentId);
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
        $shop = $this->authorizeAction(
            LogisticsAction::RESOLVE_EXCEPTION,
            $leg,
            allowIdempotentTerminalReplay: true,
        );
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
        $shop = $this->authorizeAction(LogisticsAction::RESOLVE_EXCEPTION, $leg);
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->resolveRetry($leg, $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'])]);
    }

    public function returnResolution(Request $request, ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizeAction(LogisticsAction::RESOLVE_EXCEPTION, $leg);
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->requireReturn($leg, $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'])]);
    }

    public function createReturn(ShipmentLeg $leg, ShipmentLegService $legs): JsonResponse
    {
        $shop = $this->authorizeAction(LogisticsAction::RESOLVE_EXCEPTION, $leg);
        $leg->loadMissing('shipment');
        $this->abortUnlessTenant($leg->shipment->shop_owner_id, $shop);

        return response()->json(['leg' => $legs->createReturnToShop($leg)], 201);
    }

    public function confirmReturnHandoff(ShipmentLeg $leg, HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        return response()->json(['leg' => $legs->confirmReturnHandoff($leg, $proof, $this->authorizeReturnHandoff($leg, $proof))]);
    }

    public function confirmReturnReceipt(ShipmentLeg $leg, HandoffProof $proof, ShipmentLegService $legs): JsonResponse
    {
        $leg->loadMissing('shipment');
        $shop = $this->authorizeAction(LogisticsAction::CONFIRM_RETURN_RECEIPT, $leg, $proof);

        return response()->json(['leg' => $legs->confirmReturnReceipt($leg, $proof, $shop)]);
    }

    private function authorizeLegUpdate(ShipmentLeg $leg): RiderProfile
    {
        return $this->authorizeCustody($leg, 'update-logistics-status');
    }

    private function authorizeAttemptActor(ShipmentLeg $leg): Authenticatable
    {
        $this->authorizeCustody($leg, 'update-logistics-status');

        return $this->authenticatedActor(false);
    }

    private function replayedAttempt(
        ShipmentLeg $leg,
        Authenticatable $actor,
        int $assignmentId,
        string $attemptType,
        mixed $idempotencyKey,
    ): ?DeliveryAttempt {
        if (! $actor instanceof User || ! filled($actor->shop_owner_id)) {
            return null;
        }

        $leg->loadMissing('shipment');
        if (! $leg->shipment || (int) $leg->shipment->shop_owner_id !== (int) $actor->shop_owner_id) {
            return null;
        }

        $query = DeliveryAttempt::query()
            ->where('shipment_leg_id', $leg->id)
            ->where('recorded_by_type', $actor::class)
            ->where('recorded_by_id', $actor->getAuthIdentifier());

        if (filled($idempotencyKey)) {
            $query->where('idempotency_key', (string) $idempotencyKey);
        } elseif ($assignmentId > 0) {
            $query
                ->where('delivery_assignment_id', $assignmentId)
                ->where('attempt_type', $attemptType);
        } else {
            return null;
        }

        return $query->first();
    }

    private function authorizeAttemptAssignment(ShipmentLeg $leg, Authenticatable $actor, int $assignmentId): void
    {
        $leg->loadMissing('shipment');
        abort_unless($assignmentId > 0 && $leg->assignments()
            ->whereKey($assignmentId)
            ->whereIn('status', ['assigned', 'accepted'])
            ->whereHas('riderProfile', fn ($query) => $query
                ->where('shop_owner_id', $leg->shipment->shop_owner_id)
                ->where('linked_type', $actor::class)
                ->where('linked_id', $actor->getAuthIdentifier())
                ->where('active', true))
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

    private function abortUnlessTenant(int $shopOwnerId, ShopOwner $shop): void
    {
        if ((int) $shopOwnerId !== (int) $shop->id) {
            abort(403);
        }
    }

    private function assignedRiderProfile(
        ShipmentLeg $leg,
        bool $includeRejected = false,
        bool $preferShopOwner = false,
    ): RiderProfile
    {
        $actor = $this->authenticatedActor($preferShopOwner);
        $shop = $this->shopForActor($actor);
        abort_unless($shop, 403);

        if (! $includeRejected) {
            $this->authorizeCustody($leg);

            $profile = $this->policy->resolveAssignedRider($actor, $shop, $leg);
            abort_unless($profile, 403);

            return $profile;
        }

        $profile = $this->policy->resolveRiderProfile($actor, $shop);
        abort_unless($profile, 403);
        $statuses = $includeRejected ? ['assigned', 'accepted', 'rejected'] : ['assigned', 'accepted'];
        abort_unless($profile->assignments()
            ->where('shipment_leg_id', $leg->id)
            ->whereIn('status', $statuses)
            ->exists(), 403);

        return $profile;
    }

    private function authorizeAction(
        LogisticsAction $action,
        ShipmentLeg $leg,
        ?HandoffProof $proof = null,
        bool $preferShopOwner = true,
        bool $allowIdempotentTerminalReplay = false,
    ): ShopOwner
    {
        $actor = $this->authenticatedActor($preferShopOwner);
        $shop = $this->shopForActor($actor);
        abort_unless($shop, 403);

        $decision = $this->policy->decide(
            $actor,
            $action,
            $shop,
            $leg,
            $proof,
            $allowIdempotentTerminalReplay,
        );
        if (! $decision['allowed']) {
            $this->logDenial($actor, $shop, $decision['action'], $decision['reason_category']);
            abort(403);
        }

        return $shop;
    }

    private function authorizeCustody(ShipmentLeg $leg, ?string $requiredCapability = null): RiderProfile
    {
        $actor = $this->authenticatedActor(false);
        $shop = $this->shopForActor($actor);
        abort_unless($shop, 403);

        $decision = $this->policy->decideCustody($actor, $shop, $leg, $requiredCapability);
        if (! $decision['allowed']) {
            $this->logDenial($actor, $shop, $decision['action'], $decision['reason_category']);
            abort(403);
        }

        $rider = $this->policy->resolveAssignedRider($actor, $shop, $leg);
        abort_unless($rider, 403);

        return $rider;
    }

    private function authorizeReturnHandoff(ShipmentLeg $leg, HandoffProof $proof): RiderProfile
    {
        $actor = $this->authenticatedActor(false);
        $shop = $this->shopForActor($actor);
        abort_unless($shop, 403);

        $decision = $this->policy->decideReturnHandoff($actor, $shop, $leg, $proof);
        if (! $decision['allowed']) {
            $this->logDenial($actor, $shop, $decision['action'], $decision['reason_category']);
            abort(403);
        }

        $rider = $this->policy->resolveAssignedRider($actor, $shop, $leg);
        abort_unless($rider, 403);

        return $rider;
    }

    private function authenticatedActor(bool $preferShopOwner = true): Authenticatable
    {
        $guards = $preferShopOwner ? ['shop_owner', 'user'] : ['user', 'shop_owner'];
        $actor = collect($guards)
            ->map(fn (string $guard) => Auth::guard($guard)->user())
            ->first(fn ($candidate) => $candidate instanceof Authenticatable);
        abort_unless($actor instanceof Authenticatable, 403);

        return $actor;
    }

    private function shopForActor(Authenticatable $actor): ?ShopOwner
    {
        if ($actor instanceof ShopOwner) {
            return $actor;
        }

        if (! $actor instanceof User || ! $actor->shop_owner_id) {
            return null;
        }

        return $actor->shopOwner ?: ShopOwner::query()->find($actor->shop_owner_id);
    }

    private function logDenial(
        Authenticatable $actor,
        ShopOwner $shop,
        string $action,
        ?string $reasonCategory,
    ): void {
        Log::warning('Logistics action denied', [
            'domain' => 'logistics',
            'action' => $action,
            'actor_guard' => $actor instanceof ShopOwner ? 'shop_owner' : 'user',
            'actor_type' => $actor::class,
            'shop_id' => (int) $shop->id,
            'denial_category' => $reasonCategory,
            'route_name' => (string) (request()->route()?->getName() ?? ''),
            'correlation_id' => request()->header('X-Correlation-ID'),
            'request_id' => request()->header('X-Request-ID'),
        ]);
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
