<?php

namespace App\Support\Finance;

use App\Support\Erp\ErpActorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Resolve the Finance tenant from the authenticated user session.
 *
 * A request-provided shop_id is never trusted as tenant authority.
 */
final class FinanceShopContext
{
    public function id(Request $request): int
    {
        $context = $request->attributes->get('erp.actor_context');
        if ($context instanceof ErpActorContext) {
            return (int) $context->tenantOwner()->getKey();
        }

        // Finance tenant authority is always the authenticated `user` guard.
        // Never fall back to the default/web guard or request input.
        $actor = $request->user('user');

        if (! $actor) {
            throw new HttpResponseException(response()->json([
                'message' => 'Authentication is required.',
                'error' => 'UNAUTHENTICATED',
            ], 401));
        }

        $isShopOwner = $this->isShopOwner($actor);
        $shopId = $isShopOwner ? $actor->getKey() : $actor->shop_owner_id;

        if (! is_numeric($shopId) || (int) $shopId < 1) {
            throw new HttpResponseException(response()->json([
                'message' => 'A Finance shop context is required.',
                'error' => 'TENANT_CONTEXT_REQUIRED',
            ], 403));
        }

        return (int) $shopId;
    }

    private function isShopOwner(object $actor): bool
    {
        if (method_exists($actor, 'getRoleNames')) {
            try {
                foreach ($actor->getRoleNames() as $role) {
                    if (in_array(strtolower(str_replace(['-', '_'], ' ', trim((string) $role))), ['shop owner'], true)) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // Fall through to the legacy role column.
            }
        }

        return strtolower(str_replace(['-', '_'], ' ', trim((string) ($actor->role ?? '')))) === 'shop owner';
    }
}
