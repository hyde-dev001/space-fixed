<?php

namespace App\Services;

use App\Models\PolicyAcceptance;
use App\Models\ShopPolicyVersion;

class PolicyAcceptanceService
{
    public function record(array $attributes): PolicyAcceptance
    {
        $policyVersion = ShopPolicyVersion::query()->findOrFail((int) $attributes['shop_policy_version_id']);

        return PolicyAcceptance::query()->create([
            'shop_owner_id' => (int) $attributes['shop_owner_id'],
            'shop_policy_version_id' => (int) $policyVersion->id,
            'actor_guard' => (string) ($attributes['actor_guard'] ?? 'user'),
            'actor_user_id' => isset($attributes['actor_user_id']) ? (int) $attributes['actor_user_id'] : null,
            'context_type' => (string) $attributes['context_type'],
            'context_id' => (int) $attributes['context_id'],
            'accepted_at' => $attributes['accepted_at'] ?? now(),
            'accepted_from_ip' => $attributes['accepted_from_ip'] ?? null,
            'accepted_user_agent' => $attributes['accepted_user_agent'] ?? null,
            'accepted_snapshot_hash' => (string) $policyVersion->content_hash,
        ]);
    }
}