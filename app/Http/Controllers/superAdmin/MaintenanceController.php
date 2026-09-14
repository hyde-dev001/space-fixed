<?php

namespace App\Http\Controllers\superAdmin;

use App\Enums\MaintenanceStatus;
use App\Exceptions\MaintenanceConflictException;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceWindow;
use App\Models\SuperAdmin;
use App\Services\MaintenanceLifecycleService;
use App\Services\MaintenanceStateService;
use App\Support\PrivilegedFailureResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MaintenanceController extends Controller
{
    private const STATUS_VALUES = [
        'draft',
        'scheduled',
        'active',
        'ended',
        'cancelled',
    ];

    public function __construct(
        private readonly MaintenanceLifecycleService $lifecycle,
        private readonly MaintenanceStateService $stateService,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function index(Request $request): Response
    {
        $now = now()->utc();
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in(self::STATUS_VALUES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MaintenanceWindow::query();
        $query->when($filters['status'] ?? null, fn (Builder $builder, string $status): Builder => $builder->where('status', $status));
        $query->when(($filters['search'] ?? null) !== null && $filters['search'] !== '', function (Builder $builder) use ($filters): void {
            $search = addcslashes((string) $filters['search'], "\\%_");
            $builder->where(function (Builder $searchQuery) use ($search): void {
                foreach (['title', 'public_message', 'internal_note', 'public_update_message'] as $column) {
                    $searchQuery->orWhereRaw("{$column} LIKE ? ESCAPE '\\\\'", ["%{$search}%"]);
                }
            });
        });

        $dateTimezone = config('app.shop_timezone', 'Asia/Manila');
        if (! empty($filters['date_from'])) {
            $query->where('starts_at', '>=', Carbon::createFromFormat('Y-m-d', $filters['date_from'], $dateTimezone)->startOfDay()->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where('starts_at', '<=', Carbon::createFromFormat('Y-m-d', $filters['date_to'], $dateTimezone)->endOfDay()->utc());
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $allWindows = MaintenanceWindow::query()->get();
        $current = $this->adminSummary($allWindows, $now);
        $upcoming = $allWindows
            ->filter(fn (MaintenanceWindow $window): bool => $window->starts_at?->gt($now) === true
                && $window->status === MaintenanceStatus::Scheduled
                && (! $current || $window->id !== $current['id']))
            ->sortBy('starts_at')
            ->first();

        $statusCounts = array_fill_keys(self::STATUS_VALUES, 0);
        foreach ($allWindows as $window) {
            $state = $window->status instanceof MaintenanceStatus ? $window->status->value : (string) $window->status;
            if (array_key_exists($state, $statusCounts)) {
                $statusCounts[$state]++;
            }
        }

        return Inertia::render('superAdmin/Maintenance/Index', [
            'current' => $current,
            'upcoming' => $upcoming ? $this->serializeWindow($upcoming, $now) : null,
            'history' => $paginator->getCollection()->map(fn (MaintenanceWindow $window): array => $this->serializeWindow($window, $now))->values()->all(),
            'filters' => array_merge([
                'status' => '',
                'search' => '',
                'date_from' => '',
                'date_to' => '',
                'per_page' => 25,
            ], $filters),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'status_counts' => $statusCounts,
            'can_manage' => $this->actor($request)->hasCapability(SuperAdmin::CAP_MANAGE_PLATFORM_MAINTENANCE),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->draftRules());

        return $this->perform($request, 'create', fn (SuperAdmin $actor) => $this->lifecycle->createDraft($actor, $validated, $request));
    }

    public function schedule(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        $validated = $request->validate($this->timingRules());

        return $this->perform($request, 'schedule', fn (SuperAdmin $actor) => $this->lifecycle->schedule(
            $actor,
            $maintenanceWindow,
            $this->carbon($validated['starts_at']),
            $this->carbon($validated['ends_at']),
            (int) $validated['version'],
            $request,
        ));
    }

    public function update(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        $validated = $request->validate($this->updateRules());
        $version = (int) $validated['version'];
        unset($validated['version']);

        return $this->perform($request, 'update', fn (SuperAdmin $actor) => $this->lifecycle->update($actor, $maintenanceWindow, $validated, $version, $request));
    }

    public function cancel(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        return $this->perform($request, 'cancel', fn (SuperAdmin $actor) => $this->lifecycle->cancel($actor, $maintenanceWindow, (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'], $request));
    }

    public function start(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        return $this->perform($request, 'start', fn (SuperAdmin $actor) => $this->lifecycle->start($actor, $maintenanceWindow, (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'], $request));
    }

    public function startNow(Request $request)
    {
        $validated = $request->validate(array_merge($this->draftRules(), [
            'ends_at' => ['required', 'date'],
        ]));

        return $this->perform($request, 'start_now', fn (SuperAdmin $actor) => $this->lifecycle->startNow($actor, $validated, $request));
    }

    public function extend(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        $validated = $request->validate([
            'ends_at' => ['required', 'date'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->perform($request, 'extend', fn (SuperAdmin $actor) => $this->lifecycle->extend(
            $actor,
            $maintenanceWindow,
            $this->carbon($validated['ends_at']),
            (int) $validated['version'],
            $request,
        ));
    }

    public function progress(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        $validated = $request->validate([
            'progress_stage' => ['required', 'string', Rule::in(['Maintenance Starting', 'Maintenance in Progress', 'Final Checks'])],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->perform($request, 'progress', fn (SuperAdmin $actor) => $this->lifecycle->updateProgress(
            $actor,
            $maintenanceWindow,
            $validated['progress_stage'],
            (int) $validated['version'],
            $request,
        ));
    }

    public function publicUpdate(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        $validated = $request->validate([
            'public_update_message' => ['nullable', 'string', 'max:5000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->perform($request, 'public_update', fn (SuperAdmin $actor) => $this->lifecycle->publishUpdate(
            $actor,
            $maintenanceWindow,
            $validated['public_update_message'] ?? null,
            (int) $validated['version'],
            $request,
        ));
    }

    public function end(Request $request, MaintenanceWindow $maintenanceWindow)
    {
        return $this->perform($request, 'end', fn (SuperAdmin $actor) => $this->lifecycle->end($actor, $maintenanceWindow, (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'], $request));
    }

    /** @param callable(SuperAdmin): MaintenanceWindow $callback */
    private function perform(Request $request, string $operation, callable $callback)
    {
        try {
            $window = $callback($this->actor($request));

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'window' => $this->serializeWindow($window, now()->utc()),
                ]);
            }

            return redirect()->route('admin.maintenance.index')->with('success', 'Maintenance '.$operation.' completed successfully.');
        } catch (MaintenanceConflictException $exception) {
            return $this->failures->conflict(
                request: $request,
                operation: 'platform_maintenance_'.$operation,
                message: $this->conflictMessage($exception->reason),
                code: 'platform_maintenance_'.$exception->reason,
                forceJson: $request->expectsJson(),
            );
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'platform_maintenance_'.$operation,
                exception: $exception,
                message: 'The maintenance operation could not be completed.',
                code: 'platform_maintenance_error',
                forceJson: $request->expectsJson(),
            );
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function draftRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'public_message' => ['required', 'string', 'max:5000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'notify_before_minutes' => ['sometimes', 'integer', Rule::in([5, 10, 15, 30, 60])],
            'transaction_freeze_minutes' => ['sometimes', 'nullable', 'integer', Rule::in([1, 2, 3, 5])],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function timingRules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function updateRules(): array
    {
        return array_merge($this->draftRules(), [
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
    }

    /** @param iterable<MaintenanceWindow> $windows */
    private function adminSummary(iterable $windows, CarbonInterface $now): ?array
    {
        $windows = collect($windows);
        $active = $windows
            ->filter(fn (MaintenanceWindow $window): bool => $this->stateService->effectiveState($window, $now) === MaintenanceStatus::Active->value)
            ->sortBy('starts_at')
            ->first();
        $warned = $windows
            ->filter(fn (MaintenanceWindow $window): bool => $this->stateService->effectiveState($window, $now) === MaintenanceStatus::Scheduled->value
                && $window->starts_at?->copy()->subMinutes((int) ($window->notify_before_minutes ?? 15))->lte($now))
            ->sortBy('starts_at')
            ->first();
        $future = $windows
            ->filter(fn (MaintenanceWindow $window): bool => $this->stateService->effectiveState($window, $now) === MaintenanceStatus::Scheduled->value)
            ->sortBy('starts_at')
            ->first();

        $selected = $active ?? $warned ?? $future;

        return $selected ? $this->serializeWindow($selected, $now) : null;
    }

    /** @return array<string, mixed> */
    private function serializeWindow(MaintenanceWindow $window, CarbonInterface $now): array
    {
        return [
            'id' => (int) $window->id,
            'title' => (string) $window->title,
            'public_message' => (string) $window->public_message,
            'internal_note' => $window->internal_note,
            'status' => $window->status instanceof MaintenanceStatus ? $window->status->value : (string) $window->status,
            'state' => $this->stateService->effectiveState($window, $now),
            'starts_at' => $window->starts_at?->copy()->utc()->toISOString(),
            'ends_at' => $window->ends_at?->copy()->utc()->toISOString(),
            'activated_at' => $window->activated_at?->copy()->utc()->toISOString(),
            'ended_at' => $window->ended_at?->copy()->utc()->toISOString(),
            'cancelled_at' => $window->cancelled_at?->copy()->utc()->toISOString(),
            'notify_before_minutes' => (int) $window->notify_before_minutes,
            'transaction_freeze_minutes' => $window->transaction_freeze_minutes === null ? null : (int) $window->transaction_freeze_minutes,
            'progress_stage' => $window->progress_stage,
            'public_update_message' => $window->public_update_message,
            'public_update_updated_at' => $window->public_update_updated_at?->copy()->utc()->toISOString(),
            'version' => (int) $window->version,
        ];
    }

    private function actor(Request $request): SuperAdmin
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 401);

        return $actor;
    }

    private function carbon(mixed $value): Carbon
    {
        return Carbon::parse((string) $value);
    }

    private function conflictMessage(string $reason): string
    {
        return match ($reason) {
            'overlap' => 'This maintenance window overlaps another scheduled or active window.',
            'stale_version' => 'This maintenance window changed. Refresh the page before trying again.',
            'invalid_timing' => 'The maintenance start and end times are invalid or no longer allowed.',
            'invalid_state' => 'This maintenance command is not available in the window’s current effective state.',
            'invalid_warning' => 'The warning interval is not supported.',
            'invalid_freeze' => 'The transaction freeze interval must be supported and no longer than the warning interval.',
            'invalid_progress' => 'The progress stage is not supported.',
            'lock_timeout' => 'Another maintenance command is in progress. Please try again.',
            default => 'This maintenance operation conflicts with current state.',
        };
    }
}
