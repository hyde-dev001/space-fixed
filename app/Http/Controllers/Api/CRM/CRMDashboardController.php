<?php

namespace App\Http\Controllers\API\CRM;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CRM\CustomerReview;
use App\Models\Order;
use App\Models\RepairRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CRMDashboardController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function shopOwnerId(): int
    {
        $user = Auth::guard('user')->user() ?? Auth::user();
        return $user->shop_owner_id ?? $user->id;
    }

    // ─── Shared data builder ──────────────────────────────────────────────────

    /**
     * Compute all CRM dashboard data for the given shop.
     * Shared between the JSON API endpoint and the Inertia page render.
     */
    private function buildDashboardData(int $shopOwnerId): array
    {
        $since90Days = Carbon::now()->subDays(90);

        // ── Active customers (any activity in the last 90 days) ───────────────

        $recentOrderCustomerIds  = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $since90Days)
            ->distinct()
            ->pluck('customer_id');

        $recentRepairCustomerIds = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since90Days)
            ->distinct()
            ->pluck('user_id');

        $activeCustomerCount = $recentOrderCustomerIds
            ->merge($recentRepairCustomerIds)
            ->unique()
            ->count();

        // ── Open conversations ────────────────────────────────────────────────

        $openConversationCount = Conversation::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        // ── Reviews pending response ──────────────────────────────────────────

        $pendingReviewCount = CustomerReview::forShopOwner($shopOwnerId)
            ->where('response_status', 'pending')
            ->count();

        // ── Average rating (1 decimal, 0 when no reviews) ────────────────────

        $avgRating = round(
            (float) CustomerReview::forShopOwner($shopOwnerId)->avg('rating'),
            1
        );

        // ── Engagement by channel ─────────────────────────────────────────────

        $channelLabels = [
            'crm'        => 'CRM Support',
            'repairer'   => 'Repair Desk',
            'shop_owner' => 'Direct (Owner)',
        ];

        $rawCounts = Conversation::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_to_type')
            ->selectRaw('assigned_to_type as channel, COUNT(*) as count')
            ->groupBy('assigned_to_type')
            ->pluck('count', 'channel');

        $engagementByChannel = collect($channelLabels)
            ->map(fn($label, $key) => [
                'channel' => $label,
                'count'   => (int) ($rawCounts[$key] ?? 0),
            ])
            ->values();

        // ── Recent 5 customer interactions ───────────────────────────────────

        $recentInteractions = Conversation::where('shop_owner_id', $shopOwnerId)
            ->with([
                'customer:id,name,email',
                'messages' => fn($q) => $q->latest()->limit(1),
            ])
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get()
            ->map(function (Conversation $conv) {
                $lastMsg = $conv->messages->first();
                return [
                    'conversation_id' => $conv->id,
                    'customer_name'   => $conv->customer?->name ?? 'Unknown',
                    'customer_email'  => $conv->customer?->email,
                    'last_message'    => $lastMsg?->content ?? '',
                    'last_message_at' => $conv->last_message_at?->diffForHumans(),
                    'status'          => $conv->status,
                    'priority'        => $conv->priority,
                ];
            });

        return [
            'active_customers'      => $activeCustomerCount,
            'open_conversations'    => $openConversationCount,
            'pending_reviews'       => $pendingReviewCount,
            'average_rating'        => $avgRating,
            'engagement_by_channel' => $engagementByChannel,
            'recent_interactions'   => $recentInteractions,
        ];
    }

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * GET /api/crm/dashboard-stats  (JSON)
     */
    public function index(): JsonResponse
    {
        return response()->json($this->buildDashboardData($this->shopOwnerId()));
    }

    /**
     * GET /crm/  (Inertia page)
     *
     * Passes pre-loaded stats so the dashboard renders without an extra
     * round-trip.  Splits the flat data blob into the three prop shapes
     * the CRMDashboard component expects.
     */
    public function indexPage()
    {
        $user = Auth::guard('user')->user();

        if ($user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $data = $this->buildDashboardData($this->shopOwnerId());

        return Inertia::render('ERP/CRM/CRMDashboard', [
            'initialStats' => [
                'activeCustomers'   => $data['active_customers'],
                'openConversations' => $data['open_conversations'],
                'pendingReviews'    => $data['pending_reviews'],
                'averageRating'     => $data['average_rating'],
            ],
            'initialEngagement'   => $data['engagement_by_channel'],
            'initialInteractions' => $data['recent_interactions'],
        ]);
    }
}
