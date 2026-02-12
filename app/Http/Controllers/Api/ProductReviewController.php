<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductReviewController extends Controller
{
    /**
     * Get reviews for a specific product
     */
    public function index(Request $request, $productId)
    {
        try {
            $product = Product::findOrFail($productId);
            
            $reviews = ProductReview::getProductReviews($productId);
            $averageRating = ProductReview::getAverageRating($productId);
            $ratingDistribution = ProductReview::getRatingDistribution($productId);
            $totalReviews = $reviews->count();

            return response()->json([
                'success' => true,
                'reviews' => $reviews,
                'statistics' => [
                    'average_rating' => $averageRating,
                    'total_reviews' => $totalReviews,
                    'rating_distribution' => $ratingDistribution,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch product reviews', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews',
            ], 500);
        }
    }

    /**
     * Check if user can review a product
     */
    public function checkEligibility(Request $request, $productId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'can_review' => false,
                'reason' => 'not_logged_in',
                'message' => 'Please log in to write a review.',
            ]);
        }

        // Check if user is ERP staff (they shouldn't be able to review as customers)
        $userRole = strtoupper($user->role ?? '');
        $isERPStaff = in_array($userRole, ['HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'FINANCE', 'CRM', 'MANAGER', 'STAFF']);
        
        if ($isERPStaff) {
            return response()->json([
                'success' => false,
                'can_review' => false,
                'reason' => 'staff_user',
                'message' => 'Staff accounts cannot write product reviews.',
            ]);
        }

        $eligibility = ProductReview::canUserReview($user->id, $productId);

        return response()->json([
            'success' => true,
            'can_review' => $eligibility['can_review'],
            'reason' => $eligibility['reason'] ?? null,
            'message' => $eligibility['message'],
            'order_id' => $eligibility['order_id'] ?? null,
            'order_status' => $eligibility['order_status'] ?? null,
            'existing_review' => $eligibility['existing_review'] ?? null,
        ]);
    }

    /**
     * Store a new review
     */
    public function store(Request $request, $productId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in to write a review.',
            ], 401);
        }

        // Verify eligibility
        $eligibility = ProductReview::canUserReview($user->id, $productId);

        if (!$eligibility['can_review']) {
            return response()->json([
                'success' => false,
                'message' => $eligibility['message'],
                'reason' => $eligibility['reason'],
            ], 403);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10|max:2000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'nullable|image|mimes:jpeg,jpg,png|max:5120', // 5MB max per image
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $product = Product::findOrFail($productId);
            
            // Handle image uploads
            $imagePaths = [];
            if ($request->hasFile('images')) {
                // Ensure directory exists
                Storage::makeDirectory('public/reviews');
                
                foreach ($request->file('images') as $index => $image) {
                    if ($image && $image->isValid()) {
                        $filename = 'review_' . $user->id . '_' . $productId . '_' . time() . '_' . $index . '.' . $image->getClientOriginalExtension();
                        $path = $image->storeAs('public/reviews', $filename);
                        if ($path) {
                            // Use the direct storage route instead of Storage::url()
                            $imagePaths[] = '/storage/reviews/' . $filename;
                        }
                    }
                }
            }

            // Create the review
            $review = ProductReview::create([
                'product_id' => $productId,
                'user_id' => $user->id,
                'order_id' => $eligibility['order_id'],
                'shop_owner_id' => $product->shop_owner_id,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'images' => $imagePaths,
                'is_verified_purchase' => true,
                'is_approved' => true, // Auto-approve for now
            ]);

            Log::info('Product review created', [
                'review_id' => $review->id,
                'product_id' => $productId,
                'user_id' => $user->id,
                'rating' => $request->rating,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Thank you for your review!',
                'review' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'images' => $review->images,
                    'created_at' => $review->created_at->format('F d, Y'),
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create product review', [
                'product_id' => $productId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review. Please try again.',
            ], 500);
        }
    }

    /**
     * Get user's own review for a product
     */
    public function getUserReview(Request $request, $productId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $review = ProductReview::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'No review found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'review' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'images' => $review->images,
                'is_verified_purchase' => $review->is_verified_purchase,
                'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                'formatted_date' => $review->created_at->format('F d, Y'),
            ],
        ]);
    }

    /**
     * Update user's review
     */
    public function update(Request $request, $productId, $reviewId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $review = ProductReview::where('id', $reviewId)
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found or unauthorized',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|required|integer|min:1|max:5',
            'comment' => 'sometimes|required|string|min:10|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $review->update($request->only(['rating', 'comment']));

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully',
                'review' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'updated_at' => $review->updated_at->format('F d, Y'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update product review', [
                'review_id' => $reviewId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update review',
            ], 500);
        }
    }

    /**
     * Delete user's review
     */
    public function destroy(Request $request, $productId, $reviewId)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $review = ProductReview::where('id', $reviewId)
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found or unauthorized',
            ], 404);
        }

        try {
            // Delete review images from storage
            if ($review->images && is_array($review->images)) {
                foreach ($review->images as $imagePath) {
                    // Convert URL to storage path
                    $path = str_replace('/storage/', 'public/', $imagePath);
                    Storage::delete($path);
                }
            }

            $review->delete();

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete product review', [
                'review_id' => $reviewId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
            ], 500);
        }
    }
}
