<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RepairReview;
use App\Models\RepairRequest;
use App\Models\PosRefund;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class RepairReviewController extends Controller
{
    /**
     * Submit a review for a completed repair (Phase 10D)
     * Only customers who picked up the repair can review
     */
    public function store(Request $request, $repairId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
            'review_images' => 'nullable|array|max:3',
            'review_images.*' => 'image|mimes:jpeg,png,jpg|max:2048',
        ]);

        // Verify repair belongs to customer and is picked up
        $repair = RepairRequest::where('id', $repairId)
            ->where('user_id', $user->id)
            ->where('status', 'picked_up')
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair not found or not eligible for review. You can only review repairs after pickup.'
            ], 404);
        }

        $linkedRepairIds = $this->resolveLinkedRepairIds($repair, (int) $user->id);

        // Check if review already exists
        $existingReview = RepairReview::whereIn('repair_request_id', $linkedRepairIds)->first();
        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this repair'
            ], 400);
        }

        if ($this->hasBlockingRefundFlow($linkedRepairIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Review is not allowed after a refund request has been filed for this repair.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Handle image uploads
            $imagePaths = [];
            if ($request->hasFile('review_images')) {
                foreach ($request->file('review_images') as $image) {
                    $path = $image->store('review-images', 'public');
                    $imagePaths[] = $path;
                }
            }

            $review = RepairReview::create([
                'repair_request_id' => $repairId,
                'user_id' => $user->id,
                'shop_owner_id' => $repair->shop_owner_id,
                'repairer_id' => $repair->assigned_repairer_id,
                'rating' => $request->rating,
                'review_text' => $request->review_text,
                'review_images' => $imagePaths,
                'is_verified' => true, // Verified purchase
            ]);

            DB::commit();

            // TODO: Notify shop owner of new review
            
            return response()->json([
                'success' => true,
                'message' => 'Thank you for your review!',
                'review' => $review->load(['user', 'repairer'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all reviews for a shop owner
     */
    public function getShopReviews($shopOwnerId)
    {
        $reviews = RepairReview::forShop($shopOwnerId)
            ->visible()
            ->with(['user:id,first_name,last_name', 'repairer:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $avgRating = RepairReview::forShop($shopOwnerId)
            ->visible()
            ->avg('rating');

        $totalReviews = RepairReview::forShop($shopOwnerId)
            ->visible()
            ->count();

        $ratingDistribution = RepairReview::forShop($shopOwnerId)
            ->visible()
            ->select('rating', DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get()
            ->pluck('count', 'rating');

        return response()->json([
            'success' => true,
            'reviews' => $reviews,
            'stats' => [
                'average_rating' => round($avgRating, 1),
                'total_reviews' => $totalReviews,
                'rating_distribution' => $ratingDistribution,
            ]
        ]);
    }

    /**
     * Check if customer can review a specific repair
     */
    public function canReview($repairId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'can_review' => false,
                'reason' => 'Not authenticated'
            ]);
        }

        $repair = RepairRequest::where('id', $repairId)
            ->where('user_id', $user->id)
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'Repair not found'
            ]);
        }

        if ($repair->status !== 'picked_up') {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'Repair must be picked up before reviewing'
            ]);
        }

        $linkedRepairIds = $this->resolveLinkedRepairIds($repair, (int) $user->id);

        $existingReview = RepairReview::whereIn('repair_request_id', $linkedRepairIds)->first();
        if ($existingReview) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'Already reviewed',
                'review' => $existingReview
            ]);
        }

        if ($this->hasBlockingRefundFlow($linkedRepairIds)) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'reason' => 'Refund already requested or completed',
            ]);
        }

        return response()->json([
            'success' => true,
            'can_review' => true,
            'repair' => $repair->load(['shopOwner:id,business_name', 'repairer:id,first_name,last_name'])
        ]);
    }

    /**
     * Get review for a specific repair
     */
    public function getRepairReview($repairId)
    {
        $review = RepairReview::where('repair_request_id', $repairId)
            ->with(['user:id,first_name,last_name', 'repairer:id,first_name,last_name'])
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'review' => $review
        ]);
    }

    /**
     * Shop owner responds to a review
     */
    public function respond(Request $request, $reviewId)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'response' => 'required|string|max:500',
        ]);

        $review = RepairReview::where('id', $reviewId)
            ->where('shop_owner_id', $shopOwner->id)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        $review->update([
            'shop_response' => $request->response,
            'shop_responded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Response added successfully',
            'review' => $review->fresh()
        ]);
    }

    /**
     * @return int[]
     */
    private function resolveLinkedRepairIds(RepairRequest $repair, int $customerUserId): array
    {
        $anchorRepairId = ((bool) ($repair->is_warranty_job ?? false) && (int) ($repair->parent_repair_request_id ?? 0) > 0)
            ? (int) $repair->parent_repair_request_id
            : (int) $repair->id;

        $linkedRepairIds = RepairRequest::query()
            ->where('user_id', $customerUserId)
            ->where(function ($query) use ($anchorRepairId) {
                $query->where('id', $anchorRepairId)
                    ->orWhere('parent_repair_request_id', $anchorRepairId);
            })
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (empty($linkedRepairIds)) {
            $linkedRepairIds = [$anchorRepairId];
        }

        return array_values(array_unique($linkedRepairIds));
    }

    private function hasBlockingRefundFlow(array $linkedRepairIds): bool
    {
        if (empty($linkedRepairIds)) {
            return false;
        }

        return PosRefund::query()
            ->where('module_type', 'repair')
            ->whereIn('module_reference_id', $linkedRepairIds)
            ->whereIn('status', ['requested', 'approved', 'processing', 'succeeded'])
            ->exists();
    }
}
