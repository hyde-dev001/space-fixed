<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InventoryItemPolicy
{
    /**
     * Determine whether the user can view any inventory items.
     */
    public function viewAny(User $user): bool
    {
        // Allow if user has shop_owner_id (shop owner or employee)
        // and has inventory.view permission
        return $user->shop_owner_id !== null && 
               $user->hasPermissionTo('inventory.view');
    }

    /**
     * Determine whether the user can view the inventory item.
     */
    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        // User must belong to the same shop and have view permission
        return $user->shop_owner_id === $inventoryItem->shop_owner_id &&
               $user->hasPermissionTo('inventory.view');
    }

    /**
     * Determine whether the user can create inventory items.
     */
    public function create(User $user): bool
    {
        // User must have shop_owner_id and create permission
        return $user->shop_owner_id !== null &&
               $user->hasPermissionTo('inventory.create');
    }

    /**
     * Determine whether the user can update the inventory item.
     */
    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        // User must belong to the same shop and have edit permission
        return $user->shop_owner_id === $inventoryItem->shop_owner_id &&
               $user->hasPermissionTo('inventory.edit');
    }

    /**
     * Determine whether the user can delete the inventory item.
     */
    public function delete(User $user, InventoryItem $inventoryItem): bool
    {
        // User must belong to the same shop and have delete permission
        return $user->shop_owner_id === $inventoryItem->shop_owner_id &&
               $user->hasPermissionTo('inventory.delete');
    }

    /**
     * Determine whether the user can restore the inventory item.
     */
    public function restore(User $user, InventoryItem $inventoryItem): bool
    {
        // User must belong to the same shop and have delete permission
        return $user->shop_owner_id === $inventoryItem->shop_owner_id &&
               $user->hasPermissionTo('inventory.delete');
    }

    /**
     * Determine whether the user can permanently delete the inventory item.
     */
    public function forceDelete(User $user, InventoryItem $inventoryItem): bool
    {
        // User must belong to the same shop and have delete permission
        return $user->shop_owner_id === $inventoryItem->shop_owner_id &&
               $user->hasPermissionTo('inventory.delete');
    }
    
    /**
     * Determine whether the user can adjust stock quantities.
     */
    public function adjustStock(User $user, InventoryItem $inventoryItem): bool
    {
        // User must belong to the same shop and have adjust_stock permission
        return $user->shop_owner_id === $inventoryItem->shop_owner_id &&
               $user->hasPermissionTo('inventory.adjust_stock');
    }
    
    /**
     * Determine whether the user can manage suppliers.
     */
    public function manageSuppliers(User $user): bool
    {
        return $user->shop_owner_id !== null &&
               $user->hasPermissionTo('inventory.manage_suppliers');
    }
    
    /**
     * Determine whether the user can manage supplier orders.
     */
    public function manageOrders(User $user): bool
    {
        return $user->shop_owner_id !== null &&
               $user->hasPermissionTo('inventory.manage_orders');
    }
    
    /**
     * Determine whether the user can view stock movements.
     */
    public function viewMovements(User $user): bool
    {
        return $user->shop_owner_id !== null &&
               $user->hasPermissionTo('inventory.view_movements');
    }
    
    /**
     * Determine whether the user can view reports.
     */
    public function viewReports(User $user): bool
    {
        return $user->shop_owner_id !== null &&
               $user->hasPermissionTo('inventory.view_reports');
    }
}
