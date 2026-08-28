<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\Logistics\LogisticsAction;
use App\Http\Controllers\Controller;
use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\DeliveryIncident;
use App\Models\DeliveryDispute;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ArrivalService;
use App\Services\Logistics\LogisticsActorPolicy;
use App\Services\Logistics\RiderProfileSyncService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\Erp\ErpActorContext;
use Inertia\Inertia;
use Inertia\Response;

class ErpLogisticsController extends Controller
{
    public function __construct(
        private ArrivalService $arrivals,
        private LogisticsActorPolicy $logisticsPolicy,
    ) {}

    public function dashboard(): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('access-logistics-dashboard');
        $context = $this->erpContext();
        $ownerMode = $context?->isOwnerMode() === true;
        $user = Auth::guard('user')->user();

        return Inertia::render('ERP/Logistics/Dashboard', [
            'stats' => $this->stats($shopOwnerId),
            'canViewShipments' => $ownerMode || (bool) ($user?->can('assign-logistics-deliveries')
                || $user?->can('manage-logistics-riders')),
            'canManageRiders' => $ownerMode || (bool) $user?->can('manage-logistics-riders'),
        ]);
    }

    public function dashboardStats(): JsonResponse
    {
        $shopOwnerId = $this->authorizedShopOwnerId('access-logistics-dashboard');

        return response()->json([
            'stats' => $this->stats($shopOwnerId),
        ]);
    }

    public function shipments(Request $request): Response|RedirectResponse
    {
        $context = $this->erpContext();
        $ownerMode = $context?->isOwnerMode() === true;
        $user = Auth::guard('user')->user();
        if (! $ownerMode && $user?->can('operate-logistics-deliveries') && ! $user->can('assign-logistics-deliveries')) {
            return redirect()->route('erp.logistics.deliveries');
        }

        $shopOwnerId = $this->authorizedShopOwnerId('assign-logistics-deliveries');
        $shop = ShopOwner::query()->findOrFail($shopOwnerId);
        $actor = $context?->actor() ?? ($ownerMode
            ? Auth::guard('shop_owner')->user()
            : $user);
        $rider = $actor instanceof Authenticatable
            ? $this->trustedRiderProfile($request, $actor, $shop)
            : null;
        $riderMode = $rider instanceof RiderProfile;
        $isDispatcher = ! $riderMode && ($ownerMode || ($user && (
            $user->can('assign-logistics-deliveries') ||
            $user->can('manage-logistics-riders')
        )));
        $canAssign = ! $riderMode
            && $actor instanceof Authenticatable
            && $this->logisticsPolicy->canPerform($actor, $shop, LogisticsAction::ASSIGN_RIDER);
        $canApproveProof = ! $riderMode
            && $actor instanceof Authenticatable
            && $this->logisticsPolicy->canPerform($actor, $shop, LogisticsAction::REVIEW_PROOF);
        $canUpdateStatus = $riderMode;
        $canRecordProof = $riderMode
            && $actor instanceof Authenticatable
            && ($actor instanceof ShopOwner || $actor->can('record-logistics-proof'));
        $canReportIssue = $riderMode;
        $riderProfileId = $rider?->id;
        $search = trim((string) ($request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ])['search'] ?? ''));
        [$module, $availableModules] = $this->logisticsModuleFilter($shop, (string) $request->query('module', 'all'));
        $status = in_array($request->query('status'), ['all', 'incomplete', 'requested', 'active', 'completed', 'cancelled', 'awaiting_proof_approval', 'proof_correction_required', 'customer_disputes', 'failed_attempts', 'failed_pickups'], true)
            ? $request->query('status') : 'all';
        $purpose = $request->query('purpose', 'all');
        $deliveryWindow = in_array($request->query('window'), ['morning', 'afternoon'], true)
            ? $request->query('window') : 'all';
        $settings = LogisticsSetting::firstOrCreate(['shop_owner_id' => $shopOwnerId]);
        $maxDeliveryAttempts = (int) $settings->max_delivery_attempts;

        return Inertia::render('ERP/Logistics/Shipments', [
            'shipments' => tap(Shipment::query()
                ->with(['deliveryDisputes', 'legs' => function ($query) use ($isDispatcher, $riderProfileId) {
                    $query->with([
                        'assignments.riderProfile',
                        'proofs',
                        'incidents',
                        'events' => fn ($events) => $events
                            ->whereIn('event_type', ['pickup_arrived', 'dropoff_arrived']),
                        'attempts' => fn ($attempts) => $attempts
                            ->where('status', 'failed')
                            ->latest('attempted_at')
                            ->latest('id')
                            ->limit(1),
                    ]);
                    $query->withCount(['attempts as failed_attempt_count' => fn ($attempts) => $attempts
                        ->where('attempt_type', 'delivery')->where('status', 'failed')]);
                    $query->withCount(['attempts as failed_pickup_count' => fn ($attempts) => $attempts
                        ->where('attempt_type', 'pickup')->where('status', 'failed')]);

                    if (! $isDispatcher) {
                        $query->whereHas('assignments', function ($assignments) use ($riderProfileId) {
                            $assignments->whereIn('status', ['assigned', 'accepted'])
                                ->where('rider_profile_id', $riderProfileId);
                        });
                    }
                }])
                ->where('shop_owner_id', $shopOwnerId)
                ->when($search !== '', fn ($query) => $this->filterShipmentsBySearch($query, $search, $shopOwnerId))
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
                ->when($status === 'proof_correction_required', fn ($query) => $query
                    ->whereHas('legs', fn ($legs) => $legs->where('status', 'proof_correction_required')))
                ->when($status === 'customer_disputes', fn ($query) => $query
                    ->whereHas('deliveryDisputes', fn ($disputes) => $disputes->whereIn('status', ['open', 'investigating'])))
                ->when($status === 'failed_attempts', fn ($query) => $query
                    ->whereHas('legs.attempts', fn ($attempts) => $attempts
                        ->where('attempt_type', 'delivery')->where('status', 'failed')))
                ->when($status === 'failed_pickups', fn ($query) => $query
                    ->where('purpose', 'repair_pickup')
                    ->whereHas('legs', fn ($legs) => $legs
                        ->where('status', 'needs_resolution')
                        ->where('resolution_type', 'pickup_failed')
                        ->whereHas('attempts', fn ($attempts) => $attempts
                            ->where('attempt_type', 'pickup')->where('status', 'failed'))))
                ->when($purpose !== 'all', function ($query) use ($purpose) {
                    $query->where('purpose', $purpose);
                })
                ->when(! $isDispatcher, function ($query) use ($riderProfileId) {
                    $query->whereHas('legs.assignments', function ($assignments) use ($riderProfileId) {
                        $assignments->whereIn('status', ['assigned', 'accepted'])
                            ->where('rider_profile_id', $riderProfileId);
                    });
                })
                ->latest()
                ->paginate(10)
                ->withQueryString(), function ($shipments) use ($shopOwnerId): void {
                    $this->attachShipmentSummaries($shipments->getCollection(), $shopOwnerId);
                    $shipments->getCollection()->flatMap->legs
                        ->each(function (ShipmentLeg $leg): void {
                            $this->attachArrivalPayload($leg);
                            $this->attachProofPayload($leg);
                            $leg->attempts->each(fn (DeliveryAttempt $attempt) => $this->attachAttemptEvidencePayload($attempt));
                        });
                }),
            'filters' => [
                'status' => $status,
                'purpose' => $purpose,
                'module' => $module,
                'window' => $deliveryWindow,
                'search' => $search,
            ],
            'availableModules' => $availableModules,
            'showModuleFilter' => count($availableModules) > 1,
            'logisticsSchedule' => [
                'operating_days' => array_values($settings->operating_days ?? []),
                'blackout_dates' => array_values($settings->blackout_dates ?? []),
            ],
            'today' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
            'canAssign' => $canAssign,
            'canUpdateStatus' => $canUpdateStatus,
            'canRecordProof' => $canRecordProof,
            'canApproveProof' => $canApproveProof,
            'canResolveDisputes' => ! $ownerMode && $user && $user->can('resolve-logistics-exceptions'),
            'canReportIssue' => $canReportIssue,
            'canViewShipments' => $isDispatcher || $riderMode,
            'riderMode' => $riderMode,
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
        $search = trim((string) ($request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ])['search'] ?? ''));
        $tab = in_array($request->query('tab'), ['upcoming', 'history', 'issues', 'all'], true)
            ? $request->query('tab')
            : 'upcoming';
        $business = in_array($request->query('business'), ['all', 'retail', 'repair'], true)
            ? $request->query('business')
            : 'all';
        $window = in_array($request->query('window'), ['today', 'week'], true)
            ? $request->query('window')
            : 'all';
        $shopTimezone = config('app.shop_timezone', 'Asia/Manila');
        $dates = match ($window) {
            'today' => [now($shopTimezone)->startOfDay(), now($shopTimezone)->endOfDay()],
            'week' => [now($shopTimezone)->startOfWeek(), now($shopTimezone)->endOfWeek()],
            default => null,
        };

        $rider = $this->logisticsPolicy->resolveRiderProfile($user, ShopOwner::query()->findOrFail($shopOwnerId))
            ?? abort(403);
        $hasActiveAssignment = $rider->assignments()
            ->whereIn('status', ['assigned', 'accepted'])
            ->exists();

        $batches = DeliveryBatch::query()
            ->with([
                'legs.shipment',
                'legs.proofs',
                'legs.incidents',
                'legs.events' => fn ($query) => $query
                    ->whereIn('event_type', ['pickup_arrived', 'dropoff_arrived'])
                    ->oldest('id'),
                'legs.assignments' => fn ($query) => $query->where('rider_profile_id', $rider->id),
                'legs.attempts' => fn ($query) => $query
                    ->where('attempt_type', 'delivery')
                    ->where('status', 'failed')
                    ->latest('attempted_at')
                    ->latest('id'),
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($query) use ($rider) {
                $query->where('rider_profile_id', $rider->id)
                    ->orWhereHas('legs.assignments', fn ($assignments) => $assignments
                        ->where('rider_profile_id', $rider->id)
                        ->where('status', 'rejected'));
            })
            ->get();

        $standalone = ShipmentLeg::query()
            ->with([
                'shipment',
                'proofs',
                'incidents',
                'events' => fn ($query) => $query
                    ->whereIn('event_type', ['pickup_arrived', 'dropoff_arrived'])
                    ->oldest('id'),
                'assignments' => fn ($query) => $query->where('rider_profile_id', $rider->id),
                'latestAssignment.riderProfile',
                'attempts' => fn ($query) => $query
                    ->where('attempt_type', 'delivery')
                    ->where('status', 'failed')
                    ->latest('attempted_at')
                    ->latest('id'),
            ])
            ->whereNull('delivery_batch_id')
            ->whereHas('shipment', fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
            ->whereHas('assignments', fn ($query) => $query->where('rider_profile_id', $rider->id))
            ->get();

        $this->attachShipmentSummaries(
            $batches->flatMap->legs->pluck('shipment')->merge($standalone->pluck('shipment')),
            $shopOwnerId,
        );

        $workItems = $batches
            ->map(fn (DeliveryBatch $batch) => $this->batchWorkItem($batch, $rider))
            ->concat($standalone->map(fn (ShipmentLeg $leg) => $this->standaloneWorkItem($leg, $rider)))
            ->filter()
            ->values();

        $currentCandidates = $workItems->where('group', 'current')
            ->sortBy(fn (array $item) => [
                $item['started_at'] ?? '9999-12-31',
                $item['kind'],
                $item['id'],
            ])
            ->values();
        $current = $currentCandidates->first();
        $activeConflicts = $currentCandidates->slice(1)->map(function (array $item) {
            $item['group'] = 'conflict';

            return $item;
        })->values();
        $conflictKeys = $activeConflicts->pluck('key')->all();
        $workItems = $workItems->map(function (array $item) use ($conflictKeys) {
            if (in_array($item['key'], $conflictKeys, true)) {
                $item['group'] = 'conflict';
            }

            return $item;
        });

        $offers = $workItems->where('group', 'offer')
            ->sortBy(fn (array $item) => [
                $item['response_deadline'] ?? '9999-12-31',
                $item['offered_at'] ?? '9999-12-31',
                $item['key'],
            ])
            ->values();
        $upcoming = $workItems->where('group', 'upcoming')
            ->sortBy(fn (array $item) => [
                $item['delivery_date'] ?? '9999-12-31',
                $item['delivery_window'] === 'morning' ? 0 : 1,
                $item['assignment_at'] ?? '9999-12-31',
                $item['key'],
            ])
            ->values();
        $upNext = $upcoming->first();

        $issues = $this->issueItems($batches, $standalone, $rider);
        $source = match ($tab) {
            'upcoming' => $workItems->where('group', 'upcoming')
                ->reject(fn (array $item) => $item['key'] === ($upNext['key'] ?? null)),
            'history' => $workItems->where('group', 'history'),
            'issues' => $issues,
            'all' => $workItems,
        };
        $filtered = $source
            ->when($business !== 'all', fn (Collection $items) => $items->filter(
                fn (array $item) => in_array($business, $item['business_types'], true)
            ))
            ->when($dates, fn (Collection $items) => $items->filter(
                fn (array $item) => filled($item['delivery_date'])
                    && Carbon::parse($item['delivery_date'], $shopTimezone)
                        ->betweenIncluded($dates[0], $dates[1])
            ))
            ->when($search !== '', function (Collection $items) use ($search) {
                $needle = Str::lower($search);

                return $items->filter(
                    fn (array $item) => str_contains(Str::lower($item['search_text']), $needle)
                )->map(function (array $item) use ($needle) {
                    if ($item['item_type'] === 'work') {
                        $match = collect($item['deliveries'])->first(
                            fn (array $leg) => Str::lower((string) $leg['id']) === $needle
                                || Str::lower("delivery {$leg['id']}") === $needle
                        );
                        $item['matched_delivery_id'] = $match['id'] ?? null;
                    }

                    return $item;
                });
            });
        $filtered = $this->sortDeliveryItems($filtered, $tab)
            ->map(fn (array $item) => $this->visibleDeliveryItem($item))
            ->values();

        return Inertia::render('ERP/Logistics/MyDeliveries', [
            'deliveryData' => [
                'offers' => $offers->map(fn (array $item) => $this->visibleDeliveryItem($item)),
                'current' => $current ? $this->visibleDeliveryItem($current) : null,
                'active_conflicts' => $activeConflicts->map(fn (array $item) => $this->visibleDeliveryItem($item)),
                'has_active_conflict' => $activeConflicts->isNotEmpty(),
                'up_next' => $upNext ? $this->visibleDeliveryItem($upNext) : null,
                'list' => $this->paginateDeliveryItems($filtered, $request),
                'filters' => compact('tab', 'business', 'window', 'search'),
            ],
            'canRecordProof' => $hasActiveAssignment && $user->can('record-logistics-proof'),
            'canUpdateStatus' => $hasActiveAssignment,
            'canReportIssue' => $hasActiveAssignment,
            'maxDeliveryAttempts' => (int) LogisticsSetting::firstOrCreate([
                'shop_owner_id' => $shopOwnerId,
            ])->max_delivery_attempts,
            'today' => now($shopTimezone)->toDateString(),
        ]);
    }

    private function batchWorkItem(DeliveryBatch $batch, RiderProfile $rider): ?array
    {
        $rejected = $batch->legs
            ->flatMap->assignments
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'rejected')
            ->sortByDesc('id')
            ->first();
        $isCurrentRider = (int) $batch->rider_profile_id === $rider->id;
        $hasActiveRiderStop = $batch->legs->contains(fn (ShipmentLeg $leg) =>
            $leg->rider_progress_state === RiderProgressState::ACTIVE
            && ! in_array($leg->status->value, ['delivered', 'cancelled', 'failed', 'proof_correction_required'], true)
            && $leg->assignments
                ->where('rider_profile_id', $rider->id)
                ->contains(fn (DeliveryAssignment $assignment) => in_array($assignment->status, ['assigned', 'accepted'], true))
        );
        $group = $isCurrentRider ? match ($batch->status) {
            'offered' => 'offer',
            'accepted' => 'upcoming',
            'in_progress' => $hasActiveRiderStop ? 'current' : 'history',
            'completed', 'cancelled' => 'history',
            default => null,
        } : ($rejected ? 'history' : null);

        if (! $group) {
            return null;
        }

        $status = $isCurrentRider
            ? ($batch->status === 'in_progress' && ! $hasActiveRiderStop ? 'review_pending' : $batch->status)
            : 'declined';
        $deliveries = $batch->legs->map(fn (ShipmentLeg $leg) => $this->deliveryPayload($leg))->values();
        $businessTypes = $this->businessTypes($batch->legs->pluck('shipment.purpose'));

        return [
            'item_type' => 'work',
            'key' => "batch:{$batch->id}",
            'kind' => 'batch',
            'id' => $batch->id,
            'status' => $status,
            'group' => $group,
            'business_types' => $businessTypes,
            'business_label' => $this->businessLabel($businessTypes, $batch->legs->first()?->shipment?->purpose),
            'delivery_date' => $batch->delivery_date?->toDateString(),
            'delivery_window' => $batch->delivery_window,
            'started_at' => $batch->started_at?->toISOString(),
            'offered_at' => $batch->offered_at?->toISOString(),
            'response_deadline' => null,
            'assignment_at' => $batch->accepted_at?->toISOString() ?? $batch->offered_at?->toISOString(),
            'terminal_at' => $batch->completed_at?->toISOString()
                ?? $batch->cancelled_at?->toISOString()
                ?? $batch->rejected_at?->toISOString()
                ?? $rejected?->rejected_at?->toISOString(),
            'updated_at' => $batch->updated_at?->toISOString(),
            'matched_delivery_id' => null,
            'deliveries' => $deliveries->all(),
            'search_text' => $this->workSearchText("batch {$batch->id}", $deliveries),
        ];
    }

    private function standaloneWorkItem(ShipmentLeg $leg, RiderProfile $rider): ?array
    {
        $riderAssignment = $leg->assignments
            ->where('rider_profile_id', $rider->id)
            ->sortByDesc('id')
            ->first();
        if (! $riderAssignment) {
            return null;
        }

        $latestAssignment = $leg->latestAssignment;
        $isCurrentRider = $latestAssignment?->is($riderAssignment)
            && (int) $latestAssignment->rider_profile_id === $rider->id
            && in_array($latestAssignment->status, ['assigned', 'accepted'], true);
        $legStatus = $leg->status->value;
        $riderProgressState = $leg->rider_progress_state;

        if (! $isCurrentRider) {
            $group = 'history';
            $status = $latestAssignment?->isNot($riderAssignment)
                ? 'reassigned'
                : match ($riderAssignment->status) {
                    'rejected' => 'declined',
                    'cancelled' => 'cancelled',
                    default => $legStatus,
                };
        } elseif ($riderAssignment->status === 'assigned'
            && in_array($legStatus, ['assigned', 'pickup_scheduled'], true)) {
            $group = 'offer';
            $status = 'offered';
        } elseif ($riderProgressState !== RiderProgressState::ACTIVE) {
            $group = 'history';
            $status = match ($riderProgressState) {
                RiderProgressState::PROOF_SUBMITTED => 'review_pending',
                RiderProgressState::PROOF_ACTION_REQUIRED => 'proof_action_required',
                default => $legStatus,
            };
        } else {
            $group = match ($legStatus) {
                'assigned', 'pickup_scheduled' => 'upcoming',
                'picked_up', 'in_transit', 'delivery_attempted', 'awaiting_proof_approval' => 'current',
                'needs_resolution' => in_array($leg->resolution_type, [null, 'retry'], true) ? 'current' : null,
                'delivered', 'cancelled', 'proof_correction_required' => 'history',
                default => null,
            };
            $status = $legStatus;
        }

        if (! $group) {
            return null;
        }

        $purpose = $leg->shipment?->purpose;
        $businessTypes = $this->businessTypes([$purpose]);
        $deliveries = collect([$this->deliveryPayload($leg)]);

        return [
            'item_type' => 'work',
            'key' => "single:{$leg->id}",
            'kind' => 'single',
            'id' => $leg->id,
            'status' => $status,
            'group' => $group,
            'business_types' => $businessTypes,
            'business_label' => $this->businessLabel($businessTypes, $purpose),
            'delivery_date' => $leg->scheduled_delivery_date?->toDateString(),
            'delivery_window' => $leg->delivery_window,
            'started_at' => $leg->out_for_delivery_at?->toISOString()
                ?? $leg->picked_up_at?->toISOString()
                ?? $latestAssignment?->accepted_at?->toISOString()
                ?? $latestAssignment?->assigned_at?->toISOString(),
            'offered_at' => $riderAssignment->status === 'assigned'
                ? $riderAssignment->assigned_at?->toISOString()
                : null,
            'response_deadline' => null,
            'assignment_at' => $latestAssignment?->accepted_at?->toISOString()
                ?? $latestAssignment?->assigned_at?->toISOString(),
            'terminal_at' => $leg->delivered_at?->toISOString()
                ?? $leg->failed_at?->toISOString()
                ?? $riderAssignment->completed_at?->toISOString()
                ?? $riderAssignment->cancelled_at?->toISOString()
                ?? $riderAssignment->rejected_at?->toISOString(),
            'updated_at' => $leg->updated_at?->toISOString(),
            'matched_delivery_id' => null,
            'deliveries' => $deliveries->all(),
            'search_text' => $this->workSearchText("single {$leg->id}", $deliveries),
        ];
    }

    private function issueItems(Collection $batches, Collection $standalone, RiderProfile $rider): Collection
    {
        $batchLegs = $batches->flatMap(function (DeliveryBatch $batch) {
            return $batch->legs->map(fn (ShipmentLeg $leg) => [$leg, "batch:{$batch->id}"]);
        });
        $standaloneLegs = $standalone->map(fn (ShipmentLeg $leg) => [$leg, "single:{$leg->id}"]);

        return $batchLegs->concat($standaloneLegs)
            ->map(function (array $record) use ($rider) {
                [$leg, $parentKey] = $record;

                if ($leg->rider_progress_state === RiderProgressState::PROOF_ACTION_REQUIRED
                    && $leg->status->value === 'proof_correction_required') {
                    $proof = $this->latestDeliveryProof($leg);
                    $assignment = $leg->assignments
                        ->where('rider_profile_id', $rider->id)
                        ->sortByDesc('id')
                        ->first();
                    $assignmentActive = $assignment
                        && in_array($assignment->status, ['assigned', 'accepted'], true);

                    if ($proof && $proof->review_status === 'rejected' && $assignment) {
                        $businessTypes = $this->businessTypes([$leg->shipment?->purpose]);
                        $deliveries = collect([$this->deliveryPayload($leg)]);

                        return [
                            'item_type' => 'issue',
                            'issue_type' => 'proof_correction',
                            'key' => "proof-correction:{$leg->id}:{$proof->id}",
                            'id' => $proof->id,
                            'delivery_id' => $leg->id,
                            'parent_key' => $parentKey,
                            'business_types' => $businessTypes,
                            'reason' => $proof->rejection_reason,
                            'proof_id' => $proof->id,
                            'replaces_proof_id' => $proof->replaces_proof_id,
                            'replacement_allowed' => $assignmentActive,
                            'attempted_at' => $proof->recorded_at?->toISOString(),
                            'delivery_date' => $leg->scheduled_delivery_date?->toDateString(),
                            'search_text' => $this->workSearchText(
                                "{$parentKey} proof correction {$leg->id} {$proof->rejection_reason}",
                                $deliveries,
                            ),
                        ];
                    }
                }

                if ($leg->status->value !== 'delivery_attempted' || filled($leg->resolution_type)) {
                    return null;
                }

                $assignment = $leg->assignments
                    ->where('rider_profile_id', $rider->id)
                    ->sortByDesc('id')
                    ->first();
                $attempt = $leg->attempts->first();
                if (! $assignment || ! $attempt || (int) $attempt->delivery_assignment_id !== $assignment->id) {
                    return null;
                }

                $businessTypes = $this->businessTypes([$leg->shipment?->purpose]);
                $deliveries = collect([$this->deliveryPayload($leg)]);

                return [
                    'item_type' => 'issue',
                    'issue_type' => 'delivery_attempt',
                    'key' => "issue:{$attempt->id}",
                    'id' => $attempt->id,
                    'delivery_id' => $leg->id,
                    'parent_key' => $parentKey,
                    'business_types' => $businessTypes,
                    'reason' => $attempt->reason_code,
                    'attempted_at' => $attempt->attempted_at?->toISOString(),
                    'delivery_date' => $leg->scheduled_delivery_date?->toDateString(),
                    'search_text' => $this->workSearchText(
                        "{$parentKey} issue {$attempt->reason_code}",
                        $deliveries,
                    ),
                ];
            })
            ->filter()
            ->values();
    }

    private function latestDeliveryProof(ShipmentLeg $leg): ?HandoffProof
    {
        if (! $leg->relationLoaded('proofs')) {
            return null;
        }

        return $leg->proofs
            ->filter(fn (HandoffProof $proof): bool => $proof->handoff_type === 'delivery')
            ->sort(function (HandoffProof $left, HandoffProof $right): int {
                $recordedAt = strcmp(
                    $right->recorded_at?->format('Y-m-d H:i:s.u') ?? '',
                    $left->recorded_at?->format('Y-m-d H:i:s.u') ?? '',
                );

                return $recordedAt !== 0 ? $recordedAt : $right->id <=> $left->id;
            })
            ->first();
    }

    private function deliveryPayload(ShipmentLeg $leg): array
    {
        $payload = $leg->toArray();
        unset($payload['events']);
        $payload['status'] = $leg->status->value;
        $payload['rider_progress_state'] = $leg->rider_progress_state->value;
        $payload['failed_attempt_count'] = $leg->attempts->count();
        $payload['arrivals'] = $this->arrivalPayload($leg);
        $payload['proofs'] = $leg->relationLoaded('proofs')
            ? $leg->proofs->map(function (HandoffProof $proof): array {
                $this->attachProofUrl($proof);

                return $proof->toArray();
            })->values()->all()
            : [];
        $currentDeliveryProof = $this->latestDeliveryProof($leg);
        $replacementAllowed = $leg->assignments
            ->contains(fn (DeliveryAssignment $assignment): bool => in_array($assignment->status, ['assigned', 'accepted'], true));
        $payload['proof_review'] = $currentDeliveryProof ? [
            'state' => $currentDeliveryProof->review_status,
            'proof_id' => $currentDeliveryProof->id,
            'replaces_proof_id' => $currentDeliveryProof->replaces_proof_id,
            'rejection_reason' => $currentDeliveryProof->review_status === 'rejected'
                ? $currentDeliveryProof->rejection_reason
                : null,
            'replacement_allowed' => $leg->status->value === 'proof_correction_required'
                && $currentDeliveryProof->review_status === 'rejected'
                && $replacementAllowed,
        ] : null;
        $payload['incidents'] = $leg->relationLoaded('incidents')
            ? $leg->incidents->map(fn (DeliveryIncident $incident) => $this->incidentPayload($incident))->values()->all()
            : [];

        return $payload;
    }

    private function incidentPayload(DeliveryIncident $incident): array
    {
        $evidenceUrls = collect($incident->getRawOriginal('photo_paths') ? $incident->photo_paths : [])
            ->values()
            ->map(fn ($path, $index) => is_string($path)
                && str_starts_with($path, 'incident-evidence/')
                && ! str_contains($path, '..')
                && ! str_contains($path, '\\')
                && Storage::disk('local')->exists($path)
                ? route('api.logistics.incidents.evidence', ['incident' => $incident, 'index' => $index])
                : null)
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $incident->id,
            'type' => $incident->type,
            'status' => $incident->status,
            'notes' => $incident->notes,
            'resolution' => $incident->resolution,
            'resolved_at' => $incident->resolved_at?->toISOString(),
            'reporting_rider_profile_id' => $incident->reporting_rider_profile_id,
            'evidence_urls' => $evidenceUrls,
        ];
    }

    private function proofUrl(HandoffProof $proof): ?string
    {
        $path = $proof->getRawOriginal('file_path');
        if (! is_string($path)
            || ! str_starts_with($path, 'logistics-proof/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return '/api/logistics/proofs/' . $proof->id . '/file';
    }

    private function attachArrivalPayload(ShipmentLeg $leg): void
    {
        $leg->setAttribute('arrivals', $this->arrivalPayload($leg));
        $leg->unsetRelation('events');
    }

    private function attachProofPayload(ShipmentLeg $leg): void
    {
        if (! $leg->relationLoaded('proofs')) {
            return;
        }

        $leg->proofs->each(fn (HandoffProof $proof) => $this->attachProofUrl($proof));
    }

    private function attachProofUrl(HandoffProof $proof): void
    {
        $proof->setAttribute('proof_url', $this->proofUrl($proof));
    }

    private function attachAttemptEvidencePayload(DeliveryAttempt $attempt): void
    {
        $path = $attempt->getRawOriginal('file_path');
        $available = is_string($path)
            && str_starts_with($path, 'logistics-attempt/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && Storage::disk('local')->exists($path);

        $attempt->setAttribute('proof_available', $available);
        $attempt->setAttribute(
            'proof_url',
            $available ? route('api.logistics.attempts.file', ['attempt' => $attempt]) : null,
        );
    }

    private function arrivalPayload(ShipmentLeg $leg): array
    {
        $assignment = $leg->relationLoaded('assignments')
            ? $leg->assignments->whereIn('status', ['assigned', 'accepted'])->sortByDesc('id')->first()
            : null;

        return collect(['pickup' => 'pickup_arrived', 'dropoff' => 'dropoff_arrived'])
            ->map(fn (string $eventType) => $this->arrivals->eventForAssignment($leg, $eventType, $assignment))
            ->filter()
            ->map(fn ($event) => [
                'id' => $event->id,
                'arrival_type' => $event->event_type === 'pickup_arrived' ? 'pickup' : 'dropoff',
                'result' => data_get($event->metadata, 'result'),
                'distance_m' => data_get($event->metadata, 'distance_m'),
                'radius_m' => data_get($event->metadata, 'radius_m'),
                'accuracy_m' => data_get($event->metadata, 'accuracy_m'),
                'exception_reason' => data_get($event->metadata, 'exception_reason'),
                'exception_notes' => data_get($event->metadata, 'exception_notes'),
                'recorded_at' => $event->created_at?->toISOString(),
            ])
            ->all();
    }

    private function businessTypes(iterable $purposes): array
    {
        return collect($purposes)
            ->map(fn (?string $purpose) => match ($purpose) {
                'repair_pickup', 'repair_return' => 'repair',
                'retail_delivery', 'refund_return' => 'retail',
                default => null,
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function businessLabel(array $businessTypes, ?string $purpose): string
    {
        if (count($businessTypes) > 1) {
            return 'Mixed';
        }

        return match ($purpose) {
            'repair_pickup' => 'Repair pickup',
            'repair_return' => 'Repair return',
            'refund_return' => 'Retail return',
            default => 'Retail delivery',
        };
    }

    private function workSearchText(string $prefix, Collection $deliveries): string
    {
        $details = $deliveries->flatMap(function (array $leg) {
            $destination = $leg['destination_snapshot'] ?? [];
            $shipment = $leg['shipment'] ?? [];
            $order = $shipment['order_summary'] ?? [];
            $repair = $shipment['source_summary'] ?? [];

            return [
                $leg['id'] ?? null,
                $shipment['id'] ?? null,
                $shipment['purpose'] ?? null,
                $destination['name'] ?? null,
                $destination['phone'] ?? null,
                $destination['address'] ?? null,
                $order['order_number'] ?? null,
                ...collect($order['items'] ?? [])->flatMap(fn (array $item) => [
                    $item['brand'] ?? null,
                    $item['model'] ?? null,
                ])->all(),
                $repair['request_number'] ?? null,
                $repair['customer_name'] ?? null,
                $repair['shoe_summary'] ?? null,
            ];
        });

        return collect([$prefix])->concat($details)->filter()->implode(' ');
    }

    private function sortDeliveryItems(Collection $items, string $tab): Collection
    {
        return match ($tab) {
            'upcoming' => $items->sortBy(fn (array $item) => [
                $item['delivery_date'] ?? '9999-12-31',
                $item['delivery_window'] === 'morning' ? 0 : 1,
                $item['assignment_at'] ?? '9999-12-31',
                $item['key'],
            ]),
            'history' => $items->sortByDesc(fn (array $item) => [
                $item['terminal_at'] ?? '',
                $item['updated_at'] ?? '',
                $item['key'],
            ]),
            'issues' => $items->sortByDesc(fn (array $item) => [
                $item['attempted_at'] ?? '',
                $item['delivery_id'],
            ]),
            'all' => $items->sortBy(fn (array $item) => [
                ['current' => 0, 'offer' => 1, 'upcoming' => 2, 'conflict' => 3, 'history' => 4][$item['group']],
                $item['delivery_date'] ?? $item['started_at'] ?? $item['terminal_at'] ?? '9999-12-31',
                $item['key'],
            ]),
        };
    }

    private function visibleDeliveryItem(array $item): array
    {
        unset($item['search_text'], $item['assignment_at'], $item['updated_at']);

        return $item;
    }

    private function paginateDeliveryItems(Collection $items, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, 10)->values(),
            $items->count(),
            10,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    public function riders(Request $request): Response
    {
        $shopOwnerId = $this->authorizedShopOwnerId('manage-logistics-riders');
        $ownerMode = $this->isOwnerMode();
        $availability = $request->query('availability', 'all');
        $type = $request->query('type', 'all');
        if (! $ownerMode) {
            app(RiderProfileSyncService::class)->syncShop($shopOwnerId);
        }

        $riders = RiderProfile::query()
            ->select($ownerMode
                ? ['id', 'shop_owner_id', 'name', 'rider_type', 'availability_status', 'active', 'daily_capacity']
                : ['*'])
            ->where('shop_owner_id', $shopOwnerId)
            ->when(in_array($availability, ['available', 'busy', 'inactive'], true), function ($query) use ($availability) {
                $query->where('availability_status', $availability);
            })
            ->when(in_array($type, ['employee', 'contractor', 'shop_owner'], true), function ($query) use ($type) {
                $query->where('rider_type', $type);
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        if ($ownerMode) {
            $riders->getCollection()->transform(
                static fn (RiderProfile $rider): RiderProfile => $rider->setAttribute('phone', null),
            );
        }

        return Inertia::render('ERP/Logistics/Riders', [
            'riders' => $riders,
            'filters' => [
                'availability' => $availability,
                'type' => $type,
            ],
            'canManageRiders' => ! $ownerMode,
        ]);
    }

    public function settings(): Response
    {
        $shop = ShopOwner::query()->findOrFail(
            $this->authorizedShopOwnerId('configure-logistics-settings')
        );

        return Inertia::render('ERP/Logistics/Settings', [
            'settings' => LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id]),
            'shopLocation' => [
                'latitude' => $shop->shop_latitude !== null ? (float) $shop->shop_latitude : null,
                'longitude' => $shop->shop_longitude !== null ? (float) $shop->shop_longitude : null,
                'address' => $shop->business_address,
            ],
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
        $batches = DeliveryBatch::with([
            'riderProfile',
            'legs.shipment',
            'legs.events' => fn ($events) => $events
                ->whereIn('event_type', ['pickup_arrived', 'dropoff_arrived']),
            'legs.attempts' => $attemptRelations['attempts'],
        ])
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
                $batch->legs->each(fn (ShipmentLeg $leg) => $this->attachArrivalPayload($leg));
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
        $this->attachShipmentSummaries(
            $batches->whereNotIn('status', ['completed', 'cancelled'])->flatMap->legs->pluck('shipment')
                ->merge($pool->pluck('shipment'))
                ->merge($unscheduled->pluck('shipment')),
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
            'today' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
            'logisticsSchedule' => [
                'operating_days' => array_values($settings->operating_days ?? []),
                'blackout_dates' => array_values($settings->blackout_dates ?? []),
            ],
            'availableModules' => $availableModules,
            'showModuleFilter' => count($availableModules) > 1,
        ]);
    }

    private function authorizedShopOwnerId(string $permission): int
    {
        $context = $this->erpContext();
        if ($context?->isOwnerMode() === true) {
            return (int) $context->tenantOwner()->getKey();
        }

        $user = Auth::guard('user')->user();
        $hasAccess = $user && $user->can($permission);

        if (! $user || ! $user->shop_owner_id || ! $hasAccess) {
            abort(403);
        }

        return (int) $user->shop_owner_id;
    }

    private function isOwnerMode(): bool
    {
        return $this->erpContext()?->isOwnerMode() === true;
    }

    private function erpContext(): ?ErpActorContext
    {
        $context = request()->attributes->get('erp.actor_context');

        return $context instanceof ErpActorContext ? $context : null;
    }

    private function trustedRiderProfile(
        Request $request,
        Authenticatable $actor,
        ShopOwner $shop,
    ): ?RiderProfile {
        $requested = $request->boolean('rider_mode')
            || strtolower((string) $request->query('mode')) === 'rider';

        if (! $requested) {
            return null;
        }

        $rider = $this->logisticsPolicy->resolveRiderProfile($actor, $shop);

        if (! $rider || ! $rider->assignments()
            ->whereIn('status', ['assigned', 'accepted'])
            ->exists()) {
            return null;
        }

        return $rider;
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
            'due_today' => (clone $legs)->whereDate('scheduled_delivery_date', today())->whereNotIn('status', ['delivered', 'cancelled', 'proof_correction_required'])->count(),
            'overdue' => (clone $legs)->whereDate('scheduled_delivery_date', '<', today())->whereNotIn('status', ['delivered', 'cancelled', 'proof_correction_required'])->count(),
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

    private function filterShipmentsBySearch($query, string $search, int $shopOwnerId)
    {
        if ($search === '') {
            return $query;
        }

        $like = "%{$search}%";
        $orderIds = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($orders) use ($like, $shopOwnerId) {
                $orders
                    ->where('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)
                    ->orWhere('customer_address', 'like', $like)
                    ->orWhere('shipping_address_line', 'like', $like)
                    ->orWhere('shipping_barangay', 'like', $like)
                    ->orWhere('shipping_city', 'like', $like)
                    ->orWhere('shipping_province', 'like', $like)
                    ->orWhereHas('items', fn ($items) => $items
                        ->where('product_name', 'like', $like)
                        ->orWhereHas('product', fn ($products) => $products
                            ->where('shop_owner_id', $shopOwnerId)
                            ->where('brand', 'like', $like)));
            })
            ->select('id');

        return $query->where(function ($shipments) use ($like, $orderIds) {
            $shipments
                ->where('id', 'like', $like)
                ->orWhere('source_id', 'like', $like)
                ->orWhereHas('legs', fn ($legs) => $legs
                    ->where('origin_snapshot', 'like', $like)
                    ->orWhere('destination_snapshot', 'like', $like))
                ->orWhere(fn ($retail) => $retail
                    ->where('source_type', 'order')
                    ->whereIn('source_id', $orderIds));
        });
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

    private function attachShipmentSummaries(iterable $shipments, int $shopOwnerId): void
    {
        collect($shipments)
            ->filter(fn ($shipment) => $shipment instanceof Shipment)
            ->each(function (Shipment $shipment): void {
                $shipment->setAttribute('customer_disputes', $shipment->relationLoaded('deliveryDisputes')
                    ? $shipment->deliveryDisputes->map(fn (DeliveryDispute $dispute) => [
                        'id' => (int) $dispute->id,
                        'status' => (string) $dispute->status,
                        'reason' => (string) $dispute->reason,
                        'notes' => $dispute->notes,
                        'reported_at' => optional($dispute->reported_at)->toISOString(),
                        'resolution' => $dispute->resolution,
                        'resolution_note' => $dispute->resolution_note,
                        'resolved_at' => optional($dispute->resolved_at)->toISOString(),
                        'evidence' => collect($dispute->evidence_media ?? [])
                            ->filter(fn ($media) => is_array($media)
                                && is_string($media['id'] ?? null)
                                && is_string($media['path'] ?? null)
                                && str_starts_with($media['path'], 'delivery-dispute-evidence/')
                                && ! str_contains($media['path'], '..')
                                && ! str_contains($media['path'], '\\')
                                && Storage::disk('local')->exists($media['path']))
                            ->map(fn (array $media) => [
                                'id' => $media['id'],
                                'kind' => ($media['kind'] ?? 'image') === 'video' ? 'video' : 'image',
                                'mime_type' => $media['mime_type'] ?? null,
                                'original_name' => $media['original_name'] ?? null,
                                'url' => route('api.logistics.delivery-disputes.evidence', [
                                    'dispute' => $dispute->id,
                                    'mediaId' => $media['id'],
                                ]),
                            ])
                            ->values()
                            ->all(),
                    ])->values()->all()
                    : []);
            });
        $this->attachRepairSourceSummaries($shipments, $shopOwnerId);
        $this->attachRetailOrderSummaries($shipments, $shopOwnerId);
    }

    private function attachRetailOrderSummaries(iterable $shipments, int $shopOwnerId): void
    {
        $shipments = collect($shipments)
            ->filter(fn ($shipment) => $shipment instanceof Shipment && $shipment->source_type === 'order')
            ->unique('id');
        if ($shipments->isEmpty()) {
            return;
        }

        $orders = Order::query()
            ->with(['items.product' => fn ($products) => $products
                ->where('shop_owner_id', $shopOwnerId)
                ->select('id', 'brand')])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('id', $shipments->pluck('source_id'))
            ->get()
            ->keyBy('id');

        $shipments->each(function (Shipment $shipment) use ($orders): void {
            $order = $orders->get($shipment->source_id);
            $items = $order?->items ?? collect();

            $shipment->setAttribute('order_summary', [
                'available' => (bool) $order,
                'order_id' => (int) $shipment->source_id,
                'order_number' => $order?->order_number,
                'items' => $items->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'brand' => $item->product?->brand,
                    'model' => $item->product_name ?: 'Product',
                    'image' => $item->product_image,
                    'color' => $item->color,
                    'size' => $item->size,
                    'quantity' => (int) $item->quantity,
                ])->values()->all(),
                'total_quantity' => (int) $items->sum('quantity'),
                'variant_count' => $items->count(),
                'model_count' => $items->pluck('product_name')
                    ->map(fn ($name) => mb_strtolower(trim((string) $name)))
                    ->filter()
                    ->unique()
                    ->count(),
            ]);
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
