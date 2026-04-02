<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\RepairReview;
use App\Models\ShopReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class CRMReviewController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function shopOwnerIds(): array
    {
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

        if ($user?->force_password_change) {
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
}
