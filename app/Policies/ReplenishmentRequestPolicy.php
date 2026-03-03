<?php

namespace App\Policies;

use App\Models\ReplenishmentRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReplenishmentRequestPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any replenishment requests.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('procurement.view_replenishment_requests');
    }

    /**
     * Determine whether the user can view the replenishment request.
     */
    public function view(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.view_replenishment_requests') 
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id;
    }

    /**
     * Determine whether the user can create replenishment requests.
     */
    public function create(User $user): bool
    {
        return $user->can('procurement.view_replenishment_requests');
    }

    /**
     * Determine whether the user can update the replenishment request.
     */
    public function update(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.view_replenishment_requests')
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id
            && in_array($replenishmentRequest->status, ['pending', 'needs_details']);
    }

    /**
     * Determine whether the user can delete the replenishment request.
     */
    public function delete(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.manage_replenishment_requests')
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id
            && $replenishmentRequest->status === 'pending';
    }

    /**
     * Determine whether the user can accept the replenishment request.
     */
    public function accept(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.manage_replenishment_requests')
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id
            && in_array($replenishmentRequest->status, ['pending', 'needs_details']);
    }

    /**
     * Determine whether the user can reject the replenishment request.
     */
    public function reject(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.manage_replenishment_requests')
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id
            && in_array($replenishmentRequest->status, ['pending', 'needs_details']);
    }

    /**
     * Determine whether the user can restore the replenishment request.
     */
    public function restore(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.manage_replenishment_requests')
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id;
    }

    /**
     * Determine whether the user can permanently delete the replenishment request.
     */
    public function forceDelete(User $user, ReplenishmentRequest $replenishmentRequest): bool
    {
        return $user->can('procurement.manage_replenishment_requests')
            && $user->shop_owner_id === $replenishmentRequest->shop_owner_id;
    }
}
