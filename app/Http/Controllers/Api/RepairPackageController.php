<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RepairPackageController extends Controller
{
    public function publicIndex(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_id' => 'nullable|integer|exists:shop_owners,id',
            'search' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = RepairPackage::query()
            ->active()
            ->with('services:id,name,category,price,duration,status,shop_owner_id');

        if ($request->filled('shop_id')) {
            $query->where('shop_owner_id', (int) $request->shop_id);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $packages = $query->orderByDesc('created_at')->get()->map(function (RepairPackage $package) {
            $serviceTotal = $package->services->sum(fn ($service) => (float) $service->price);

            return [
                'id' => $package->id,
                'shop_owner_id' => $package->shop_owner_id,
                'name' => $package->name,
                'description' => $package->description,
                'package_price' => (float) $package->package_price,
                'status' => $package->status,
                'starts_at' => optional($package->starts_at)?->toIso8601String(),
                'ends_at' => optional($package->ends_at)?->toIso8601String(),
                'service_count' => $package->services->count(),
                'services_total_price' => round($serviceTotal, 2),
                'savings_amount' => round(max($serviceTotal - (float) $package->package_price, 0), 2),
                'services' => $package->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'price' => (float) $service->price,
                    'duration' => $service->duration,
                    'status' => $service->status,
                ])->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function index(Request $request)
    {
        $query = RepairPackage::query()->with('services:id,name,category,price,duration,status,shop_owner_id');

        if ($shopOwnerId = $this->resolveShopOwnerId()) {
            $query->where('shop_owner_id', $shopOwnerId);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $packages = $query->orderByDesc('created_at')->get()->map(function (RepairPackage $package) {
            $serviceTotal = $package->services->sum(fn ($service) => (float) $service->price);

            return [
                'id' => $package->id,
                'shop_owner_id' => $package->shop_owner_id,
                'name' => $package->name,
                'description' => $package->description,
                'package_price' => (float) $package->package_price,
                'status' => $package->status,
                'starts_at' => optional($package->starts_at)?->toIso8601String(),
                'ends_at' => optional($package->ends_at)?->toIso8601String(),
                'service_count' => $package->services->count(),
                'services_total_price' => round($serviceTotal, 2),
                'savings_amount' => round(max($serviceTotal - (float) $package->package_price, 0), 2),
                'services' => $package->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'price' => (float) $service->price,
                    'duration' => $service->duration,
                    'status' => $service->status,
                ])->values(),
                'created_at' => optional($package->created_at)?->toIso8601String(),
                'updated_at' => optional($package->updated_at)?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function analytics(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context.',
            ], 403);
        }

        $packages = RepairPackage::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->with('services:id,name,category,price,duration,status,shop_owner_id')
            ->orderByDesc('created_at')
            ->get();

        $packageIds = $packages->pluck('id')->all();

        $packageRequests = empty($packageIds)
            ? collect()
            : RepairRequest::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('repair_package_id', $packageIds)
                ->orderByDesc('created_at')
                ->get([
                    'id',
                    'request_id',
                    'repair_package_id',
                    'package_price',
                    'add_ons_total',
                    'final_total',
                    'total',
                    'status',
                    'created_at',
                    'pricing_breakdown',
                ]);

        $bookingsCount = $packageRequests->count();
        $packageRevenue = round($packageRequests->sum(fn ($repairRequest) => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0)), 2);
        $packageBaseRevenue = round($packageRequests->sum(fn ($repairRequest) => (float) ($repairRequest->package_price ?? 0)), 2);
        $addOnRevenue = round($packageRequests->sum(fn ($repairRequest) => (float) ($repairRequest->add_ons_total ?? 0)), 2);
        $cutoff = now()->subDays(30);
        $recentRequests = $packageRequests->filter(fn ($repairRequest) => $repairRequest->created_at && $repairRequest->created_at->gte($cutoff));
        $packageLookup = $packages->keyBy('id');

        $topPackages = $packages
            ->map(function (RepairPackage $package) use ($packageRequests) {
                $bookings = $packageRequests->where('repair_package_id', $package->id)->values();
                $serviceTotal = (float) $package->services->sum(fn ($service) => (float) $service->price);

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'status' => $package->status,
                    'booking_count' => $bookings->count(),
                    'revenue' => round($bookings->sum(fn ($repairRequest) => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0)), 2),
                    'add_on_revenue' => round($bookings->sum(fn ($repairRequest) => (float) ($repairRequest->add_ons_total ?? 0)), 2),
                    'average_order_value' => $bookings->count() > 0
                        ? round($bookings->sum(fn ($repairRequest) => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0)) / $bookings->count(), 2)
                        : 0,
                    'services_total_price' => round($serviceTotal, 2),
                    'package_price' => (float) $package->package_price,
                    'savings_amount' => round(max($serviceTotal - (float) $package->package_price, 0), 2),
                    'last_booked_at' => optional($bookings->max('created_at'))?->toIso8601String(),
                ];
            })
            ->sortByDesc('booking_count')
            ->values();

        $monthlyTrend = collect(range(5, 0))
            ->map(function (int $monthsAgo) use ($packageRequests) {
                $monthDate = now()->copy()->subMonths($monthsAgo);
                $bookings = $packageRequests->filter(function ($repairRequest) use ($monthDate) {
                    return $repairRequest->created_at
                        && $repairRequest->created_at->year === $monthDate->year
                        && $repairRequest->created_at->month === $monthDate->month;
                });

                return [
                    'month' => $monthDate->format('M Y'),
                    'bookings' => $bookings->count(),
                    'revenue' => round($bookings->sum(fn ($repairRequest) => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0)), 2),
                ];
            })
            ->values();

        $recentBookings = $packageRequests
            ->take(5)
            ->map(function ($repairRequest) use ($packageLookup) {
                $package = $packageLookup->get($repairRequest->repair_package_id);

                return [
                    'repair_request_id' => $repairRequest->id,
                    'order_number' => $repairRequest->request_id,
                    'package_id' => $repairRequest->repair_package_id,
                    'package_name' => $repairRequest->pricing_breakdown['package_name'] ?? $package?->name,
                    'booked_at' => optional($repairRequest->created_at)?->toIso8601String(),
                    'final_total' => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0),
                    'add_ons_total' => (float) ($repairRequest->add_ons_total ?? 0),
                    'status' => $repairRequest->status,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => [
                    'total_packages' => $packages->count(),
                    'active_packages' => $packages->where('status', 'active')->count(),
                    'inactive_packages' => $packages->where('status', 'inactive')->count(),
                    'total_bookings' => $bookingsCount,
                    'package_revenue' => $packageRevenue,
                    'package_base_revenue' => $packageBaseRevenue,
                    'add_on_revenue' => $addOnRevenue,
                    'average_order_value' => $bookingsCount > 0 ? round($packageRevenue / $bookingsCount, 2) : 0,
                    'add_on_attach_rate' => $bookingsCount > 0
                        ? round(($packageRequests->filter(fn ($repairRequest) => (float) ($repairRequest->add_ons_total ?? 0) > 0)->count() / $bookingsCount) * 100, 1)
                        : 0,
                    'bookings_last_30_days' => $recentRequests->count(),
                    'revenue_last_30_days' => round($recentRequests->sum(fn ($repairRequest) => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0)), 2),
                ],
                'top_packages' => $topPackages,
                'status_breakdown' => $packageRequests
                    ->groupBy('status')
                    ->map(fn ($items, $status) => ['status' => $status, 'count' => count($items)])
                    ->values(),
                'monthly_trend' => $monthlyTrend,
                'recent_bookings' => $recentBookings,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'package_price' => 'required|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'service_ids' => 'required|array|min:2',
            'service_ids.*' => 'integer|exists:repair_services,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $actorId = Auth::guard('user')->id();

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwnerId,
            'name' => $request->name,
            'description' => $request->description,
            'package_price' => $request->package_price,
            'status' => $request->status ?? 'active',
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        try {
            $package->syncIncludedServices((array) $request->service_ids);
        } catch (ValidationException $e) {
            $package->delete();

            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Repair package created successfully.',
            'data' => $package->load('services:id,name,category,price,duration,status,shop_owner_id'),
        ], 201);
    }

    public function show(int $id)
    {
        $package = RepairPackage::with('services:id,name,category,price,duration,status,shop_owner_id')->find($id);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Repair package not found.',
            ], 404);
        }

        if (!$this->canAccessPackage($package)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to access this package.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $package,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $package = RepairPackage::find($id);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Repair package not found.',
            ], 404);
        }

        if (!$this->canAccessPackage($package)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to update this package.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'package_price' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|in:active,inactive',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'service_ids' => 'sometimes|required|array|min:2',
            'service_ids.*' => 'integer|exists:repair_services,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only(['name', 'description', 'package_price', 'status', 'starts_at', 'ends_at']);
        if (Auth::guard('user')->check()) {
            $updateData['updated_by'] = Auth::guard('user')->id();
        }

        $package->update($updateData);

        if ($request->has('service_ids')) {
            try {
                $package->syncIncludedServices((array) $request->service_ids);
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Repair package updated successfully.',
            'data' => $package->load('services:id,name,category,price,duration,status,shop_owner_id'),
        ]);
    }

    public function destroy(int $id)
    {
        $package = RepairPackage::find($id);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Repair package not found.',
            ], 404);
        }

        if (!$this->canAccessPackage($package)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to delete this package.',
            ], 403);
        }

        $package->delete();

        return response()->json([
            'success' => true,
            'message' => 'Repair package deleted successfully.',
        ]);
    }

    private function resolveShopOwnerId(): ?int
    {
        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        if (Auth::guard('user')->check()) {
            $shopOwnerId = Auth::guard('user')->user()?->shop_owner_id;
            return $shopOwnerId ? (int) $shopOwnerId : null;
        }

        return null;
    }

    private function canAccessPackage(RepairPackage $package): bool
    {
        $shopOwnerId = $this->resolveShopOwnerId();
        return $shopOwnerId !== null && $package->shop_owner_id === $shopOwnerId;
    }
}
