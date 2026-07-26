<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\RiderProfileSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ErpLogisticsController extends Controller
{
    public function dashboard(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('access-logistics-dashboard');

        return Inertia::render('ERP/Logistics/Dashboard', [
            'stats' => $this->stats($shopOwnerId),
        ]);
    }

    public function shipments(Request $request): Response|RedirectResponse
    {
        $user = Auth::guard('user')->user();
        if ($user?->can('operate-logistics-deliveries') && ! $user->can('assign-logistics-deliveries')) {
            return redirect()->route('erp.logistics.deliveries');
        }

        $shopOwnerId = $this->authorizedShopOwnerId('assign-logistics-deliveries');
        $isDispatcher = $user && (
            $user->can('assign-logistics-deliveries') ||
            $user->can('manage-logistics-riders')
        );
        $canAssign = $user && $user->can('assign-logistics-deliveries');
        $shop = ShopOwner::query()->findOrFail($shopOwnerId);
        [$module, $availableModules] = $this->logisticsModuleFilter($shop, (string) $request->query('module', 'all'));
        $status = in_array($request->query('status'), ['all', 'incomplete', 'requested', 'active', 'completed', 'cancelled', 'awaiting_proof_approval', 'failed_attempts'], true)
            ? $request->query('status') : 'all';
        $purpose = $request->query('purpose', 'all');
        $deliveryWindow = in_array($request->query('window'), ['morning', 'afternoon'], true)
            ? $request->query('window') : 'all';
        $maxDeliveryAttempts = (int) LogisticsSetting::firstOrCreate(['shop_owner_id' => $shopOwnerId])->max_delivery_attempts;

        return Inertia::render('ERP/Logistics/Shipments', [
            'shipments' => tap(Shipment::query()
                ->with(['legs' => function ($query) use ($user, $isDispatcher) {
                    $query->with(['assignments.riderProfile', 'proofs', 'attempts' => fn ($attempts) => $attempts
                        ->where('attempt_type', 'delivery')
                        ->where('status', 'failed')
                        ->latest('attempted_at')
                        ->latest('id')
                        ->limit(1)]);
                    $query->withCount(['attempts as failed_attempt_count' => fn ($attempts) => $attempts
                        ->where('attempt_type', 'delivery')->where('status', 'failed')]);

                    if (! $isDispatcher) {
                        $query->whereHas('assignments', function ($assignments) use ($user) {
                            $assignments->whereIn('status', ['assigned', 'accepted'])
                                ->whereHas('riderProfile', function ($riders) use ($user) {
                                    $riders->where('linked_type', User::class)
                                        ->where('linked_id', $user->id);
                                });
                        });
                    }
                }])
                ->where('shop_owner_id', $shopOwnerId)
                ->when($module !== 'all', fn ($query) => $query
                    ->whereIn('source_type', Shipment::sourceTypesForModule($module)))
                ->when($deliveryWindow !== 'all', fn ($query) => $query
                    ->whereHas('legs', fn ($legs) => $legs->where('delivery_window', $deliveryWindow)))
                ->when($status === 'incomplete', function ($query) {
                    $query->whereNotIn('status', ['completed', 'cancelled']);
                })
                ->when(in_array($status, ['requested', 'active', 'completed', 'cancelled'], true), function ($query) use ($status) {
                    $query->where('status', $status);
                })
                ->when($status === 'awaiting_proof_approval', fn ($query) => $query
                    ->whereHas('legs', fn ($legs) => $legs->where('status', 'awaiting_proof_approval')))
                ->when($status === 'failed_attempts', fn ($query) => $query
                    ->whereHas('legs.attempts', fn ($attempts) => $attempts
                        ->where('attempt_type', 'delivery')->where('status', 'failed')))
                ->when($purpose !== 'all', function ($query) use ($purpose) {
                    $query->where('purpose', $purpose);
                })
                ->when(! $isDispatcher, function ($query) use ($user) {
                    $query->whereHas('legs.assignments', function ($assignments) use ($user) {
                        $assignments->whereIn('status', ['assigned', 'accepted'])
                            ->whereHas('riderProfile', function ($riders) use ($user) {
                                $riders->where('linked_type', User::class)
                                    ->where('linked_id', $user->id);
                            });
                    });
                })
                ->latest()
                ->paginate(10)
                ->withQueryString(), fn ($shipments) => $this->attachRepairSourceSummaries(
                    $shipments->getCollection(),
                    $shopOwnerId,
                )),
            'filters' => [
                'status' => $status,
                'purpose' => $purpose,
                'module' => $module,
                'window' => $deliveryWindow,
            ],
            'availableModules' => $availableModules,
            'showModuleFilter' => count($availableModules) > 1,
            'canAssign' => $canAssign,
            'canUpdateStatus' => false,
            'canRecordProof' => false,
            'canApproveProof' => $user && ($user->can('approve-proof-of-delivery') || $user->can('assign-logistics-deliveries')),
            'riderMode' => false,
            'maxDeliveryAttempts' => $maxDeliveryAttempts,
            'assignableRiders' => $canAssign
                ? RiderProfile::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('active', true)
                    ->where('availability_status', 'available')
                    ->orderBy('name')
                    ->get(['id', 'name', 'phone', 'rider_type', 'availability_status'])
                : [],
        ]);
    }

    public function deliveries(Request $request): Response
    {
        $user = Auth::guard('user')->user();
        if (! $user || ! $user->shop_owner_id || ! $user->can('operate-logistics-deliveries')) {
            abort(403);
        }
        $shopOwnerId = (int) $user->shop_owner_id;
        $status = in_array($request->query('status'), ['assigned', 'picked_up', 'in_transit', 'delivery_attempted', 'awaiting_proof_approval', 'delivered', 'cancelled'], true)
            ? $request->query('status')
            : 'all';
        $window = in_array($request->query('window'), ['today', 'week'], true) ? $request->query('window') : 'all';
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $databaseTimezone = config('database.connections.'.config('database.default').'.timezone', config('app.timezone', 'UTC'));
        $dates = match ($window) {
            'today' => [now($shopTimezone)->startOfDay(), now($shopTimezone)->endOfDay()],
            'week' => [now($shopTimezone)->startOfWeek(), now($shopTimezone)->endOfWeek()],
            default => null,
        };
        $assignmentMatches = function ($assignments) use ($user, $dates, $databaseTimezone) {
            $assignments->whereIn('status', ['assigned', 'accepted'])
                ->whereHas('riderProfile', fn ($riders) => $riders
                    ->where('linked_type', User::class)
                    ->where('linked_id', $user->id))
                ->when($dates, fn ($query) => $query->whereBetween('assigned_at', [
                    $dates[0]->setTimezone($databaseTimezone),
                    $dates[1]->setTimezone($databaseTimezone),
                ]));
        };
        $legMatches = function ($legs) use ($assignmentMatches, $status) {
            $legs->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->whereDoesntHave('deliveryBatch', fn ($query) => $query->where('status', 'offered'))
                ->whereHas('assignments', $assignmentMatches);
        };

        return Inertia::render('ERP/Logistics/MyDeliveries', [
            'shipments' => Shipment::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereHas('legs', $legMatches)
                ->with(['legs' => function ($query) use ($legMatches) {
                    $legMatches($query);
                    $query->with(['assignments.riderProfile', 'proofs', 'attempts' => fn ($attempts) => $attempts
                        ->where('attempt_type', 'delivery')
                        ->where('status', 'failed')
                        ->latest('attempted_at')
                        ->latest('id')
                        ->limit(1)]);
                }])
                ->latest()->paginate(10)->withQueryString(),
            'filters' => ['status' => $status, 'window' => $window],
            'canAssign' => false,
            'canUpdateStatus' => $user->can('update-logistics-status'),
            'canRecordProof' => $user->can('record-logistics-proof'),
            'canApproveProof' => false,
            'riderMode' => true,
            'assignableRiders' => [],
            'batches' => DeliveryBatch::query()->with(['legs.proofs', 'riderProfile'])
                ->where('shop_owner_id', $shopOwnerId)
                ->whereHas('riderProfile', fn ($query) => $query->where('linked_type', User::class)->where('linked_id', $user->id))
                ->whereIn('status', ['offered', 'accepted', 'in_progress'])->orderBy('delivery_date')->get(),
        ]);
    }

    public function riders(Request $request): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('manage-logistics-riders');
        $availability = $request->query('availability', 'all');
        $type = $request->query('type', 'all');
        app(RiderProfileSyncService::class)->syncShop($shopOwnerId);

        return Inertia::render('ERP/Logistics/Riders', [
            'riders' => RiderProfile::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->when(in_array($availability, ['available', 'busy', 'inactive'], true), function ($query) use ($availability) {
                    $query->where('availability_status', $availability);
                })
                ->when(in_array($type, ['employee', 'contractor', 'shop_owner'], true), function ($query) use ($type) {
                    $query->where('rider_type', $type);
                })
                ->orderBy('name')
                ->paginate(10)
                ->withQueryString(),
            'filters' => [
                'availability' => $availability,
                'type' => $type,
            ],
        ]);
    }

    public function settings(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('configure-logistics-settings');

        return Inertia::render('ERP/Logistics/Settings', [
            'settings' => LogisticsSetting::firstOrCreate(['shop_owner_id' => $shopOwnerId]),
        ]);
    }

    public function batches(Request $request): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('manage-logistics-batches');
        $shop = ShopOwner::query()->findOrFail($shopOwnerId);
        [$module, $availableModules] = $this->logisticsModuleFilter($shop, (string) $request->query('module', 'all'));
        $deliveryWindow = in_array($request->query('window'), ['morning', 'afternoon'], true)
            ? $request->query('window') : 'all';
        $deliveryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date'))
            ? (string) $request->query('date') : null;
        $settings = LogisticsSetting::firstOrCreate(['shop_owner_id' => $shopOwnerId]);
        $attemptRelations = ['attempts' => fn ($attempts) => $attempts
            ->where('attempt_type', 'delivery')->where('status', 'failed')->latest('attempted_at')->latest('id')->limit(1)];
        $filterLegModule = fn ($query) => $query
            ->when($module !== 'all', fn ($shipments) => $shipments
                ->whereIn('source_type', Shipment::sourceTypesForModule($module)));
        $batches = DeliveryBatch::with(['riderProfile', 'legs.shipment', 'legs.attempts' => $attemptRelations['attempts']])
            ->where('shop_owner_id', $shopOwnerId)
            ->when($module !== 'all', fn ($query) => $this->filterBatchesByModule($query, $module))
            ->when($deliveryDate, fn ($query) => $query->whereDate('delivery_date', $deliveryDate))
            ->when($deliveryWindow !== 'all', fn ($query) => $query->where('delivery_window', $deliveryWindow))
            ->latest()
            ->get()
            ->each(function (DeliveryBatch $batch): void {
                $modules = $batch->legs
                    ->map(fn ($leg) => Shipment::moduleForSourceType((string) $leg->shipment?->source_type))
                    ->unique();
                $batch->setAttribute('module', $modules->count() === 1 && $modules->first()
                    ? $modules->first()
                    : 'mixed');
            });
        $pool = ShipmentLeg::with(['shipment', ...$attemptRelations])
            ->withCount(['attempts as failed_attempt_count' => fn ($attempts) => $attempts->where('attempt_type', 'delivery')->where('status', 'failed')])
            ->whereHas('shipment', fn ($query) => $filterLegModule($query->where('shop_owner_id', $shopOwnerId)))
            ->whereNull('delivery_batch_id')->where('schedule_status', 'scheduled')->where('status', 'pending')
            ->when($deliveryDate, fn ($query) => $query->whereDate('scheduled_delivery_date', $deliveryDate))
            ->when($deliveryWindow !== 'all', fn ($query) => $query->where('delivery_window', $deliveryWindow))
            ->get();
        $unscheduled = ShipmentLeg::with(['shipment', ...$attemptRelations])
            ->withCount(['attempts as failed_attempt_count' => fn ($attempts) => $attempts->where('attempt_type', 'delivery')->where('status', 'failed')])
            ->whereHas('shipment', fn ($query) => $filterLegModule($query->where('shop_owner_id', $shopOwnerId)))
            ->whereNull('delivery_batch_id')->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('schedule_status')->orWhere('schedule_status', '!=', 'scheduled'))
            ->get();
        $this->attachRepairSourceSummaries(
            $batches->flatMap->legs->pluck('shipment')->merge($pool->pluck('shipment'))->merge($unscheduled->pluck('shipment')),
            $shopOwnerId,
        );

        return Inertia::render('ERP/Logistics/Batches', [
            'batches' => $batches,
            'pool' => $pool,
            'unscheduled' => $unscheduled,
            'riders' => RiderProfile::where('shop_owner_id', $shopOwnerId)->where('active', true)->where('availability_status', 'available')->get(),
            'dailyRiderCapacity' => (int) $settings->daily_rider_capacity,
            'maxDeliveryAttempts' => (int) $settings->max_delivery_attempts,
            'filters' => ['module' => $module, 'date' => $deliveryDate, 'window' => $deliveryWindow],
            'availableModules' => $availableModules,
            'showModuleFilter' => count($availableModules) > 1,
        ]);
    }

    private function authorizedShopOwnerId(string $permission): int
    {
        $user = Auth::guard('user')->user();
        $hasAccess = $user && $user->can($permission);

        if (! $user || ! $user->shop_owner_id || ! $hasAccess) {
            abort(403);
        }

        return (int) $user->shop_owner_id;
    }

    private function stats(int $shopOwnerId): array
    {
        $query = Shipment::query()->where('shop_owner_id', $shopOwnerId);
        $legs = ShipmentLeg::query()->whereHas('shipment', fn ($q) => $q->where('shop_owner_id', $shopOwnerId));
        $delivered = (clone $legs)->where('status', 'delivered')->count();
        $failed = (clone $legs)->whereIn('status', ['delivery_attempted', 'needs_resolution'])->count();

        return [
            'requested' => (clone $query)->where('status', 'requested')->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
            'due_today' => (clone $legs)->whereDate('scheduled_delivery_date', today())->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'overdue' => (clone $legs)->whereDate('scheduled_delivery_date', '<', today())->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'failed_attempts' => $failed,
            'unassigned' => (clone $legs)->where('status', 'pending')->whereDoesntHave('assignments', fn ($q) => $q->whereIn('status', ['assigned', 'accepted']))->count(),
            'rider_workload' => DeliveryAssignment::query()->whereIn('status', ['assigned', 'accepted'])->whereHas('leg.shipment', fn ($q) => $q->where('shop_owner_id', $shopOwnerId))->count(),
            'delivery_success_rate' => $delivered + $failed ? round($delivered * 100 / ($delivered + $failed), 1) : 0,
        ];
    }

    private function logisticsModuleFilter(ShopOwner $shop, string $requested): array
    {
        $available = $shop->logisticsModules();
        $selected = count($available) === 1
            ? $available[0]
            : (in_array($requested, $available, true) ? $requested : 'all');

        return [$selected, $available];
    }

    private function filterBatchesByModule($query, string $module)
    {
        $sourceTypes = Shipment::sourceTypesForModule($module);

        return $query
            ->whereHas('legs.shipment', fn ($shipments) => $shipments->whereIn('source_type', $sourceTypes))
            ->whereDoesntHave('legs.shipment', fn ($shipments) => $shipments->whereNotIn('source_type', $sourceTypes));
    }

    private function attachRepairSourceSummaries(iterable $shipments, int $shopOwnerId): void
    {
        $shipments = collect($shipments)
            ->filter(fn ($shipment) => $shipment instanceof Shipment && $shipment->source_type === 'repair_request')
            ->unique('id');
        if ($shipments->isEmpty()) {
            return;
        }

        $repairs = RepairRequest::query()
            ->with('user:id,name,first_name,last_name')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('id', $shipments->pluck('source_id'))
            ->get()
            ->keyBy('id');

        $shipments->each(function (Shipment $shipment) use ($repairs): void {
            if ($repair = $repairs->get($shipment->source_id)) {
                $shipment->setAttribute('source_summary', $this->repairSourceSummary($repair));
            }
        });
    }

    private function repairSourceSummary(RepairRequest $repair): array
    {
        $customer = $repair->customer_name
            ?: $repair->user?->name
            ?: trim("{$repair->user?->first_name} {$repair->user?->last_name}");
        $shoe = trim(implode(' ', array_filter([$repair->brand, $repair->shoe_type])));

        return [
            'request_number' => $repair->request_id ?: (string) $repair->id,
            'customer_name' => $customer ?: 'Customer not provided',
            'shoe_summary' => $shoe ?: ($repair->description ?: 'Repair item'),
        ];
    }
}
