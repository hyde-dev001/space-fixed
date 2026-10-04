<?php

namespace App\Services\Logistics;

use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\Shipment;
use App\Models\ShopOwner;
use App\Models\Order;
use App\Services\ShopModuleAccessService;
use Illuminate\Validation\ValidationException;

final class LogisticsMovementEligibility
{
    public function __construct(private ShopModuleAccessService $modules) {}

    public function canStart(ShopOwner $shop, bool $lockState = false): bool
    {
        $current = clone $shop;
        $current->unsetRelation('modules');
        if ($lockState && $current->getConnection()->transactionLevel() > 0) {
            // A plain read can retain the snapshot taken before waiting for the shop lock.
            $current->setRelation('modules', $current->modules()
                ->where('module_key', 'logistics')->lockForUpdate()->get());
        }

        return $this->modules->canAccess($current, 'logistics');
    }

    public function assertCanStart(ShopOwner $shop): void
    {
        if (! $this->canStart($shop, lockState: true)) {
            throw ValidationException::withMessages([
                'logistics' => ['Enable Logistics before arranging a new shop-owned movement.'],
            ]);
        }
    }

    public function lockShopForLeg(ShipmentLeg $leg): ShopOwner
    {
        $shopOwnerId = ShipmentLeg::query()->whereKey($leg->id)
            ->join('shipments', 'shipments.id', '=', 'shipment_legs.shipment_id')
            ->value('shipments.shop_owner_id');

        return ShopOwner::query()->whereKey($shopOwnerId)->lockForUpdate()->firstOrFail();
    }

    public function isThirdPartyTracking(ShipmentLeg $leg): bool
    {
        $leg->loadMissing('shipment');
        $shipment = $leg->shipment;

        return $shipment?->source_type === 'order'
            && $shipment->purpose === 'retail_delivery'
            && Order::query()->whereKey($shipment->source_id)
                ->where('shop_owner_id', $shipment->shop_owner_id)
                ->first()?->resolvedDeliveryMethod() === 'third_party';
    }

    public function assertInternalLeg(ShipmentLeg $leg): void
    {
        if ($this->isThirdPartyTracking($leg)) {
            throw ValidationException::withMessages([
                'shipment_leg_id' => ['Third-party tracking cannot authorize shop-owned dispatch.'],
            ]);
        }
    }

    public function canContinue(ShopOwner $shop, ShipmentLeg $leg): bool
    {
        if (! $leg->exists || ! $this->modules->decide($shop, 'logistics', enforceState: false)->allowed) {
            return false;
        }
        // Read persisted evidence: an active parent, assignment, or caller's
        // unsaved status cannot establish that this particular leg started.
        $started = ShipmentLeg::query()->with('shipment')
            ->whereKey($leg->id)
            ->where('shop_owner_id', $shop->id)
            ->whereHas('shipment', fn ($query) => $query
                ->where('shop_owner_id', $shop->id)->where('status', '!=', 'cancelled'))
            ->whereNotIn('status', ['pending', 'assigned', 'pickup_scheduled', 'failed', 'cancelled'])
            ->where(function ($query) {
                $query->whereNotNull('picked_up_at')
                    ->orWhereNotNull('out_for_delivery_at')
                    ->orWhereIn('status', [
                        'picked_up', 'in_transit', 'delivery_attempted',
                        'awaiting_proof_approval', 'proof_correction_required', 'delivered',
                    ]);
            })
            ->first();

        return $started !== null && ! $this->isThirdPartyTracking($started);
    }

    public function assertExistingShipmentAllowed(ShopOwner $shop, Shipment $shipment): void
    {
        if ($this->canStart($shop, lockState: true)) {
            return;
        }

        if (! $this->canContinueShipment($shop, $shipment)) {
            $this->assertCanStart($shop);
        }
    }

    public function canContinueShipment(ShopOwner $shop, Shipment $shipment): bool
    {
        $leg = $shipment->legs()->reorder('sequence', 'desc')->first();

        return $leg && $this->canContinue($shop, $leg);
    }
}
