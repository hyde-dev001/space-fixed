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

        $normalizedStatus = str_replace('-', '_', strtolower(trim((string) $repair->status)));

        $allowedStatuses = [
            'pending',
            'repairer_accepted',
            'waiting_customer_confirmation',
            'confirmed',
            'owner_approved',
            'received',
            'in_progress',
            'completed',
            'ready_for_pickup',
            'shipped',
            'picked_up',
        ];

        return in_array($normalizedStatus, $allowedStatuses, true);
    }
}
