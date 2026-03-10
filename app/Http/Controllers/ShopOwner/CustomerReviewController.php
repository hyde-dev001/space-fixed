<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\ProductReview;
use App\Models\RepairReview;
use App\Models\ReviewReport;
use App\Enums\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
                'responseStatus' => ($r->shop_response && $r->shop_responded_at) ? 'responded' : 'pending',
                'shopResponse'   => $r->shop_response,
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

    /**
     * Shop owner writes a public response to a customer review.
     * POST /api/shop-owner/reviews/{id}/respond
     */
    public function respond(Request $request, string $id): JsonResponse
    {
        $shopOwner   = Auth::guard('shop_owner')->user();
        $validated   = $request->validate([
            'response' => 'required|string|max:1000',
        ]);

        [$type, $rawId] = explode('_', $id, 2);
        $reviewId = (int) $rawId;

        if ($type === 'product') {
            $review = ProductReview::where('id', $reviewId)
                ->where('shop_owner_id', $shopOwner->id)
                ->firstOrFail();

            $review->update([
                'shop_response'     => $validated['response'],
                'shop_responded_at' => now(),
            ]);

            return response()->json([
                'shopResponse'   => $review->shop_response,
                'responseStatus' => 'responded',
            ]);
        }

        if ($type === 'repair') {
            $review = RepairReview::where('id', $reviewId)
                ->where('shop_owner_id', $shopOwner->id)
                ->firstOrFail();

            $review->update([
                'shop_response'     => $validated['response'],
                'shop_responded_at' => now(),
            ]);

            return response()->json([
                'shopResponse'   => $review->shop_response,
                'responseStatus' => 'responded',
            ]);
        }

        return response()->json(['error' => 'Invalid review type.'], 422);
    }

    /**
     * Shop owner reports a malicious/fake customer review.
     * POST /api/shop-owner/reviews/report
     */
    public function reportReview(Request $request): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'review_id' => 'required|string',   // e.g. "product_42" or "repair_17"
            'reason'    => ['required', Rule::in([
                'fake_review', 'harassment', 'spam', 'inappropriate_content', 'other',
            ])],
            'notes' => 'nullable|string|max:1000',
        ]);

        // Parse the prefixed review ID
        [$type, $rawId] = explode('_', $validated['review_id'], 2);
        $reviewId = (int) $rawId;

        if (!in_array($type, ['product', 'repair'])) {
            return response()->json(['error' => 'Invalid review type.'], 422);
        }

        // Load the review and confirm it belongs to this shop
        if ($type === 'product') {
            $review = ProductReview::where('id', $reviewId)
                ->where('shop_owner_id', $shopOwner->id)
                ->with('user:id,name,email')
                ->first();
            if (!$review) {
                return response()->json(['error' => 'Review not found.'], 404);
            }
            $customerId = $review->user_id;
            $snapshot = [
                'type'         => 'product',
                'rating'       => $review->rating,
                'comment'      => $review->comment,
                'images'       => $review->images ?? [],
                'customerName' => $review->user?->name ?? 'Unknown',
                'createdAt'    => $review->created_at?->format('Y-m-d'),
            ];
        } else {
            $review = RepairReview::where('id', $reviewId)
                ->where('shop_owner_id', $shopOwner->id)
                ->with('user:id,name,email')
                ->first();
            if (!$review) {
                return response()->json(['error' => 'Review not found.'], 404);
            }
            $customerId = $review->user_id;
            $snapshot = [
                'type'         => 'repair',
                'rating'       => $review->rating,
                'comment'      => $review->review_text,
                'images'       => $review->review_images ?? [],
                'customerName' => $review->user?->name ?? 'Unknown',
                'createdAt'    => $review->created_at?->format('Y-m-d'),
            ];
        }

        // Prevent duplicate pending reports for the same review
        $existing = ReviewReport::where('review_type', $type)
            ->where('review_id', $reviewId)
            ->where('shop_owner_id', $shopOwner->id)
            ->whereNotIn('status', ['dismissed'])
            ->first();

        if ($existing) {
            return response()->json(['error' => 'You have already reported this review.'], 409);
        }

        $report = ReviewReport::create([
            'review_type'     => $type,
            'review_id'       => $reviewId,
            'shop_owner_id'   => $shopOwner->id,
            'user_id'         => $customerId,
            'reason'          => $validated['reason'],
            'notes'           => $validated['notes'] ?? null,
            'review_snapshot' => $snapshot,
            'status'          => 'pending_review',
        ]);

        // Notify all super admins
        $shopName   = $shopOwner->business_name ?? $shopOwner->first_name . ' ' . $shopOwner->last_name;
        $reasonLabel = ReviewReport::$reasonLabels[$validated['reason']] ?? $validated['reason'];

        Notification::notifyAllSuperAdmins(
            type: NotificationType::REVIEW_REPORTED,
            title: 'Malicious Review Reported',
            message: "{$shopName} reported a customer review for: {$reasonLabel}",
            actionUrl: '/superAdmin/flagged-accounts',
            data: ['review_report_id' => $report->id],
        );

        return response()->json([
            'message' => 'Review reported successfully. Our team will review it shortly.',
            'report'  => $report->only(['id', 'status']),
        ]);
    }
}
