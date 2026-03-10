<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    private function shopOwner()
    {
        return Auth::guard('shop_owner')->user();
    }

    /**
     * GET /api/shop-owner/customers
     * Returns customers who have placed orders or repair requests for this shop.
     */
    public function index(Request $request): JsonResponse
    {
        $shopOwner = $this->shopOwner();
        $shopId    = $shopOwner->id;

        $search = trim($request->input('search', ''));
        $status = $request->input('status', 'all'); // all | active | inactive

        // ── Collect unique user_ids from orders ────────────────────────────
        $orderCustomerIds = Order::where('shop_owner_id', $shopId)
            ->whereNotNull('customer_id')
            ->pluck('customer_id')
            ->unique();

        // ── Collect unique user_ids from repair requests ───────────────────
        $repairCustomerIds = RepairRequest::where('shop_owner_id', $shopId)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        $allCustomerIds = $orderCustomerIds->merge($repairCustomerIds)->unique()->values();

        if ($allCustomerIds->isEmpty()) {
            return response()->json([
                'customers' => [],
                'stats'     => [
                    'totalCustomers'   => 0,
                    'activeCustomers'  => 0,
                    'totalOrders'      => 0,
                    'totalRepairs'     => 0,
                ],
            ]);
        }

        // ── Aggregate orders per customer ──────────────────────────────────
        $orderStats = Order::where('shop_owner_id', $shopId)
            ->whereIn('customer_id', $allCustomerIds)
            ->select(
                'customer_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(total_amount), 0) as order_spent'),
                DB::raw('MAX(created_at) as last_order_at')
            )
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        // ── Aggregate repairs per customer ─────────────────────────────────
        $repairStats = RepairRequest::where('shop_owner_id', $shopId)
            ->whereIn('user_id', $allCustomerIds)
            ->select(
                'user_id',
                DB::raw('COUNT(*) as repair_count'),
                DB::raw('COALESCE(SUM(total), 0) as repair_spent'),
                DB::raw('MAX(created_at) as last_repair_at')
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $activeThreshold = Carbon::now()->subDays(30);

        // ── Build customer list ────────────────────────────────────────────
        $customers = User::whereIn('id', $allCustomerIds)
            ->get()
            ->map(function (User $user) use ($orderStats, $repairStats, $activeThreshold) {
                $os = $orderStats->get($user->id);
                $rs = $repairStats->get($user->id);

                $orderCount  = (int)   ($os?->order_count  ?? 0);
                $orderSpent  = (float) ($os?->order_spent  ?? 0);
                $repairCount = (int)   ($rs?->repair_count ?? 0);
                $repairSpent = (float) ($rs?->repair_spent ?? 0);

                $lastOrderAt  = $os ? Carbon::parse($os->last_order_at)  : null;
                $lastRepairAt = $rs ? Carbon::parse($rs->last_repair_at) : null;

                $lastActivity = null;
                if ($lastOrderAt && $lastRepairAt) {
                    $lastActivity = $lastOrderAt->gt($lastRepairAt) ? $lastOrderAt : $lastRepairAt;
                } else {
                    $lastActivity = $lastOrderAt ?? $lastRepairAt;
                }

                $isActive = $lastActivity && $lastActivity->gte($activeThreshold);

                return [
                    'id'           => $user->id,
                    'name'         => $user->name ?? trim("{$user->first_name} {$user->last_name}"),
                    'email'        => $user->email,
                    'phone'        => $user->phone ?? '',
                    'address'      => $user->address ?? '',
                    'city'         => '',
                    'status'       => $isActive ? 'active' : 'inactive',
                    'joinedAt'     => $user->created_at->toDateString(),
                    'lastActivity' => $lastActivity ? $lastActivity->toDateString() : $user->created_at->toDateString(),
                    'totalOrders'  => $orderCount,
                    'totalRepairs' => $repairCount,
                    'totalSpent'   => round($orderSpent + $repairSpent, 2),
                ];
            });

        // ── Apply search filter ────────────────────────────────────────────
        if ($search !== '') {
            $lower = strtolower($search);
            $customers = $customers->filter(function ($c) use ($lower) {
                return str_contains(strtolower($c['name']), $lower)
                    || str_contains(strtolower($c['email']), $lower)
                    || str_contains(strtolower($c['phone']), $lower);
            });
        }

        // ── Apply status filter ────────────────────────────────────────────
        if ($status !== 'all') {
            $customers = $customers->filter(fn ($c) => $c['status'] === $status);
        }

        $sorted = $customers->sortByDesc('lastActivity')->values();

        $totalCustomers  = $sorted->count();
        $activeCustomers = $sorted->where('status', 'active')->count();
        $totalOrders     = $sorted->sum('totalOrders');
        $totalRepairs    = $sorted->sum('totalRepairs');

        return response()->json([
            'customers' => $sorted,
            'stats'     => [
                'totalCustomers'  => $totalCustomers,
                'activeCustomers' => $activeCustomers,
                'totalOrders'     => $totalOrders,
                'totalRepairs'    => $totalRepairs,
            ],
        ]);
    }

    /**
     * GET /api/shop-owner/customers/{id}/orders
     * Purchase history for a specific customer in this shop.
     */
    public function orders(int $id): JsonResponse
    {
        $shopId = $this->shopOwner()->id;

        $orders = Order::where('shop_owner_id', $shopId)
            ->where('customer_id', $id)
            ->with('items.product')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($o) => [
                'id'          => $o->id,
                'orderNumber' => $o->order_number,
                'itemSummary' => $o->items->map(fn ($i) => "{$i->product_name} x{$i->quantity}")->implode(', ') ?: '—',
                'date'        => $o->created_at->toDateString(),
                'amount'      => (float) $o->total_amount,
                'status'      => in_array($o->status, ['delivered']) ? 'completed'
                    : (in_array($o->status, ['cancelled']) ? 'cancelled' : 'processing'),
            ]);

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /api/shop-owner/customers/{id}/repairs
     * Repair history for a specific customer in this shop.
     */
    public function repairs(int $id): JsonResponse
    {
        $shopId = $this->shopOwner()->id;

        $repairs = RepairRequest::where('shop_owner_id', $shopId)
            ->where('user_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id'            => $r->id,
                'requestNumber' => $r->request_id,
                'service'       => $r->shoe_type ?? 'Repair Service',
                'date'          => $r->created_at->toDateString(),
                'cost'          => (float) $r->total,
                'status'        => in_array($r->status, ['completed', 'picked_up']) ? 'done'
                    : (in_array($r->status, ['in_progress', 'assigned_to_repairer', 'repairer_accepted']) ? 'in_progress' : 'queued'),
            ]);

        return response()->json(['repairs' => $repairs]);
    }
}
