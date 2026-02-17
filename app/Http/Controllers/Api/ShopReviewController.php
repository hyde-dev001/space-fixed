<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopReview;
use App\Models\ShopOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShopReviewController extends Controller
{
    /**
     * Get all reviews for a shop
     */
    public function index($shopId)
    {
        $reviews = ShopReview::where('shop_owner_id', $shopId)
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($review) {
                return [
                    'id' => $review->id,
                    'user_name' => $review->user->name ?? 'Anonymous',
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'images' => $review->images,
                    'created_at' => $review->created_at->toDateTimeString(),
                    'verified' => true,
                ];
            });

        $totalReviews = $reviews->count();
        $averageRating = $totalReviews > 0 ? round($reviews->avg('rating'), 1) : 0;
        
        $ratingDistribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = $reviews->where('rating', $i)->count();
            $ratingDistribution[$i] = [
                'count' => $count,
                'percentage' => $totalReviews > 0 ? round(($count / $totalReviews) * 100) : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'reviews' => $reviews,
            'statistics' => [
                'average_rating' => $averageRating,
                'total_reviews' => $totalReviews,
                'rating_distribution' => $ratingDistribution,
            ],
        ]);
    }

    public function checkEligibility($shopId)
    {
        // Check both web and user guards
        $user = Auth::guard('web')->user() ?? Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'message' => 'Please log in to write a review',
                'reason' => 'not_authenticated',
            ]);
        }

        $existingReview = ShopReview::where('shop_owner_id', $shopId)
            ->where('user_id', $user->id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'message' => 'You have already reviewed this shop',
                'reason' => 'already_reviewed',
                'existing_review' => [
                    'rating' => $existingReview->rating,
                    'comment' => $existingReview->comment,
                    'created_at' => $existingReview->created_at->toDateTimeString(),
                ],
            ]);
        }

        // Check if user has completed an order (either product order or repair service) from this shop
        $hasCompletedOrder = DB::table('orders')
            ->where('customer_id', $user->id)
            ->where('shop_owner_id', $shopId)
            ->whereIn('status', ['delivered', 'completed'])
            ->exists();

        // Also check if user has completed a repair service from this shop
        $hasCompletedRepair = DB::table('repair_requests')
            ->where('user_id', $user->id)
            ->where('shop_owner_id', $shopId)
            ->where('status', 'picked_up')
            ->exists();

        if (!$hasCompletedOrder && !$hasCompletedRepair) {
            return response()->json([
                'success' => true,
                'can_review' => false,
                'message' => 'You can only review shops where you have completed a purchase or repair service',
                'reason' => 'no_completed_service',
            ]);
        }

        return response()->json([
            'success' => true,
            'can_review' => true,
            'message' => 'You are eligible to review this shop',
        ]);
    }

    public function store(Request $request, $shopId)
    {
        // Check both web and user guards
        $user = Auth::guard('web')->user() ?? Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in to write a review',
            ], 401);
        }

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10|max:1000',
            'images.*' => 'nullable|image|max:5120',
        ]);

        $existingReview = ShopReview::where('shop_owner_id', $shopId)
            ->where('user_id', $user->id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this shop. Each user can only submit one review.',
            ], 422);
        }

        $hasCompletedOrder = DB::table('orders')
            ->where('customer_id', $user->id)
            ->where('shop_owner_id', $shopId)
            ->whereIn('status', ['delivered', 'completed'])
            ->exists();

        $hasCompletedRepair = DB::table('repair_requests')
            ->where('user_id', $user->id)
            ->where('shop_owner_id', $shopId)
            ->where('status', 'picked_up')
            ->exists();

        if (!$hasCompletedOrder && !$hasCompletedRepair) {
            return response()->json([
                'success' => false,
                'message' => 'You can only review shops where you have completed a purchase or repair service',
            ], 403);
        }

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('shop-reviews', 'public');
                $imagePaths[] = Storage::url($path);
            }
        }

        $review = ShopReview::create([
            'shop_owner_id' => $shopId,
            'user_id' => $user->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'images' => $imagePaths,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your review!',
            'review' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toDateTimeString(),
            ],
        ]);
    }
}
