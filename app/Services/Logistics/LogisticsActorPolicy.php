<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Enums\Logistics\LogisticsAction;
use App\Enums\Logistics\ShipmentLegStatus;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ShopModuleAccessService;
use App\Support\Erp\ErpActorContext;
use Illuminate\Contracts\Auth\Authenticatable;

final class LogisticsActorPolicy
{
    /** @var array<int, string> */
    private const OWNER_ACTIONS = [
        LogisticsAction::ASSIGN_RIDER->value,
        LogisticsAction::SCHEDULE_DELIVERY->value,
        LogisticsAction::RESOLVE_EXCEPTION->value,
        LogisticsAction::REVIEW_PROOF->value,
        LogisticsAction::CONFIRM_RETURN_RECEIPT->value,
    ];

    /** @var array<string, array<int, string>> */
    private const EMPLOYEE_CAPABILITIES = [
        LogisticsAction::ASSIGN_RIDER->value => ['assign-logistics-deliveries'],
        LogisticsAction::SCHEDULE_DELIVERY->value => [
            'assign-logistics-deliveries',
            'manage-logistics-batches',
        ],
        LogisticsAction::RESOLVE_EXCEPTION->value => ['resolve-logistics-exceptions'],
        LogisticsAction::REVIEW_PROOF->value => [
            'approve-proof-of-delivery',
            'assign-logistics-deliveries',
        ],
        LogisticsAction::CONFIRM_RETURN_RECEIPT->value => [
            'approve-proof-of-delivery',
            'assign-logistics-deliveries',
        ],
        LogisticsAction::SUBMIT_PROOF->value => ['record-logistics-proof'],
    ];

    /** @var array<string, array<int, ShipmentLegStatus>> */
    private const SOURCE_STATES = [
        LogisticsAction::ASSIGN_RIDER->value => [
            ShipmentLegStatus::PENDING,
            ShipmentLegStatus::FAILED,
        ],
        LogisticsAction::SCHEDULE_DELIVERY->value => [
            ShipmentLegStatus::PENDING,
            ShipmentLegStatus::ASSIGNED,
        ],
        LogisticsAction::RESOLVE_EXCEPTION->value => [
            ShipmentLegStatus::ASSIGNED,
            ShipmentLegStatus::PICKED_UP,
            ShipmentLegStatus::IN_TRANSIT,
            ShipmentLegStatus::DELIVERY_ATTEMPTED,
            ShipmentLegStatus::NEEDS_RESOLUTION,
        ],
        LogisticsAction::SUBMIT_PROOF->value => [
            ShipmentLegStatus::PENDING,
            ShipmentLegStatus::ASSIGNED,
            ShipmentLegStatus::PICKUP_SCHEDULED,
            ShipmentLegStatus::PICKED_UP,
            ShipmentLegStatus::IN_TRANSIT,
            ShipmentLegStatus::DELIVERY_ATTEMPTED,
            ShipmentLegStatus::AWAITING_PROOF_APPROVAL,
            ShipmentLegStatus::PROOF_CORRECTION_REQUIRED,
        ],
        LogisticsAction::REVIEW_PROOF->value => [
            ShipmentLegStatus::AWAITING_PROOF_APPROVAL,
            ShipmentLegStatus::DELIVERED,
            ShipmentLegStatus::PROOF_CORRECTION_REQUIRED,
        ],
        LogisticsAction::CONFIRM_RETURN_RECEIPT->value => [
            ShipmentLegStatus::PICKED_UP,
            ShipmentLegStatus::IN_TRANSIT,
            ShipmentLegStatus::DELIVERY_ATTEMPTED,
            ShipmentLegStatus::DELIVERED,
        ],
    ];

    /** @var array<int, ShipmentLegStatus> */
    private const CUSTODY_STATES = [
        ShipmentLegStatus::PENDING,
        ShipmentLegStatus::ASSIGNED,
        ShipmentLegStatus::PICKUP_SCHEDULED,
        ShipmentLegStatus::PICKED_UP,
        ShipmentLegStatus::IN_TRANSIT,
        ShipmentLegStatus::DELIVERY_ATTEMPTED,
        ShipmentLegStatus::AWAITING_PROOF_APPROVAL,
        ShipmentLegStatus::PROOF_CORRECTION_REQUIRED,
        ShipmentLegStatus::NEEDS_RESOLUTION,
    ];

    public function __construct(
        private readonly ShopModuleAccessService $modules,
    ) {}

    /**
     * Resolve an ERP route context into the same pure decision contract used by
     * API controllers.
     *
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    public function decideForContext(
        ErpActorContext $context,
        LogisticsAction $action,
        ?ShipmentLeg $leg = null,
        ?HandoffProof $proof = null,
    ): array {
        if (! in_array('logistics', $context->moduleKeys(), true)) {
            return $this->deny($action, 'module_unavailable');
        }

        return $this->decide($context->actor(), $action, $context->tenantOwner(), $leg, $proof);
    }

    /**
     * Decide one explicit Logistics action without changing application state.
     *
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    public function decide(
        Authenticatable $actor,
        LogisticsAction $action,
        ShopOwner $shop,
        ?ShipmentLeg $leg = null,
        ?HandoffProof $proof = null,
    ): array {
        if (! $this->actorBelongsToShop($actor, $shop)) {
            return $this->deny($action, 'cross_shop');
        }

        $leg = $this->resolveLeg($leg, $proof);
        if (! $leg) {
            return $this->deny($action, 'source_record_required');
        }

        $leg->loadMissing('shipment');
        if (! $leg->shipment || (int) $leg->shipment->shop_owner_id !== (int) $shop->id) {
            return $this->deny($action, 'cross_shop');
        }

        if (! $this->modules->canAccess($shop, 'logistics')) {
            return $this->deny($action, 'module_unavailable');
        }

        if (! $this->hasValidSourceState($action, $leg)) {
            return $this->deny($action, 'source_state_invalid');
        }

        if ($proof && (int) $proof->shipment_leg_id !== (int) $leg->id) {
            return $this->deny($action, 'cross_shop');
        }

        if ($action === LogisticsAction::REVIEW_PROOF) {
            $proofDecision = $this->reviewProofDecision($actor, $leg, $proof, $action);
            if ($proofDecision !== null) {
                return $proofDecision;
            }
        }

        if ($action === LogisticsAction::CONFIRM_RETURN_RECEIPT) {
            $returnDecision = $this->returnReceiptDecision($leg, $proof, $action);
            if ($returnDecision !== null) {
                return $returnDecision;
            }
        }

        if ($action === LogisticsAction::SUBMIT_PROOF) {
            if ($actor instanceof User && ! $this->hasCapability($actor, $action)) {
                return $this->deny($action, 'action_not_allowed');
            }

            return $this->custodyDecision($actor, $shop, $leg, $action);
        }

        if ($actor instanceof ShopOwner) {
            return in_array($action->value, self::OWNER_ACTIONS, true)
                ? $this->allow($action)
                : $this->deny($action, 'action_not_allowed');
        }

        if (! $actor instanceof User || ! $this->hasCapability($actor, $action)) {
            return $this->deny($action, 'action_not_allowed');
        }

        return $this->allow($action);
    }

    /**
     * Decide whether an authenticated actor may assert physical custody of a
     * leg. The endpoint chooses its own existing capability requirement; the
     * policy supplies the shared tenant, module, source-state, identity, and
     * exact-assignment checks.
     *
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    public function decideCustody(
        Authenticatable $actor,
        ShopOwner $shop,
        ShipmentLeg $leg,
        ?string $requiredCapability = null,
    ): array {
        $action = LogisticsAction::SUBMIT_PROOF;

        $contextDecision = $this->custodyContextDecision($actor, $shop, $leg, $action);
        if ($contextDecision !== null) {
            return $contextDecision;
        }

        if (! $this->hasValidState($leg, self::CUSTODY_STATES)) {
            return $this->deny($action, 'source_state_invalid');
        }

        if ($requiredCapability !== null
            && $actor instanceof User
            && ! $actor->can($requiredCapability)) {
            return $this->deny($action, 'action_not_allowed');
        }

        return $this->custodyDecision($actor, $shop, $leg, $action);
    }

    /**
     * Return-handoff confirmation has one terminal replay: the same assigned
     * rider may repeat a handoff request after the shop has already received
     * and approved the return proof. Other terminal custody requests remain
     * denied by decideCustody().
     *
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    public function decideReturnHandoff(
        Authenticatable $actor,
        ShopOwner $shop,
        ShipmentLeg $leg,
        HandoffProof $proof,
    ): array {
        $action = LogisticsAction::SUBMIT_PROOF;
        $contextDecision = $this->custodyContextDecision($actor, $shop, $leg, $action);
        if ($contextDecision !== null) {
            return $contextDecision;
        }

        if ($leg->leg_type !== 'return_to_shop'
            || (int) $proof->shipment_leg_id !== (int) $leg->id
            || $proof->handoff_type !== 'receive') {
            return $this->deny($action, 'source_state_invalid');
        }

        if ($leg->status->value === 'delivered') {
            return $proof->review_status === 'approved'
                ? $this->custodyDecision($actor, $shop, $leg, $action)
                : $this->deny($action, 'source_state_invalid');
        }

        if (! $this->hasValidState($leg, self::CUSTODY_STATES)) {
            return $this->deny($action, 'source_state_invalid');
        }

        if (! in_array($proof->review_status, ['pending', 'rider_confirmed'], true)) {
            return $this->deny($action, 'source_state_invalid');
        }

        return $this->custodyDecision($actor, $shop, $leg, $action);
    }

    public function resolveAssignedRider(
        Authenticatable $actor,
        ShopOwner $shop,
        ShipmentLeg $leg,
    ): ?RiderProfile {
        $profile = $this->custodyProfile($actor, $shop);
        if (! $profile) {
            return null;
        }

        return $profile->assignments()
            ->where('shipment_leg_id', $leg->id)
            ->whereIn('status', ['assigned', 'accepted'])
            ->exists()
            ? $profile
            : null;
    }

    public function resolveRiderProfile(
        Authenticatable $actor,
        ShopOwner $shop,
    ): ?RiderProfile {
        return $this->custodyProfile($actor, $shop);
    }

    /**
     * Resolve an actor-level capability for page projections. Source-state,
     * proof, and assignment checks remain in decide()/decideCustody().
     */
    public function canPerform(
        Authenticatable $actor,
        ShopOwner $shop,
        LogisticsAction $action,
    ): bool {
        if (! $this->actorBelongsToShop($actor, $shop)) {
            return false;
        }

        if ($actor instanceof ShopOwner) {
            return in_array($action->value, self::OWNER_ACTIONS, true);
        }

        return $actor instanceof User && $this->hasCapability($actor, $action);
    }

    /**
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    private function custodyDecision(
        Authenticatable $actor,
        ShopOwner $shop,
        ShipmentLeg $leg,
        LogisticsAction $action,
    ): array {
        $profile = $this->custodyProfile($actor, $shop);

        if (! $profile) {
            return $this->deny($action, 'rider_identity_required');
        }

        return $this->resolveAssignedRider($actor, $shop, $leg)
            ? $this->allow($action)
            : $this->deny($action, 'active_assignment_required');
    }

    /**
     * @return array{allowed: bool, action: string, reason_category: ?string}|null
     */
    private function custodyContextDecision(
        Authenticatable $actor,
        ShopOwner $shop,
        ShipmentLeg $leg,
        LogisticsAction $action,
    ): ?array {
        if (! $this->actorBelongsToShop($actor, $shop)) {
            return $this->deny($action, 'cross_shop');
        }

        $leg->loadMissing('shipment');
        if (! $leg->shipment || (int) $leg->shipment->shop_owner_id !== (int) $shop->id) {
            return $this->deny($action, 'cross_shop');
        }

        if (! $this->modules->canAccess($shop, 'logistics')) {
            return $this->deny($action, 'module_unavailable');
        }

        return null;
    }

    private function reviewProofDecision(
        Authenticatable $actor,
        ShipmentLeg $leg,
        ?HandoffProof $proof,
        LogisticsAction $action,
    ): ?array {
        if (! $proof) {
            return $this->deny($action, 'source_record_required');
        }

        if ($proof->review_status === 'approved'
            && $leg->status === ShipmentLegStatus::DELIVERED) {
            return null;
        }

        if ($proof->review_status === 'rejected'
            && $leg->status === ShipmentLegStatus::PROOF_CORRECTION_REQUIRED) {
            return null;
        }

        if ($proof->handoff_type !== 'delivery' || $proof->review_status !== 'pending') {
            return $this->deny($action, 'source_state_invalid');
        }

        if (! filled($proof->confirmed_by_type) || ! filled($proof->confirmed_by_id)) {
            return $this->deny($action, 'maker_checker_identity_missing');
        }

        if ($proof->confirmed_by_type === $actor::class
            && (int) $proof->confirmed_by_id === (int) $actor->getAuthIdentifier()) {
            return $this->deny($action, 'maker_checker_conflict');
        }

        return null;
    }

    private function returnReceiptDecision(
        ShipmentLeg $leg,
        ?HandoffProof $proof,
        LogisticsAction $action,
    ): ?array {
        if ($leg->leg_type !== 'return_to_shop'
            || ! $proof
            || $proof->handoff_type !== 'receive') {
            return $this->deny($action, 'source_state_invalid');
        }

        if ($leg->status->value === 'delivered' && $proof->review_status === 'approved') {
            return null;
        }

        if ($proof->review_status !== 'rider_confirmed') {
            return $this->deny($action, 'source_state_invalid');
        }

        return null;
    }

    private function resolveLeg(?ShipmentLeg $leg, ?HandoffProof $proof): ?ShipmentLeg
    {
        if ($leg) {
            return $leg;
        }

        if (! $proof) {
            return null;
        }

        $proof->loadMissing('leg');

        return $proof->leg;
    }

    private function actorBelongsToShop(Authenticatable $actor, ShopOwner $shop): bool
    {
        return ($actor instanceof ShopOwner && (int) $actor->getAuthIdentifier() === (int) $shop->id)
            || ($actor instanceof User && (int) $actor->shop_owner_id === (int) $shop->id);
    }

    private function hasValidSourceState(LogisticsAction $action, ShipmentLeg $leg): bool
    {
        return $this->hasValidState($leg, self::SOURCE_STATES[$action->value]);
    }

    /**
     * @param  array<int, ShipmentLegStatus>  $allowedStates
     */
    private function hasValidState(ShipmentLeg $leg, array $allowedStates): bool
    {
        $status = $leg->status;
        if (! $status instanceof ShipmentLegStatus) {
            $status = ShipmentLegStatus::tryFrom((string) $status);
        }

        return $status instanceof ShipmentLegStatus && in_array($status, $allowedStates, true);
    }

    private function custodyProfile(Authenticatable $actor, ShopOwner $shop): ?RiderProfile
    {
        $profileType = $actor instanceof ShopOwner ? ShopOwner::class : User::class;
        $riderType = $actor instanceof ShopOwner ? 'shop_owner' : 'employee';

        return RiderProfile::query()
            ->where('shop_owner_id', $shop->id)
            ->where('rider_type', $riderType)
            ->where('linked_type', $profileType)
            ->where('linked_id', $actor->getAuthIdentifier())
            ->where('active', true)
            ->where('availability_status', '!=', 'inactive')
            ->first();
    }

    private function hasCapability(Authenticatable $actor, LogisticsAction $action): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        foreach (self::EMPLOYEE_CAPABILITIES[$action->value] ?? [] as $permission) {
            if ($actor->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    private function allow(LogisticsAction $action): array
    {
        return [
            'allowed' => true,
            'action' => $action->value,
            'reason_category' => null,
        ];
    }

    /**
     * @return array{allowed: bool, action: string, reason_category: ?string}
     */
    private function deny(LogisticsAction $action, string $reasonCategory): array
    {
        return [
            'allowed' => false,
            'action' => $action->value,
            'reason_category' => $reasonCategory,
        ];
    }
}
