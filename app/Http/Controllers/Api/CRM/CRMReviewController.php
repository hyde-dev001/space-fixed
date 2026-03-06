<?php

namespace App\Http\Controllers\API\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class CRMReviewController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function shopOwnerId(): int
    {
        $user = Auth::user();
        return $user->shop_owner_id ?? $user->id;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Compute review aggregate stats for a given shop.
     * Shared between the JSON index() and the Inertia indexPage().
     */
    private function buildStats(int $shopOwnerId): array
    {
        $base = CustomerReview::forShopOwner($shopOwnerId);

        return [
            'total'          => (clone $base)->count(),
            'pending'        => (clone $base)->withStatus('pending')->count(),
            'in_progress'    => (clone $base)->withStatus('in_progress')->count(),
            'responded'      => (clone $base)->withStatus('responded')->count(),
            'average_rating' => round((float) (clone $base)->avg('rating'), 2),
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

        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $reviews = CustomerReview::forShopOwner($shopOwnerId)
            ->with(['customer:id,name,email', 'order:id,order_number'])
            ->latest()
            ->get()
            ->map(fn(CustomerReview $r) => [
                'id'              => $r->id,
                // camelCase fields expected by CustomerReviews.tsx
                'customerName'    => $r->customer?->name ?? 'Unknown Customer',
                'customer'        => $r->customer ? ['id' => $r->customer->id, 'name' => $r->customer->name, 'email' => $r->customer->email] : null,
                'order'           => $r->order    ? ['id' => $r->order->id, 'order_number' => $r->order->order_number] : null,
                'orderType'       => $r->order_type,
                'serviceType'     => $r->service_type,
                'rating'          => $r->rating,
                'comment'         => $r->comment,
                'feedbackImages'  => $r->feedback_images ?? [],
                'responseStatus'  => $r->response_status,
                'staffResponse'   => $r->staff_response,
                'respondedAt'     => $r->responded_at?->toDateTimeString(),
                'createdAt'       => $r->created_at->toDateTimeString(),
            ]);

        return Inertia::render('ERP/CRM/CustomerReviews', [
            'initialReviews' => $reviews,
            'initialStats'   => $this->buildStats($shopOwnerId),
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
        $shopOwnerId = $this->shopOwnerId();

        $query = CustomerReview::forShopOwner($shopOwnerId)
            ->with(['customer:id,name,email', 'order:id,order_number'])
            ->latest();

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('order_type')) {
            $query->ofType($request->order_type);
        }

        if ($request->filled('min_rating')) {
            $query->minRating((int) $request->min_rating);
        }

        if ($request->filled('max_rating')) {
            $query->where('rating', '<=', (int) $request->max_rating);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', "%{$search}%")
                  ->orWhere('service_type', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($cu) => $cu->where('name', 'like', "%{$search}%"));
            });
        }

        $reviews = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'reviews' => $reviews,
            'stats'   => $this->buildStats($shopOwnerId),
        ]);
    }

    /**
     * POST /api/crm/reviews/{id}/respond
     *
     * Saves a staff response, marks response_status = responded,
     * and records the responded_at timestamp.
     */
    public function respond(Request $request, int $id): JsonResponse
    {
        $shopOwnerId = $this->shopOwnerId();

        $review = CustomerReview::forShopOwner($shopOwnerId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'staff_response' => 'required|string|max:3000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $review->update([
            'staff_response'  => $request->staff_response,
            'response_status' => 'responded',
            'responded_at'    => now(),
        ]);

        return response()->json([
            'message' => 'Response saved successfully',
            'review'  => $review->fresh(['customer:id,name,email']),
        ]);
    }

    /**
     * PATCH /api/crm/reviews/{id}/status
     *
     * Moves a review back to pending or marks it in_progress.
     * (Use respond() to mark it as responded.)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $shopOwnerId = $this->shopOwnerId();

        $review = CustomerReview::forShopOwner($shopOwnerId)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,in_progress',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $review->update(['response_status' => $request->status]);

        return response()->json([
            'message' => 'Status updated successfully',
            'review'  => $review,
        ]);
    }
}
