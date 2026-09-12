<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyAcceptance;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;
use App\Services\ShopPolicySectionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopPolicyController extends Controller
{
    public function checkoutContext(Request $request): JsonResponse
    {
        $user = $request->user('user');
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.pid' => ['required', 'integer', 'min:1'],
        ]);

        $productIds = collect($validated['items'])
            ->map(fn (array $item) => (int) ($item['pid'] ?? 0))
            ->filter(fn (int $pid) => $pid > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'shop_owner_id' => null,
                    'shop_name' => null,
                    'mixed_shop_cart' => false,
                    'has_active_policy' => false,
                    'active_policy' => null,
                ],
            ]);
        }

        $shopOwnerIds = Product::query()
            ->whereIn('id', $productIds->all())
            ->pluck('shop_owner_id')
            ->filter(fn ($value) => !is_null($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        if ($shopOwnerIds->count() !== 1) {
            return response()->json([
                'success' => true,
                'data' => [
                    'shop_owner_id' => null,
                    'shop_name' => null,
                    'mixed_shop_cart' => $shopOwnerIds->count() > 1,
                    'has_active_policy' => false,
                    'active_policy' => null,
                ],
            ]);
        }

        $shopOwnerId = (int) $shopOwnerIds->first();
        $shopName = ShopOwner::query()->whereKey($shopOwnerId)->value('business_name');
        $active = $this->resolveActiveVersion($shopOwnerId);

        return response()->json([
            'success' => true,
            'data' => [
                'shop_owner_id' => $shopOwnerId,
                'shop_name' => $shopName,
                'mixed_shop_cart' => false,
                'has_active_policy' => (bool) $active,
                'active_policy' => $active,
            ],
        ]);
    }

    public function active(Request $request, int $shopOwnerId, ShopPolicySectionResolver $sectionResolver): JsonResponse
    {
        $version = $this->resolveActiveVersion($shopOwnerId);

        if (!$version) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        $validated = $request->validate([
            'flow' => ['nullable', 'string', 'in:retail,repair'],
        ]);

        if (! empty($validated['flow'])) {
            $version->setAttribute('policy_sections_json', $sectionResolver->forFlow(
                (array) ($version->policy_sections_json ?? []),
                (string) $validated['flow'],
                (string) ($version->business_type_scope ?? '')
            ));
        }

        return response()->json([
            'success' => true,
            'data' => $version,
        ]);
    }

    public function prefill(Request $request, int $shopOwnerId): JsonResponse
    {
        $user = $request->user('user');
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $version = $this->resolveActiveVersion($shopOwnerId);
        if (!$version) {
            return response()->json([
                'success' => false,
                'message' => 'No active policy version found for this shop.',
            ], 404);
        }

        $accepted = PolicyAcceptance::query()
            ->where('shop_owner_id', (int) $shopOwnerId)
            ->where('shop_policy_version_id', (int) $version->id)
            ->where('actor_user_id', (int) $user->id)
            ->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'prefill_checked' => $accepted,
                'shop_policy_version_id' => (int) $version->id,
            ],
        ]);
    }

    private function resolveActiveVersion(int $shopOwnerId): ?ShopPolicyVersion
    {
        return ShopPolicyVersion::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'published')
            ->latest('version_number')
            ->first();
    }
}
