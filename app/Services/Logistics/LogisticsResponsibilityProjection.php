<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use App\Support\Logistics\LogisticsResponsibility;

final class LogisticsResponsibilityProjection
{
    private const ACTIVE_ASSIGNMENT_STATUSES = ['assigned', 'accepted'];

    private const ACTIVE_BATCH_STATUSES = ['draft', 'offered', 'accepted', 'in_progress'];

    private const TERMINAL_LEG_STATUSES = ['delivered', 'cancelled'];

    private const KNOWN_RESOLUTION_TYPES = [
        'retry',
        'return_required',
        'returned',
        'loss_confirmed',
        'pickup_failed',
        'pickup_attempts_exhausted',
    ];

    public function project(ShipmentLeg $leg): LogisticsResponsibility
    {
        $this->loadReadableState($leg);

        if (! $leg->shipment) {
            return $this->health('missing_shipment');
        }

        $status = $this->status($leg);
        if ($status === null) {
            return $this->health('unknown_leg_status');
        }

        if (in_array($status, self::TERMINAL_LEG_STATUSES, true)) {
            return $this->emptyResult();
        }

        if ($leg->leg_type === 'return_to_shop') {
            return $this->projectReturnLeg($leg);
        }

        if ($leg->returnLeg !== null) {
            if (! $this->validReturnChain($leg, $leg->returnLeg)) {
                return $this->health('invalid_return_chain');
            }

            return $this->projectReturnLeg($leg->returnLeg);
        }

        $resolution = $this->resolutionType($leg);
        if ($resolution === false) {
            return $this->health('unknown_resolution_type');
        }

        if ($resolution !== null && in_array($resolution, ['returned', 'loss_confirmed', 'pickup_attempts_exhausted'], true)) {
            return $this->emptyResult();
        }

        $assignment = $this->currentAssignment($leg);
        if ($assignment['health'] !== null) {
            return $this->health($assignment['health']);
        }

        $responsibleParty = $assignment['party'] !== null
            ? ['party' => $assignment['party'], 'health' => null]
            : $this->batchResponsibleParty($leg);

        if ($responsibleParty['health'] !== null) {
            return $this->health($responsibleParty['health']);
        }

        $party = $assignment['party'] ?? $responsibleParty['party'];
        $material = $this->materialState($leg, $resolution, $party);
        if ($material['health'] !== null) {
            return $this->health($material['health']);
        }

        if ($party !== null) {
            return new LogisticsResponsibility(
                ownerActionRequired: false,
                deterministicResponsibleParty: $party,
                recoveryPathActive: true,
                recoveryPathExhausted: false,
                materialExceptionActive: $material['material'],
                healthReason: null,
            );
        }

        return new LogisticsResponsibility(
            ownerActionRequired: false,
            deterministicResponsibleParty: null,
            recoveryPathActive: false,
            recoveryPathExhausted: $material['exhausted'],
            materialExceptionActive: $material['material'],
            healthReason: null,
        );
    }

    /**
     * @param iterable<ShipmentLeg> $legs
     * @return array<int, LogisticsResponsibility>
     */
    public function projectMany(iterable $legs): array
    {
        $results = [];
        foreach ($legs as $leg) {
            $results[] = $this->project($leg);
        }

        return $results;
    }

    private function projectReturnLeg(ShipmentLeg $leg): LogisticsResponsibility
    {
        if (! $leg->returnForLeg || ! $this->validReturnChain($leg->returnForLeg, $leg)) {
            return $this->health('invalid_return_chain');
        }

        $status = $this->status($leg);
        if ($status === null) {
            return $this->health('unknown_leg_status');
        }
        if (in_array($status, self::TERMINAL_LEG_STATUSES, true)) {
            return $this->emptyResult();
        }

        $receiveProofs = $leg->proofs
            ->where('handoff_type', 'receive')
            ->sortByDesc('id')
            ->values();
        $activeProofs = $receiveProofs->whereIn('review_status', ['pending', 'rider_confirmed']);
        if ($activeProofs->count() > 1) {
            return $this->health('contradictory_return_proofs');
        }

        $proof = $activeProofs->first();
        if ($proof instanceof HandoffProof && $proof->review_status === 'rider_confirmed') {
            return new LogisticsResponsibility(
                ownerActionRequired: true,
                deterministicResponsibleParty: null,
                recoveryPathActive: true,
                recoveryPathExhausted: false,
                materialExceptionActive: true,
                healthReason: null,
            );
        }

        $assignment = $this->currentAssignment($leg);
        if ($assignment['health'] !== null) {
            return $this->health($assignment['health']);
        }
        if ($assignment['party'] === null) {
            return $this->health('indeterminate_return_responsibility');
        }

        return new LogisticsResponsibility(
            ownerActionRequired: false,
            deterministicResponsibleParty: $assignment['party'],
            recoveryPathActive: true,
            recoveryPathExhausted: false,
            materialExceptionActive: true,
            healthReason: null,
        );
    }

    /**
     * @return array{party: ?string, health: ?string}
     */
    private function currentAssignment(ShipmentLeg $leg): array
    {
        $active = $leg->assignments
            ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
            ->values();

        if ($active->count() > 1) {
            return ['party' => null, 'health' => 'contradictory_active_assignments'];
        }

        /** @var DeliveryAssignment|null $assignment */
        $assignment = $active->first();
        if (! $assignment) {
            return ['party' => null, 'health' => null];
        }

        if ($assignment->rider_profile_id !== null) {
            $rider = $assignment->riderProfile;
            if (! $rider
                || (int) $rider->shop_owner_id !== (int) $leg->shipment->shop_owner_id
                || ! $rider->active
                || $rider->availability_status === 'inactive') {
                return ['party' => null, 'health' => 'invalid_active_assignment'];
            }

            if (! filled($rider->linked_type) || ! filled($rider->linked_id) || $rider->linked === null) {
                return ['party' => null, 'health' => 'invalid_rider_identity'];
            }

            return ['party' => 'rider', 'health' => null];
        }

        if ($assignment->courier_provider_id !== null || $assignment->assignment_type === 'external_courier') {
            return ['party' => 'dispatcher', 'health' => null];
        }

        return ['party' => null, 'health' => 'indeterminate_assignment'];
    }

    /**
     * @return array{party: ?string, health: ?string}
     */
    private function batchResponsibleParty(ShipmentLeg $leg): array
    {
        $batch = $leg->deliveryBatch;
        if (! $batch) {
            return ['party' => null, 'health' => null];
        }

        if (! in_array((string) $batch->status, self::ACTIVE_BATCH_STATUSES, true)) {
            return $batch->status === 'completed'
                ? ['party' => null, 'health' => 'completed_batch_with_active_leg']
                : ['party' => null, 'health' => null];
        }

        if ($batch->status === 'in_progress') {
            $rider = $batch->riderProfile;
            if (! $rider) {
                return ['party' => null, 'health' => 'missing_batch_rider'];
            }
            if (! $rider->active || $rider->availability_status === 'inactive') {
                return ['party' => null, 'health' => 'invalid_batch_rider'];
            }

            return ['party' => 'rider', 'health' => null];
        }

        return ['party' => 'dispatcher', 'health' => null];
    }

    /**
     * @return array{material: bool, exhausted: bool, health: ?string}
     */
    private function materialState(ShipmentLeg $leg, ?string $resolution, ?string $party): array
    {
        $status = $this->status($leg);
        $failedState = in_array($status, ['delivery_attempted', 'needs_resolution', 'failed'], true);
        if (! $failedState || ! $leg->failed_at) {
            return ['material' => false, 'exhausted' => false, 'health' => null];
        }

        if ($resolution === 'retry' && $party === null) {
            return ['material' => false, 'exhausted' => false, 'health' => 'retry_without_current_owner'];
        }

        if ($resolution === 'retry' && $party !== null) {
            return ['material' => true, 'exhausted' => false, 'health' => null];
        }

        if ($resolution === 'return_required') {
            return ['material' => false, 'exhausted' => false, 'health' => 'return_required_without_return_leg'];
        }

        if ($resolution !== null) {
            return ['material' => false, 'exhausted' => false, 'health' => 'unsupported_recovery_state'];
        }

        if ($party !== null) {
            return ['material' => true, 'exhausted' => false, 'health' => null];
        }

        $attempts = $leg->attempts->where('attempt_type', 'delivery')->count();
        $setting = $leg->shipment->shopOwner?->logisticsSetting;
        $maxAttempts = max(1, (int) ($setting?->max_delivery_attempts ?? 2));
        if ($attempts < $maxAttempts) {
            return ['material' => false, 'exhausted' => false, 'health' => 'unexhausted_without_recovery_path'];
        }

        return ['material' => true, 'exhausted' => true, 'health' => null];
    }

    private function loadReadableState(ShipmentLeg $leg): void
    {
        $leg->loadMissing([
            'shipment.shopOwner.logisticsSetting',
            'assignments.riderProfile.linked',
            'attempts',
            'deliveryBatch.riderProfile',
            'returnForLeg',
            'returnLeg.assignments.riderProfile.linked',
            'returnLeg.proofs',
            'proofs',
        ]);

        if ($leg->returnForLeg) {
            $leg->returnForLeg->loadMissing('shipment');
        }
        if ($leg->returnLeg) {
            $leg->returnLeg->loadMissing('shipment.shopOwner.logisticsSetting');
        }
    }

    private function validReturnChain(ShipmentLeg $original, ShipmentLeg $return): bool
    {
        return $return->leg_type === 'return_to_shop'
            && (int) $return->return_for_leg_id === (int) $original->id
            && (int) $return->shipment_id === (int) $original->shipment_id;
    }

    private function status(ShipmentLeg $leg): ?string
    {
        $status = $leg->status;

        return is_object($status) && property_exists($status, 'value')
            ? $status->value
            : (is_string($status) ? $status : null);
    }

    /**
     * @return string|false|null
     */
    private function resolutionType(ShipmentLeg $leg): string|false|null
    {
        $resolution = $leg->resolution_type;
        if ($resolution === null || in_array($resolution, self::KNOWN_RESOLUTION_TYPES, true)) {
            return $resolution;
        }

        return false;
    }

    private function emptyResult(): LogisticsResponsibility
    {
        return new LogisticsResponsibility(false, null, false, false, false, null);
    }

    private function health(string $reason): LogisticsResponsibility
    {
        return new LogisticsResponsibility(false, null, false, false, false, $reason);
    }
}
