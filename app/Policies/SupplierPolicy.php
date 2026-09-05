<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('procurement.view') || $user->can('access-suppliers-management');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user) && $user->shop_owner_id === $supplier->shop_owner_id;
    }

    public function create(User $user): bool
    {
        return $user->can('procurement.manage_suppliers');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->create($user) && $user->shop_owner_id === $supplier->shop_owner_id;
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $this->update($user, $supplier);
    }
}
