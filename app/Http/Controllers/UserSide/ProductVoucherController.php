<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PromoCampaign;
use App\Models\VoucherClaim;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class ProductVoucherController extends Controller
{
    public function claim(Request $request, int $productId, int $campaignId): JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (!Schema::hasTable('promo_campaigns') || !Schema::hasTable('voucher_claims')) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher feature is not available yet. Please run promo migrations first.',
            ], 503);
        }

        $product = Product::query()
            ->select('id', 'shop_owner_id')
            ->findOrFail($productId);

        $campaign = PromoCampaign::with('products:id')
            ->where('id', $campaignId)
            ->where('kind', 'voucher')
            ->where('status', 'active')
            ->where('shop_owner_id', $product->shop_owner_id)
            ->firstOrFail();

        if (now()->lt($campaign->start_at) || now()->gt($campaign->end_at)) {
            return response()->json(['success' => false, 'message' => 'Campaign not active.'], 422);
        }

        if ($campaign->scope === 'product_specific' && !$campaign->products->pluck('id')->contains($product->id)) {
            return response()->json(['success' => false, 'message' => 'Voucher not applicable to this product.'], 422);
        }

        if ($campaign->usage_limit !== null && (int) $campaign->used_count >= (int) $campaign->usage_limit) {
            return response()->json(['success' => false, 'message' => 'Voucher is fully claimed.'], 422);
        }

        try {
            $claim = VoucherClaim::create([
                'promo_campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'shop_owner_id' => $campaign->shop_owner_id,
                'status' => 'claimed',
                'claimed_at' => now(),
            ]);
        } catch (QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Voucher already claimed.'], 409);
        }

        return response()->json(['success' => true, 'data' => $claim], 201);
    }
}
