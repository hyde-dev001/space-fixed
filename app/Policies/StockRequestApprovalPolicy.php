<?php

namespace App\Policies;

use App\Models\StockRequestApproval;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockRequestApprovalPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any stock request approvals.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('procurement.review_stock_requests')
            || $user->can('procurement.create_purchase_requests')
            || $user->can('procurement.view')
            || $user->can('access-stock-request-approval');
    }

    public function create(User $user): bool
    {
        return $user->can('procurement.create_purchase_requests');
    }

    /**
     * Determine whether the user can view the stock request approval.
     */
    public function view(User $user, StockRequestApproval $stockRequestApproval): bool
    {
        return $this->viewAny($user)
            && $user->shop_owner_id === $stockRequestApproval->shop_owner_id;
    }

    /**
     * Determine whether the user can approve the stock request.
     */
    public function approve(User $user, StockRequestApproval $stockRequestApproval): bool
    {
        return $user->can('procurement.review_stock_requests')
            && $user->shop_owner_id === $stockRequestApproval->shop_owner_id
            && in_array($stockRequestApproval->status, ['pending', 'needs_details']);
    }

    /**
     * Determine whether the user can reject the stock request.
     */
    public function reject(User $user, StockRequestApproval $stockRequestApproval): bool
    {
        return $user->can('procurement.review_stock_requests')
            && $user->shop_owner_id === $stockRequestApproval->shop_owner_id
            && in_array($stockRequestApproval->status, ['pending', 'needs_details']);
    }

    public function requestDetails(User $user, StockRequestApproval $stockRequestApproval): bool
    {
        return $this->approve($user, $stockRequestApproval);
    }

    /**
     * Determine whether the user can restore the stock request approval.
     */
    public function restore(User $user, StockRequestApproval $stockRequestApproval): bool
    {
        return $user->can('procurement.review_stock_requests')
            && $user->shop_owner_id === $stockRequestApproval->shop_owner_id;
    }

    /**
     * Determine whether the user can permanently delete the stock request approval.
     */
    public function forceDelete(User $user, StockRequestApproval $stockRequestApproval): bool
    {
        return $user->can('procurement.review_stock_requests')
            && $user->shop_owner_id === $stockRequestApproval->shop_owner_id;
    }
}
