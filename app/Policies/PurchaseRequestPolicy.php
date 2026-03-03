<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseRequestPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any purchase requests.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('procurement.view_purchase_requests');
    }

    /**
     * Determine whether the user can view the purchase request.
     */
    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.view_purchase_requests') 
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id;
    }

    /**
     * Determine whether the user can create purchase requests.
     */
    public function create(User $user): bool
    {
        return $user->can('procurement.create_purchase_requests');
    }

    /**
     * Determine whether the user can update the purchase request.
     */
    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.edit_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id
            && $purchaseRequest->status === 'draft';
    }

    /**
     * Determine whether the user can delete the purchase request.
     */
    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.delete_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id
            && $purchaseRequest->status === 'draft';
    }

    /**
     * Determine whether the user can submit the purchase request to finance.
     */
    public function submitToFinance(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.create_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id
            && $purchaseRequest->status === 'draft';
    }

    /**
     * Determine whether the user can approve the purchase request.
     */
    public function approve(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.approve_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id
            && $purchaseRequest->status === 'pending_finance';
    }

    /**
     * Determine whether the user can reject the purchase request.
     */
    public function reject(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.reject_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id
            && in_array($purchaseRequest->status, ['pending_finance', 'approved']);
    }

    /**
     * Determine whether the user can restore the purchase request.
     */
    public function restore(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.delete_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id;
    }

    /**
     * Determine whether the user can permanently delete the purchase request.
     */
    public function forceDelete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->can('procurement.delete_purchase_requests')
            && $user->shop_owner_id === $purchaseRequest->shop_owner_id;
    }
}
