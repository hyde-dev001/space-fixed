<?php

namespace App\Http\Controllers\API\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerNote;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class CRMCustomerController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function shopOwnerId(): int
    {
        $user = Auth::user();
        return $user->shop_owner_id ?? $user->id;
    }

    /**
     * Verify the given user ID is a customer of this shop.
     * Returns true when they have at least one order or repair request.
     */
    private function customerBelongsToShop(int $customerId, int $shopOwnerId): bool
    {
        return Order::where('shop_owner_id', $shopOwnerId)->where('customer_id', $customerId)->exists()
            || RepairRequest::where('shop_owner_id', $shopOwnerId)->where('user_id', $customerId)->exists();
    }

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * GET /crm/customers  (Inertia page)
     *
     * Server-side renders the customer list with basic per-customer stats.
     * Full detail (order / repair / note history) is lazy-loaded via the
     * show() JSON endpoint when the user opens a customer record.
     */
    public function indexPage(Request $request)
    {
        $user = Auth::guard('user')->user();

        if ($user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // ── Per-customer order stats ──────────────────────────────────────────
        $orderStats = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('customer_id')
            ->select(
                'customer_id as user_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total_spent'),
                DB::raw('MAX(created_at) as last_order_at')
            )
            ->groupBy('customer_id')
            ->get()
            ->keyBy('user_id');

        // ── Per-customer repair stats ─────────────────────────────────────────
        $repairStats = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('user_id')
            ->select(
                'user_id',
                DB::raw('COUNT(*) as repair_count'),
                DB::raw('MAX(created_at) as last_repair_at')
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $customerIds = collect($orderStats->keys())
            ->merge($repairStats->keys())
            ->unique()
            ->values();

        if ($customerIds->isEmpty()) {
            return Inertia::render('ERP/CRM/Customers', [
                'initialCustomers' => [],
            ]);
        }

        // ── Notes count per customer (single query, no N+1) ───────────────────
        $notesCounts = CustomerNote::where('shop_owner_id', $shopOwnerId)
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, COUNT(*) as cnt')
            ->groupBy('customer_id')
            ->pluck('cnt', 'customer_id');

        $customers = User::whereIn('id', $customerIds)
            ->orderBy('name')
            ->get()
            ->map(function (User $customer) use ($orderStats, $repairStats, $notesCounts) {
                $orders  = $orderStats->get($customer->id);
                $repairs = $repairStats->get($customer->id);

                $lastOrderAt  = $orders?->last_order_at   ? Carbon::parse($orders->last_order_at)   : null;
                $lastRepairAt = $repairs?->last_repair_at ? Carbon::parse($repairs->last_repair_at) : null;

                $lastActivity = match (true) {
                    $lastOrderAt && $lastRepairAt => $lastOrderAt->max($lastRepairAt),
                    default                       => $lastOrderAt ?? $lastRepairAt,
                };

                return [
                    'id'           => $customer->id,
                    'name'         => $customer->name  ?? 'Unknown',
                    'email'        => $customer->email ?? '',
                    'phone'        => $customer->phone ?? '',
                    'address'      => $customer->address ?? '',
                    'status'       => ($lastActivity && $lastActivity->greaterThan(now()->subDays(90)))
                                        ? 'active' : 'inactive',
                    'totalOrders'  => (int)   ($orders?->order_count  ?? 0),
                    'totalRepairs' => (int)   ($repairs?->repair_count ?? 0),
                    'totalSpent'   => (float) ($orders?->total_spent   ?? 0),
                    'lastActivity' => $lastActivity?->toDateString(),
                    'memberSince'  => Carbon::parse($customer->created_at)->toDateString(),
                    'notesCount'   => (int) ($notesCounts->get($customer->id) ?? 0),
                ];
            });

        return Inertia::render('ERP/CRM/Customers', [
            'initialCustomers' => $customers,
        ]);
    }

    /**
     * GET /api/crm/customers
     *
     * Paginated customer list.  Each entry includes aggregate stats:
     * total_orders, total_repairs, total_spent.
     */
    public function index(Request $request): JsonResponse
    {
        $shopOwnerId = $this->shopOwnerId();

        // Collect distinct customer IDs who have transacted with this shop.
        $orderCustomerIds  = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id');

        $repairCustomerIds = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $customerIds = $orderCustomerIds->merge($repairCustomerIds)->unique()->values();

        if ($customerIds->isEmpty()) {
            return response()->json([
                'data'    => [],
                'total'   => 0,
                'message' => 'No customers found',
            ]);
        }

        $query = User::whereIn('id', $customerIds);

        // Full-text search by name / email / phone.
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Status filter (active / inactive / suspended).
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $customers = $query->orderBy('name')->paginate($request->get('per_page', 20));

        // Attach per-customer aggregate stats.
        $customers->getCollection()->transform(function (User $customer) use ($shopOwnerId) {
            $customer->total_orders  = Order::where('shop_owner_id', $shopOwnerId)
                ->where('customer_id', $customer->id)->count();

            $customer->total_repairs = RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->where('user_id', $customer->id)->count();

            $customer->total_spent   = (float) Order::where('shop_owner_id', $shopOwnerId)
                ->where('customer_id', $customer->id)
                ->whereNotNull('total_amount')
                ->sum('total_amount');

            return $customer;
        });

        return response()->json($customers);
    }

    /**
     * GET /api/crm/customers/{id}
     *
     * Full customer detail: profile, order history, repair history,
     * staff notes, and summary stats.
     */
    public function show(int $id): JsonResponse
    {
        $shopOwnerId = $this->shopOwnerId();

        if (!$this->customerBelongsToShop($id, $shopOwnerId)) {
            return response()->json(['error' => 'Customer not found for this shop'], 404);
        }

        $customer = User::findOrFail($id);

        $orders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('customer_id', $id)
            ->with('items')
            ->latest()
            ->get();

        $repairs = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('user_id', $id)
            ->latest()
            ->get();

        $notes = CustomerNote::where('shop_owner_id', $shopOwnerId)
            ->where('customer_id', $id)
            ->latest()
            ->get();

        return response()->json([
            'customer' => $customer,
            'orders'   => $orders,
            'repairs'  => $repairs,
            'notes'    => $notes,
            'stats'    => [
                'total_orders'  => $orders->count(),
                'total_repairs' => $repairs->count(),
                'total_spent'   => (float) $orders->sum('total_amount'),
                'member_since'  => $customer->created_at?->toDateString(),
            ],
        ]);
    }

    /**
     * PUT /api/crm/customers/{id}
     *
     * Update editable customer fields: name, phone, address, status.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $shopOwnerId = $this->shopOwnerId();

        if (!$this->customerBelongsToShop($id, $shopOwnerId)) {
            return response()->json(['error' => 'Customer not found for this shop'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|string|max:255',
            'phone'   => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:500',
            'status'  => 'sometimes|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $customer = User::findOrFail($id);
        $customer->update($request->only(['name', 'phone', 'address', 'status']));

        return response()->json([
            'message'  => 'Customer updated successfully',
            'customer' => $customer->fresh(),
        ]);
    }

    /**
     * POST /api/crm/customers/{id}/notes
     *
     * Attach a staff note to a customer record.
     * The author defaults to the authenticated user's name.
     */
    public function storeNote(Request $request, int $id): JsonResponse
    {
        $shopOwnerId = $this->shopOwnerId();

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
            'author'  => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user   = Auth::user();
        $author = $request->input('author', $user->name ?? 'Staff');

        $note = CustomerNote::create([
            'customer_id'   => $id,
            'shop_owner_id' => $shopOwnerId,
            'author'        => $author,
            'content'       => $request->content,
        ]);

        return response()->json([
            'message' => 'Note added successfully',
            'note'    => $note,
        ], 201);
    }
}
