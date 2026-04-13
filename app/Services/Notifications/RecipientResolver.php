<?php

namespace App\Services\Notifications;

use App\Models\User;

final class RecipientResolver
{
    /**
     * Resolve shop owner notifications to either owner records or delegated users.
     * Governance events always include the owner regardless of registration type.
     *
     * @return array{shop_owner_ids: int[], user_ids: int[]}
     */
    public function resolveShopOwnerRecipients(string $eventType, int $shopOwnerId, string $registrationType): array
    {
        $governanceTypes = [
            'salary_change_submitted',
            'refund_request',
            'employee_suspension_request',
            'high_value_approval',
            'repair_reject_approval',
        ];

        if (in_array($eventType, $governanceTypes, true)) {
            return ['shop_owner_ids' => [$shopOwnerId], 'user_ids' => []];
        }

        if (strtolower($registrationType) === 'company') {
            return [
                'shop_owner_ids' => [],
                'user_ids' => $this->resolveDelegatedUsers($shopOwnerId, $eventType),
            ];
        }

        return ['shop_owner_ids' => [$shopOwnerId], 'user_ids' => []];
    }

    /**
     * @return int[]
     */
    private function resolveDelegatedUsers(int $shopOwnerId, string $eventType): array
    {
        return User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('permissions', fn ($query) => $query->whereIn('name', ['access-refund-approval', 'view-job-orders']))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
