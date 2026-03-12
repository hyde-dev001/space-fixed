<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopReport;
use App\Models\Order;
use App\Models\RepairRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportShopController extends Controller
{
    /**
     * Submit a report against a shop.
     *
     * Anti-abuse rules enforced:
     *  1. User must be authenticated.
     *  2. User account must be at least 7 days old.
     *  3. User must have at least one completed order OR completed repair with this shop.
     *  4. User may only report a shop once (unique constraint).
     */
    public function store(Request $request, int $shopId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Rule 2: Account must be at least 7 days old
        if ($user->created_at->diffInDays(now()) < 7) {
            return response()->json([
                'message' => 'Your account must be at least 7 days old to report a shop.',
            ], 422);
        }

        // Rule 4: Check for existing report (before heavy queries)
        $alreadyReported = ShopReport::where('user_id', $user->id)
            ->where('shop_owner_id', $shopId)
            ->exists();

        if ($alreadyReported) {
            return response()->json([
                'message' => 'You have already submitted a report for this shop.',
            ], 422);
        }

        // Rule 3: Must have a verified transaction with the shop
        // Orders: delivered or shipped (cancelled orders don't count)
        $completedOrder = Order::where('shop_owner_id', $shopId)
            ->where('customer_id', $user->id)
            ->whereNotIn('status', ['cancelled', 'pending'])
            ->first();

        // Repairs: any active or completed repair (scam can happen at any stage)
        $completedRepair = null;
        if (!$completedOrder) {
            $completedRepair = RepairRequest::where('shop_owner_id', $shopId)
                ->where('user_id', $user->id)
                ->whereNotIn('status', ['new_request', 'pending'])
                ->first();
        }

        if (!$completedOrder && !$completedRepair) {
            return response()->json([
                'message' => 'You can only report a shop after completing a transaction with them.',
            ], 422);
        }

        // Validate inputs
        $validated = $request->validate([
            'reason' => ['required', 'in:fraud,fake_products,harassment,no_show,misconduct,other'],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        // Determine transaction proof
        $transactionType = $completedOrder ? 'order' : 'repair';
        $transactionId   = $completedOrder ? $completedOrder->id : $completedRepair->id;

        $report = ShopReport::create([
            'user_id'          => $user->id,
            'shop_owner_id'    => $shopId,
            'reason'           => $validated['reason'],
            'description'      => $validated['description'],
            'transaction_type' => $transactionType,
            'transaction_id'   => $transactionId,
            'status'           => 'submitted',
            'ip_address'       => $request->ip(),
        ]);

        // Resolve shop name for the notification message
        $shop = \App\Models\ShopOwner::find($shopId);
        $shopName = $shop?->business_name ?? "Shop #{$shopId}";
        $reasonLabel = ucwords(str_replace('_', ' ', $validated['reason']));

        \App\Models\Notification::notifyAllSuperAdmins(
            \App\Enums\NotificationType::SHOP_REPORT_FILED,
            'Shop Report Filed',
            "{$user->first_name} {$user->last_name} filed a report against {$shopName}. Reason: {$reasonLabel}.",
            '/admin/shop-reports',
            ['report_id' => $report->id, 'shop_owner_id' => $shopId, 'reason' => $validated['reason']]
        );

        return response()->json([
            'message' => 'Your report has been submitted and will be reviewed by our team.',
        ], 201);
    }
}
