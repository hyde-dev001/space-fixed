<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\RepairReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CustomerReviewController extends Controller
{
    /**
     * Return all reviews (product + repair) for the authenticated shop owner.
     *
     * GET /api/shop-owner/reviews
     */
    public function index(): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $shopOwnerId = $shopOwner->id;

        // ── Product reviews ──────────────────────────────────────────────
        $productReviews = ProductReview::where('shop_owner_id', $shopOwnerId)
            ->where('is_approved', true)
            ->with([
                'user:id,name,email',
                'product:id,name',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id'             => 'product_' . $r->id,
                'customerName'   => $r->user?->name ?? 'Unknown Customer',
                'rating'         => (int) $r->rating,
                'comment'        => $r->comment ?? '',
                'feedbackImages' => $r->images ?? [],
                'serviceType'    => $r->product?->name ?? 'Product',
                'orderType'      => 'product',
                'responseStatus' => 'pending',   // ProductReview has no shop-response field yet
                'shopResponse'   => null,
                'createdAt'      => $r->created_at->format('Y-m-d'),
            ]);

        // ── Repair reviews ───────────────────────────────────────────────
        $repairReviews = RepairReview::where('shop_owner_id', $shopOwnerId)
            ->visible()
            ->with([
                'user:id,name,email',
                'repairRequest:id,shoe_type',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id'             => 'repair_' . $r->id,
                'customerName'   => $r->user?->name ?? 'Unknown Customer',
                'rating'         => (int) $r->rating,
                'comment'        => $r->review_text ?? '',
                'feedbackImages' => $r->review_images ?? [],
                'serviceType'    => $r->repairRequest?->shoe_type ?? 'Repair Service',
                'orderType'      => 'repair',
                'responseStatus' => ($r->shop_response && $r->shop_responded_at) ? 'responded' : 'pending',
                'shopResponse'   => $r->shop_response,
                'createdAt'      => $r->created_at->format('Y-m-d'),
            ]);

        // Merge and sort newest first
        $all = $productReviews
            ->concat($repairReviews)
            ->sortByDesc('createdAt')
            ->values();

        // Aggregate stats (unfiltered totals for metric cards)
        $stats = [
            'total'            => $all->count(),
            'averageRating'    => $all->count() > 0
                ? round($all->avg('rating'), 1)
                : 0,
            'pendingResponses' => $all->where('responseStatus', 'pending')->count(),
            'respondedCount'   => $all->where('responseStatus', 'responded')->count(),
        ];

        return response()->json([
            'reviews' => $all,
            'stats'   => $stats,
        ]);
    }
}
