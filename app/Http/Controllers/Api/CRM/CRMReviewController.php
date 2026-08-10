<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\ProductReview;
use App\Models\RepairReview;
use App\Models\ReviewReport;
use App\Models\ShopReview;
use App\Enums\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use App\Support\Erp\ErpActorContext;
use Inertia\Inertia;

class CRMReviewController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function shopOwnerIds(): array
    {
        $context = request()->attributes->get('erp.actor_context');
        if ($context instanceof ErpActorContext) {
            return [(int) $context->tenantOwner()->getKey()];
        }

        $user = Auth::guard('user')->user() ?? Auth::user();
        if (! $user) {
            return [];
        }

        $ids = [];

        if (!empty($user->shop_owner_id)) {
            $ids[] = (int) $user->shop_owner_id;
        }

        if (!empty($user->id)) {
            $ids[] = (int) $user->id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function mergedReviews(array $shopOwnerIds): Collection
    {
        if (empty($shopOwnerIds)) {
            return collect();
        }

        $productReviews = ProductReview::query()
            ->where(function ($query) use ($shopOwnerIds) {
                $query->whereIn('shop_owner_id', $shopOwnerIds)
                    ->orWhereHas('product', function ($productQuery) use ($shopOwnerIds) {
                        $productQuery->whereIn('shop_owner_id', $shopOwnerIds);
                    });
            })
            ->with(['user:id,name,email', 'product:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ProductReview $r) {
                return [
                    'id' => (int) $r->id,
                    'reviewId' => 'product_' . $r->id,
                    'customerName' => $r->user?->name ?? 'Unknown Customer',
                    'customer' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'email' => $r->user->email] : null,
                    'order' => $r->order ? ['id' => $r->order->id, 'order_number' => $r->order->order_number] : null,
                    'orderType' => 'product',
                    'serviceType' => $r->product?->name ?? 'Product',
                    'rating' => (int) $r->rating,
                    'comment' => $r->comment ?? '',
                    'feedbackImages' => $r->images ?? [],
                    'createdAt' => $r->created_at->toDateTimeString(),
                    'createdAtTs' => $r->created_at?->timestamp ?? 0,
                ];
            });

        $repairReviews = RepairReview::query()
            ->where(function ($query) use ($shopOwnerIds) {
                $query->whereIn('shop_owner_id', $shopOwnerIds)
                    ->orWhereHas('repairRequest', function ($repairRequestQuery) use ($shopOwnerIds) {
                        $repairRequestQuery->whereIn('shop_owner_id', $shopOwnerIds);
                    });
            })
            ->with(['user:id,name,email', 'repairRequest:id,shoe_type'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (RepairReview $r) {
                return [
                    'id' => (int) $r->id,
                    'reviewId' => 'repair_' . $r->id,
                    'customerName' => $r->user?->name ?? 'Unknown Customer',
                    'customer' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'email' => $r->user->email] : null,
                    'order' => null,
                    'orderType' => 'repair',
                    'serviceType' => $r->repairRequest?->shoe_type ?? 'Repair Service',
                    'rating' => (int) $r->rating,
                    'comment' => $r->review_text ?? '',
                    'feedbackImages' => $r->review_images ?? [],
                    'createdAt' => $r->created_at->toDateTimeString(),
                    'createdAtTs' => $r->created_at?->timestamp ?? 0,
                ];
            });

        $shopReviews = ShopReview::query()
            ->whereIn('shop_owner_id', $shopOwnerIds)
            ->with(['user:id,name,email'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ShopReview $r) {
                return [
                    'id' => (int) $r->id,
                    'reviewId' => 'shop_' . $r->id,
                    'customerName' => $r->user?->name ?? 'Unknown Customer',
                    'customer' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'email' => $r->user->email] : null,
                    'order' => null,
                    'orderType' => 'repair',
                    'serviceType' => 'Shop Service',
                    'rating' => (int) $r->rating,
                    'comment' => $r->comment ?? '',
                    'feedbackImages' => $r->images ?? [],
                    'createdAt' => $r->created_at->toDateTimeString(),
                    'createdAtTs' => $r->created_at?->timestamp ?? 0,
                ];
            });

        return $productReviews
            ->concat($repairReviews)
            ->concat($shopReviews)
            ->sortByDesc('createdAtTs')
            ->values()
            ->map(function (array $item) {
                unset($item['createdAtTs']);
                return $item;
            });
    }

    /**
     * Compute review aggregate stats for a given shop.
     * Shared between the JSON index() and the Inertia indexPage().
     */
    private function buildStats(Collection $reviews): array
    {
        return [
            'total'          => $reviews->count(),
            'average_rating' => round((float) ($reviews->avg('rating') ?? 0), 2),
        ];
    }

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * GET /crm/customer-reviews  (Inertia page)
     *
     * Server-side renders the reviews list with the first page of data and
     * aggregate stats so the page is immediately useful on load.
     */
    public function indexPage()
    {
        $user = Auth::guard('user')->user();
        $context = request()->attributes->get('erp.actor_context');

        if (! $context instanceof ErpActorContext && $user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $shopOwnerIds = $this->shopOwnerIds();
        $reviews = $this->mergedReviews($shopOwnerIds);

        return Inertia::render('ERP/CRM/CustomerReviews', [
            'initialReviews' => $reviews->values(),
            'initialStats'   => $this->buildStats($reviews),
        ]);
    }

    /**
     * GET /api/crm/reviews
     *
     * Paginated review list.  Supports filters:
     *   status        (pending | in_progress | responded)
     *   order_type    (product | repair)
     *   min_rating    (1–5)
     *   max_rating    (1–5)
     *   search        (comment, service_type, or customer name)
     *
     * Also returns aggregate stats in the response envelope.
     */
    public function index(Request $request): JsonResponse
    {
        $shopOwnerIds = $this->shopOwnerIds();
        $allReviews = $this->mergedReviews($shopOwnerIds);

        $orderType = strtolower((string) $request->get('order_type', ''));
        $search = strtolower(trim((string) $request->get('search', '')));
        $minRating = (int) $request->get('min_rating', 0);
        $maxRating = (int) $request->get('max_rating', 5);

        $filtered = $allReviews->filter(function (array $review) use ($orderType, $search, $minRating, $maxRating) {
            if ($orderType !== '' && in_array($orderType, ['product', 'repair'], true) && $review['orderType'] !== $orderType) {
                return false;
            }

            $rating = (int) ($review['rating'] ?? 0);
            if ($minRating > 0 && $rating < $minRating) {
                return false;
            }
            if ($maxRating > 0 && $rating > $maxRating) {
                return false;
            }

            if ($search !== '') {
                $haystack = strtolower(trim(sprintf(
                    '%s %s %s',
                    $review['customerName'] ?? '',
                    $review['comment'] ?? '',
                    $review['serviceType'] ?? ''
                )));

                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        })->values();

        $page = max(1, (int) $request->get('page', 1));
        $perPage = max(1, (int) $request->get('per_page', 20));
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $items = $filtered->forPage($page, $perPage)->values();

        return response()->json([
            'reviews' => [
                'data' => $items,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'stats'   => $this->buildStats($allReviews),
        ]);
    }

    /**
     * POST /api/crm/reviews/report
     *
     * Allow CRM users to report malicious reviews to super admins.
     */
    public function reportReview(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user() ?? Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $shopOwnerIds = $this->shopOwnerIds();
        if (empty($shopOwnerIds)) {
            return response()->json(['error' => 'No shop association found.'], 403);
        }

        $actingShopOwnerId = !empty($user->shop_owner_id)
            ? (int) $user->shop_owner_id
            : (int) $user->id;

        $validated = $request->validate([
            'review_id' => 'required|string',
            'reason' => ['required', Rule::in([
                'fake_review', 'harassment', 'spam', 'inappropriate_content', 'other',
            ])],
            'notes' => 'nullable|string|max:1000',
        ]);

        if (!str_contains($validated['review_id'], '_')) {
            return response()->json(['error' => 'Invalid review ID format.'], 422);
        }

        [$type, $rawId] = explode('_', $validated['review_id'], 2);
        $reviewId = (int) $rawId;

        if (!in_array($type, ['product', 'repair', 'shop'], true)) {
            return response()->json(['error' => 'Invalid review type.'], 422);
        }

        $customerId = null;
        $snapshot = [];

        if ($type === 'product') {
            $review = ProductReview::query()
                ->where('id', $reviewId)
                ->where(function ($query) use ($shopOwnerIds) {
                    $query->whereIn('shop_owner_id', $shopOwnerIds)
                        ->orWhereHas('product', function ($productQuery) use ($shopOwnerIds) {
                            $productQuery->whereIn('shop_owner_id', $shopOwnerIds);
                        });
                })
                ->with('user:id,name,email')
                ->first();

            if (!$review) {
                return response()->json(['error' => 'Review not found.'], 404);
            }

            $customerId = $review->user_id;
            $snapshot = [
                'type' => 'product',
                'rating' => $review->rating,
                'comment' => $review->comment,
                'images' => $review->images ?? [],
                'customerName' => $review->user?->name ?? 'Unknown',
                'createdAt' => $review->created_at?->format('Y-m-d'),
            ];
        } elseif ($type === 'repair') {
            $review = RepairReview::query()
                ->where('id', $reviewId)
                ->where(function ($query) use ($shopOwnerIds) {
                    $query->whereIn('shop_owner_id', $shopOwnerIds)
                        ->orWhereHas('repairRequest', function ($repairRequestQuery) use ($shopOwnerIds) {
                            $repairRequestQuery->whereIn('shop_owner_id', $shopOwnerIds);
                        });
                })
                ->with('user:id,name,email')
                ->first();

            if (!$review) {
                return response()->json(['error' => 'Review not found.'], 404);
            }

            $customerId = $review->user_id;
            $snapshot = [
                'type' => 'repair',
                'rating' => $review->rating,
                'comment' => $review->review_text,
                'images' => $review->review_images ?? [],
                'customerName' => $review->user?->name ?? 'Unknown',
                'createdAt' => $review->created_at?->format('Y-m-d'),
            ];
        } else {
            $review = ShopReview::query()
                ->where('id', $reviewId)
                ->whereIn('shop_owner_id', $shopOwnerIds)
                ->with('user:id,name,email')
                ->first();

            if (!$review) {
                return response()->json(['error' => 'Review not found.'], 404);
            }

            $customerId = $review->user_id;
            $snapshot = [
                'type' => 'shop',
                'rating' => $review->rating,
                'comment' => $review->comment,
                'images' => $review->images ?? [],
                'customerName' => $review->user?->name ?? 'Unknown',
                'createdAt' => $review->created_at?->format('Y-m-d'),
            ];
        }

        $existing = ReviewReport::query()
            ->where('review_type', $type)
            ->where('review_id', $reviewId)
            ->where('shop_owner_id', $actingShopOwnerId)
            ->whereNotIn('status', ['dismissed'])
            ->first();

        if ($existing) {
            return response()->json(['error' => 'You have already reported this review.'], 409);
        }

        $report = ReviewReport::create([
            'review_type' => $type,
            'review_id' => $reviewId,
            'shop_owner_id' => $actingShopOwnerId,
            'user_id' => $customerId,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'review_snapshot' => $snapshot,
            'status' => 'pending_review',
        ]);

        $reasonLabel = ReviewReport::$reasonLabels[$validated['reason']] ?? $validated['reason'];
        $shopName = $user->shopOwner?->business_name
            ?? $user->shopOwner?->shop_name
            ?? $user->name
            ?? 'CRM User';

        Notification::notifyAllSuperAdmins(
            type: NotificationType::REVIEW_REPORTED,
            title: 'Malicious Review Reported',
            message: "{$shopName} reported a customer review for: {$reasonLabel}",
            actionUrl: '/superAdmin/flagged-accounts',
            data: ['review_report_id' => $report->id],
        );

        return response()->json([
            'message' => 'Review reported successfully. Our team will review it shortly.',
            'report' => $report->only(['id', 'status']),
        ]);
    }
}
