<?php

namespace App\Policies;

use App\Models\RepairRequest;
use App\Models\User;

class RepairRequestPolicy
{
    public function posCheckout(User $user, RepairRequest $repair): bool
    {
        $sameShop = (int) ($user->shop_owner_id ?? 0) > 0
            && (int) $user->shop_owner_id === (int) $repair->shop_owner_id;

        if (!$sameShop) {
            return false;
        }

        $allowedStatuses = ['pending', 'ready_for_pickup', 'in_progress', 'completed'];

        return in_array((string) $repair->status, $allowedStatuses, true);
    }
}
