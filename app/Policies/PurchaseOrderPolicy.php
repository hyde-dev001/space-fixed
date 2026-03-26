<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseOrderPolicy
{
    use HandlesAuthorization;

    private function canWithFallback(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can view any purchase orders.
     */
    public function viewAny(User $user): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'view-procurement',
            'procurement.view_purchase_orders',
        ]);
    }

    /**
     * Determine whether the user can view the purchase order.
     */
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'view-procurement',
            'procurement.view_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id;
    }

    /**
     * Determine whether the user can create purchase orders.
     */
    public function create(User $user): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.create_purchase_orders',
        ]);
    }

    /**
     * Determine whether the user can update the purchase order.
     */
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.edit_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id
            && $purchaseOrder->status === 'draft';
    }

    /**
     * Determine whether the user can delete the purchase order.
     */
    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.delete_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id
            && $purchaseOrder->status === 'draft';
    }

    /**
     * Determine whether the user can update the purchase order status.
     */
    public function updateStatus(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.manage_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id
            && !in_array($purchaseOrder->status, ['completed', 'cancelled']);
    }

    /**
     * Determine whether the user can cancel the purchase order.
     */
    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.cancel_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id
            && !in_array($purchaseOrder->status, ['delivered', 'completed', 'cancelled']);
    }

    /**
     * Determine whether the user can restore the purchase order.
     */
    public function restore(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.delete_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id;
    }

    /**
     * Determine whether the user can permanently delete the purchase order.
     */
    public function forceDelete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->canWithFallback($user, [
            'access-purchase-orders',
            'access-procurement-dashboard',
            'procurement.delete_purchase_orders',
        ])
            && $user->shop_owner_id === $purchaseOrder->shop_owner_id;
    }
}
