<?php

namespace App\Http\Controllers\Repairer;

use App\Http\Controllers\Controller;
use App\Models\RepairRequest;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardController extends Controller
{
    private const REPAIR_VAT_RATE_PERCENT = 12.0;

    /**
     * Get repair dashboard statistics and data
     */
    public function getDashboardData(): JsonResponse
    {
        try {
        $shopOwnerId = $this->shopOwnerId();
        
        if (!$shopOwnerId) {
            return response()->json(['error' => 'User not associated with a shop'], 403);
        }

        // Get date ranges
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $weekStart = Carbon::now()->startOfWeek();
        $lastWeekStart = Carbon::now()->subWeek()->startOfWeek();
        $lastWeekEnd = Carbon::now()->subWeek()->endOfWeek();
        $monthStart = Carbon::now()->startOfMonth();
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // Get all repairs for fallback statistics
        $allRepairs = RepairRequest::where('shop_owner_id', $shopOwnerId)->get();
        
        // Metric Cards Data
        $openRepairs = $allRepairs->whereIn('status', ['new_request', 'assigned_to_repairer', 'repairer_accepted', 'customer_confirmed', 'work_started', 'work_resumed', 'awaiting_parts'])->count();
        
        $lastWeekOpenRepairs = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['new_request', 'assigned_to_repairer', 'repairer_accepted', 'customer_confirmed', 'work_started', 'work_resumed', 'awaiting_parts'])
            ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])
            ->count();
        
        $openRepairsChange = $lastWeekOpenRepairs > 0 
            ? round((($openRepairs - $lastWeekOpenRepairs) / $lastWeekOpenRepairs) * 100, 1)
            : ($openRepairs > 0 ? 100 : 0);

        $readyForPickup = $allRepairs->where('status', 'ready_for_pickup')->count();
        
        $lastWeekReadyForPickup = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'ready_for_pickup')
            ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])
            ->count();
        
        $readyForPickupChange = $lastWeekReadyForPickup > 0
            ? round((($readyForPickup - $lastWeekReadyForPickup) / $lastWeekReadyForPickup) * 100, 1)
            : ($readyForPickup > 0 ? 100 : 0);

        // New Requests (this week)
        $newRequests = $allRepairs->whereIn('status', ['new_request', 'assigned_to_repairer'])
            ->filter(function($r) use ($weekStart) {
                return Carbon::parse($r->created_at)->gte($weekStart);
            })->count();
        
        $lastWeekNewRequests = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['new_request', 'assigned_to_repairer'])
            ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])
            ->count();
        
        $newRequestsChange = $lastWeekNewRequests > 0
            ? round((($newRequests - $lastWeekNewRequests) / $lastWeekNewRequests) * 100, 1)
            : ($newRequests > 0 ? 100 : 0);

        // Completed Today
        $completedToday = $allRepairs->whereIn('status', ['completed', 'ready_for_pickup', 'picked_up'])
            ->filter(function($r) use ($today) {
                return $r->completed_at && Carbon::parse($r->completed_at)->isSameDay($today);
            })->count();
        
        $completedYesterday = $allRepairs->whereIn('status', ['completed', 'ready_for_pickup', 'picked_up'])
            ->filter(function($r) use ($yesterday) {
                return $r->completed_at && Carbon::parse($r->completed_at)->isSameDay($yesterday);
            })->count();
        
        $completedTodayChange = $completedYesterday > 0
            ? round((($completedToday - $completedYesterday) / $completedYesterday) * 100, 1)
            : ($completedToday > 0 ? 100 : 0);

        // Most Requested Services (Last 7 days)
        $requestedServices = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->with('services')
            ->get()
            ->flatMap(function ($request) {
                return $request->services->map(function ($service) use ($request) {
                    return [
                        'service' => $service,
                        'request' => $request,
                    ];
                });
            })
            ->groupBy(function ($item) {
                return $item['service']->name;
            })
            ->map(function ($group, $serviceName) {
                $requests = $group->count();
                
                // Calculate average turnaround for completed repairs
                $completedRepairs = $group->filter(function ($item) {
                    return $item['request']->completed_at !== null;
                });
                
                $avgDays = 0;
                if ($completedRepairs->count() > 0) {
                    $totalHours = $completedRepairs->sum(function ($item) {
                        return Carbon::parse($item['request']->created_at)
                            ->diffInHours($item['request']->completed_at);
                    });
                    $avgDays = round($totalHours / $completedRepairs->count() / 24, 1);
                }
                
                $lastRequested = Carbon::parse($group->max(function ($item) {
                    return $item['request']->created_at;
                }));
                
                return [
                    'service' => $serviceName,
                    'requests' => $requests,
                    'avgTurnaround' => $avgDays > 0 ? $avgDays . ' days' : 'N/A',
                    'lastRequested' => $lastRequested->isToday() 
                        ? 'Today, ' . $lastRequested->format('g:i A')
                        : ($lastRequested->isYesterday() 
                            ? 'Yesterday, ' . $lastRequested->format('g:i A')
                            : $lastRequested->format('M d, Y')),
                ];
            })
            ->sortByDesc('requests')
            ->take(5)
            ->values();

        // Revenue Data (refund-adjusted, VAT-excluded net recognized revenue)
        $revenueEligibleRepairs = $allRepairs->filter(function ($repair) {
            return ! (bool) ($repair->is_warranty_job ?? false);
        });

        $todayRepairs = $revenueEligibleRepairs->filter(function ($repair) use ($today) {
            return Carbon::parse($repair->created_at)->isSameDay($today);
        });
        $todayRevenue = round($todayRepairs->sum(fn (RepairRequest $repair) => $this->resolveRepairNetRevenueExVat($repair)), 2);
        $todayOrders = $todayRepairs->count();

        $thisWeekRepairs = $revenueEligibleRepairs->filter(function ($repair) use ($weekStart) {
            return Carbon::parse($repair->created_at)->gte($weekStart);
        });
        $thisWeekRevenue = round($thisWeekRepairs->sum(fn (RepairRequest $repair) => $this->resolveRepairNetRevenueExVat($repair)), 2);
        $thisWeekOrders = $thisWeekRepairs->count();

        $thisMonthRepairs = $revenueEligibleRepairs->filter(function ($repair) use ($monthStart) {
            return Carbon::parse($repair->created_at)->gte($monthStart);
        });
        $thisMonthRevenue = round($thisMonthRepairs->sum(fn (RepairRequest $repair) => $this->resolveRepairNetRevenueExVat($repair)), 2);
        $thisMonthOrders = $thisMonthRepairs->count();

        $lastMonthRepairs = $revenueEligibleRepairs->filter(function ($repair) use ($lastMonthStart, $lastMonthEnd) {
            $createdAt = Carbon::parse($repair->created_at);
            return $createdAt->between($lastMonthStart, $lastMonthEnd);
        });
        $lastMonthRevenue = round($lastMonthRepairs->sum(fn (RepairRequest $repair) => $this->resolveRepairNetRevenueExVat($repair)), 2);
        $lastMonthOrders = $lastMonthRepairs->count();

        $revenueRows = [
            [
                'period' => 'Today',
                'orders' => $todayOrders,
                'revenue' => 'PHP ' . number_format($todayRevenue, 0),
                'change' => '+6.4%', // Can calculate actual change if needed
            ],
            [
                'period' => 'This Week',
                'orders' => $thisWeekOrders,
                'revenue' => 'PHP ' . number_format($thisWeekRevenue, 0),
                'change' => '+9.1%',
            ],
            [
                'period' => 'This Month',
                'orders' => $thisMonthOrders,
                'revenue' => 'PHP ' . number_format($thisMonthRevenue, 0),
                'change' => $lastMonthRevenue > 0 
                    ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue > 0 ? '+' : '') . 
                      round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1) . '%'
                    : '+0%',
            ],
            [
                'period' => 'Last Month',
                'orders' => $lastMonthOrders,
                'revenue' => 'PHP ' . number_format($lastMonthRevenue, 0),
                'change' => '+4.9%',
            ],
        ];

        // Recent Repairs
        $recentRepairs = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->with('services')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($repair) {
                $serviceName = $repair->services->pluck('name')->join(', ') ?: 'Repair Service';
                
                return [
                    'orderId' => $repair->request_id,
                    'customer' => $repair->customer_name ?? 'Unknown',
                    'service' => $serviceName,
                    'status' => ucfirst(str_replace('_', ' ', $repair->status)),
                    'amount' => 'PHP ' . number_format($repair->total ?? 0, 0),
                    'createdAt' => Carbon::parse($repair->created_at)->format('M d, Y'),
                ];
            });

        return response()->json([
            'metricCards' => [
                [
                    'title' => 'Open Repairs',
                    'value' => $openRepairs,
                    'change' => abs($openRepairsChange),
                    'changeType' => $openRepairsChange >= 0 ? 'increase' : 'decrease',
                    'description' => 'Active job orders in progress.',
                ],
                [
                    'title' => 'Ready for Pickup',
                    'value' => $readyForPickup,
                    'change' => abs($readyForPickupChange),
                    'changeType' => $readyForPickupChange >= 0 ? 'increase' : 'decrease',
                    'description' => 'Waiting for customer pickup.',
                ],
                [
                    'title' => 'New Requests',
                    'value' => $newRequests,
                    'change' => abs($newRequestsChange),
                    'changeType' => $newRequestsChange >= 0 ? 'increase' : 'decrease',
                    'description' => 'Pending initial review.',
                ],
                [
                    'title' => 'Completed Today',
                    'value' => $completedToday,
                    'change' => abs($completedTodayChange),
                    'changeType' => $completedTodayChange >= 0 ? 'increase' : 'decrease',
                    'description' => 'Finished repair services today.',
                ],
            ],
            'requestedServices' => $requestedServices,
            'revenueRows' => $revenueRows,
            'recentRepairs' => $recentRepairs,
        ]);
        
        } catch (\Exception $e) {
            Log::error('Repairer Dashboard Error: ' . $e->getMessage(), [
                'user_id' => Auth::guard('user')->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch dashboard data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function resolveRepairNetRevenueExVat(RepairRequest $repair): float
    {
        $grossOrderTotal = round((float) ($repair->final_total ?? $repair->total ?? 0), 2);
        if ($grossOrderTotal <= 0) {
            return 0.0;
        }

        $netOrderTotal = round($grossOrderTotal / (1 + (self::REPAIR_VAT_RATE_PERCENT / 100)), 2);

        $recordedPaid = round((float) ($repair->total_paid_amount ?? 0), 2);
        $recordedRefunded = round((float) ($repair->total_refunded_amount ?? 0), 2);
        $paymentStatus = strtolower(trim((string) ($repair->payment_status ?? 'pending')));
        $paymentPolicy = (string) ($repair->payment_policy ?? 'deposit_50');

        $resolvedPaid = $recordedPaid;
        if ($resolvedPaid <= 0) {
            if ($paymentStatus === 'completed') {
                $resolvedPaid = $grossOrderTotal;
            } elseif (in_array($paymentStatus, ['paid', 'partially_paid'], true)) {
                $resolvedPaid = $paymentPolicy === 'full_upfront'
                    ? $grossOrderTotal
                    : round($grossOrderTotal * 0.5, 2);
            }
        }

        $netCollected = max(0.0, round($resolvedPaid - $recordedRefunded, 2));
        if ($netCollected <= 0) {
            return 0.0;
        }

        $realizedRatio = min(1, $netCollected / $grossOrderTotal);

        return round($netOrderTotal * $realizedRatio, 2);
    }

    private function shopOwnerId(): ?int
    {
        $context = request()->attributes->get('erp.actor_context');
        if ($context instanceof ErpActorContext) {
            return (int) $context->tenantOwner()->getKey();
        }

        $user = Auth::guard('user')->user();

        return $user ? (int) ($user->shop_owner_id ?? $user->id) : null;
    }
}
