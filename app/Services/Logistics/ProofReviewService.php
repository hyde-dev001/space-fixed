<?php

namespace App\Services\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\PaymentSettlementService;
use App\Services\RepairDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProofReviewService
{
    public function __construct(
        private ShipmentLegService $legs,
        private DeliveryEventService $events,
        private RepairDeliveryService $repairDeliveries,
        private PaymentSettlementService $settlementService,
    ) {}

    /**
     * @return array{leg: ShipmentLeg, proof: HandoffProof}
     */
    public function approve(HandoffProof $proof, User|ShopOwner $actor): array
    {
        return DB::transaction(function () use ($proof, $actor) {
            [$leg, $lockedProof] = $this->lockProofAndLeg($proof->id);
            $this->assertSupportedHandoff($lockedProof);

            if ($lockedProof->handoff_type === 'receive') {
                throw ValidationException::withMessages([
                    'proof' => 'Return handoff proofs must be confirmed through the return receipt workflow.',
                ]);
            }

            if ($lockedProof->handoff_type === 'delivery') {
                $this->assertCurrentDeliveryProof($leg, $lockedProof);
            }

            if ($lockedProof->review_status === 'rejected') {
                throw ValidationException::withMessages([
                    'proof' => 'A rejected proof cannot be approved. Review its current replacement instead.',
                ]);
            }

            if ($lockedProof->review_status === 'pending') {
                if ($leg->status->value !== 'awaiting_proof_approval') {
                    throw ValidationException::withMessages([
                        'status' => 'Only a proof awaiting review can be approved.',
                    ]);
                }

                $lockedProof->update([
                    'review_status' => 'approved',
                    'reviewed_by_type' => $actor::class,
                    'reviewed_by_id' => $actor->id,
                    'reviewed_at' => now(),
                ]);
            } elseif ($lockedProof->review_status !== 'approved') {
                throw ValidationException::withMessages([
                    'proof' => 'This proof is not available for approval.',
                ]);
            }

            $delivered = $leg->status->value === 'delivered'
                ? $leg
                : $this->legs->markDelivered($leg);

            if ($lockedProof->wasChanged('review_status')) {
                $this->events->record($delivered->shipment, $delivered, [
                    'event_type' => 'proof_approved',
                    'visibility' => 'internal',
                    'message' => 'Delivery proof approved.',
                    'metadata' => [
                        'proof_id' => $lockedProof->id,
                        'replaces_proof_id' => $lockedProof->replaces_proof_id,
                        'business_status' => $delivered->status->value,
                        'rider_progress_state' => $delivered->rider_progress_state?->value,
                    ],
                    'created_by_type' => $actor::class,
                    'created_by_id' => $actor->id,
                ]);
            }

            if ($delivered->shipment->source_type === 'repair_request'
                && $delivered->shipment->purpose === 'repair_return') {
                $repair = RepairRequest::query()->findOrFail($delivered->shipment->source_id);

                if ($repair->shopOwner?->isCompany()) {
                    $this->repairDeliveries->activateReturnHandoff(
                        $repair,
                        $actor,
                        $this->settlementService,
                        'shop_delivery',
                    );
                }
            }

            return [
                'leg' => $delivered->fresh(),
                'proof' => $lockedProof->fresh(),
            ];
        });
    }

    /**
     * @return array{leg: ShipmentLeg, proof: HandoffProof}
     */
    public function reject(HandoffProof $proof, User|ShopOwner $actor, string $reason): array
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'A rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($proof, $actor, $reason) {
            [$leg, $lockedProof] = $this->lockProofAndLeg($proof->id);
            $this->assertSupportedHandoff($lockedProof);

            if ($lockedProof->handoff_type === 'receive') {
                return $this->rejectReceiveProof($leg, $lockedProof, $actor, $reason);
            }

            $this->assertCurrentDeliveryProof($leg, $lockedProof);

            if ($lockedProof->review_status === 'rejected') {
                if ($leg->status->value !== 'proof_correction_required') {
                    throw ValidationException::withMessages([
                        'proof' => 'This proof has already been replaced or is no longer current.',
                    ]);
                }

                return [
                    'leg' => $leg->fresh(),
                    'proof' => $lockedProof->fresh(),
                ];
            }

            if ($lockedProof->review_status !== 'pending'
                || $leg->status->value !== 'awaiting_proof_approval') {
                throw ValidationException::withMessages([
                    'proof' => 'Only the current pending delivery proof can be rejected.',
                ]);
            }

            $lockedProof->update([
                'review_status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by_type' => $actor::class,
                'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $leg->update([
                'status' => 'proof_correction_required',
                'rider_progress_state' => 'proof_action_required',
            ]);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'proof_rejected',
                'visibility' => 'internal',
                'message' => 'Delivery proof rejected. Submit a replacement proof.',
                'metadata' => [
                    'proof_id' => $lockedProof->id,
                    'rider_profile_id' => $this->activeRiderProfileId($leg),
                    'rejection_reason' => $reason,
                    'rider_progress_state' => 'proof_action_required',
                ],
                'created_by_type' => $actor::class,
                'created_by_id' => $actor->id,
            ]);

            return [
                'leg' => $leg->fresh(),
                'proof' => $lockedProof->fresh(),
            ];
        });
    }

    /**
     * @return array{0: ShipmentLeg, 1: HandoffProof}
     */
    private function lockProofAndLeg(int $proofId): array
    {
        $legId = HandoffProof::query()->whereKey($proofId)->value('shipment_leg_id');
        abort_unless($legId, 404);

        $leg = ShipmentLeg::query()
            ->with('shipment')
            ->lockForUpdate()
            ->findOrFail($legId);
        $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proofId);

        if ((int) $proof->shipment_leg_id !== (int) $leg->id) {
            throw ValidationException::withMessages([
                'proof' => 'This proof does not belong to the delivery leg.',
            ]);
        }

        return [$leg, $proof];
    }

    private function assertSupportedHandoff(HandoffProof $proof): void
    {
        if (! in_array($proof->handoff_type, ['delivery', 'receive'], true)) {
            throw ValidationException::withMessages([
                'proof' => 'Only delivery and return handoff proofs can be reviewed here.',
            ]);
        }
    }

    private function assertCurrentDeliveryProof(ShipmentLeg $leg, HandoffProof $proof): void
    {
        $current = $leg->proofs()
            ->where('handoff_type', 'delivery')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $current || (int) $current->id !== (int) $proof->id) {
            throw ValidationException::withMessages([
                'proof' => 'Only the current delivery proof can be reviewed.',
            ]);
        }
    }

    private function rejectReceiveProof(
        ShipmentLeg $leg,
        HandoffProof $proof,
        User|ShopOwner $actor,
        string $reason,
    ): array {
        if ($proof->review_status === 'rejected') {
            return [
                'leg' => $leg->fresh(),
                'proof' => $proof->fresh(),
            ];
        }

        if ($proof->review_status !== 'pending' || $leg->status->value !== 'awaiting_proof_approval') {
            throw ValidationException::withMessages([
                'proof' => 'Only a pending return handoff proof can be rejected.',
            ]);
        }

        $proof->update([
            'review_status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by_type' => $actor::class,
            'reviewed_by_id' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $leg->update(['status' => 'in_transit']);
        $this->events->record($leg->shipment, $leg, [
            'event_type' => 'proof_rejected',
            'visibility' => 'internal',
            'message' => 'Return handoff proof rejected.',
            'metadata' => [
                'rider_profile_id' => $this->activeRiderProfileId($leg),
                'rejection_reason' => $reason,
            ],
            'created_by_type' => $actor::class,
            'created_by_id' => $actor->id,
        ]);

        return [
            'leg' => $leg->fresh(),
            'proof' => $proof->fresh(),
        ];
    }

    private function activeRiderProfileId(ShipmentLeg $leg): ?int
    {
        $id = $leg->assignments()
            ->whereIn('status', ['assigned', 'accepted'])
            ->latest('id')
            ->value('rider_profile_id');

        return $id === null ? null : (int) $id;
    }
}
