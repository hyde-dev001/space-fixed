<?php

namespace App\Services;

use App\Enums\MaintenanceStatus;
use App\Exceptions\MaintenanceConflictException;
use App\Models\MaintenanceWindow;
use App\Models\SuperAdmin;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceLifecycleService
{
    private const NOTIFY_CHOICES = [5, 10, 15, 30, 60];

    private const FREEZE_CHOICES = [1, 2, 3, 5];

    private const PROGRESS_CHOICES = [
        'Maintenance Starting',
        'Maintenance in Progress',
        'Final Checks',
    ];

    public function __construct(
        private readonly MaintenanceCommandLock $commandLock,
        private readonly MaintenanceStateService $stateService,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(SuperAdmin $actor, array $attributes, ?Request $request = null): MaintenanceWindow
    {
        $request ??= $this->maintenanceRequest();

        [$window, $changed] = $this->commandLock->run(function () use ($actor, $attributes, $request): array {
            return DB::transaction(function () use ($actor, $attributes, $request): array {
                $values = $this->validatedAttributes($attributes);
                $window = MaintenanceWindow::query()->create(array_merge($values, [
                    'status' => MaintenanceStatus::Draft,
                    'version' => 1,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]));
                $this->audit->platformMaintenanceChanged($request, $actor, $window, 'platform_maintenance_created', [], $this->safeState($window));

                return [$window, true];
            });
        });

        if ($changed) {
            $this->stateService->forget();
        }

        return $window;
    }

    /** @param array<string, mixed> $attributes */
    public function createScheduled(
        SuperAdmin $actor,
        array $attributes,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?Request $request = null,
    ): MaintenanceWindow {
        $request ??= $this->maintenanceRequest();
        $now = now();

        [$window, $changed] = $this->commandLock->run(function () use ($actor, $attributes, $startsAt, $endsAt, $request, $now): array {
            return DB::transaction(function () use ($actor, $attributes, $startsAt, $endsAt, $request, $now): array {
                $values = $this->validatedAttributes($attributes);
                $this->ensureTiming($startsAt, $endsAt, $now, true);
                $window = MaintenanceWindow::query()->create(array_merge($values, [
                    'status' => MaintenanceStatus::Scheduled,
                    'starts_at' => $startsAt->copy()->utc(),
                    'ends_at' => $endsAt->copy()->utc(),
                    'version' => 1,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]));
                $this->ensureNoOverlap($window, $now);
                $this->audit->platformMaintenanceChanged($request, $actor, $window, 'platform_maintenance_created', [], $this->safeState($window));
                $this->audit->platformMaintenanceChanged($request, $actor, $window, 'platform_maintenance_scheduled', [], $this->safeState($window));

                return [$window, true];
            });
        });

        if ($changed) {
            $this->stateService->forget();
        }

        return $window;
    }

    public function schedule(
        SuperAdmin $actor,
        MaintenanceWindow $window,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $expectedVersion,
        ?Request $request = null,
    ): MaintenanceWindow {
        return $this->mutate($actor, $window, $expectedVersion, $request, 'platform_maintenance_scheduled', function (MaintenanceWindow $locked, CarbonInterface $now) use ($startsAt, $endsAt): void {
            $this->ensureState($locked, $now, [MaintenanceStatus::Draft]);
            $this->ensureTiming($startsAt, $endsAt, $now, true);
            $locked->fill([
                'status' => MaintenanceStatus::Scheduled,
                'starts_at' => $startsAt->copy()->utc(),
                'ends_at' => $endsAt->copy()->utc(),
            ]);
            $this->ensureNoOverlap($locked, $now);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        SuperAdmin $actor,
        MaintenanceWindow $window,
        array $attributes,
        int $expectedVersion,
        ?Request $request = null,
    ): MaintenanceWindow {
        return $this->mutate($actor, $window, $expectedVersion, $request, 'platform_maintenance_updated', function (MaintenanceWindow $locked, CarbonInterface $now) use ($attributes): void {
            $this->ensureState($locked, $now, [MaintenanceStatus::Draft, MaintenanceStatus::Scheduled]);
            $values = $this->validatedAttributes($attributes);
            $startsAt = array_key_exists('starts_at', $values) ? $values['starts_at'] : $locked->starts_at;
            $endsAt = array_key_exists('ends_at', $values) ? $values['ends_at'] : $locked->ends_at;

            if ($locked->status === MaintenanceStatus::Scheduled) {
                $this->ensureTiming($startsAt, $endsAt, $now, true);
            } elseif ($startsAt !== null || $endsAt !== null) {
                $this->ensureTiming($startsAt, $endsAt, $now, false);
            }

            $locked->fill($values);
            if ($locked->status === MaintenanceStatus::Scheduled) {
                $this->ensureNoOverlap($locked, $now);
            }
        });
    }

    public function cancel(SuperAdmin $actor, MaintenanceWindow $window, int $expectedVersion, ?Request $request = null): MaintenanceWindow
    {
        return $this->mutate($actor, $window, $expectedVersion, $request, 'platform_maintenance_cancelled', function (MaintenanceWindow $locked, CarbonInterface $now) use ($actor): void {
            $this->ensureState($locked, $now, [MaintenanceStatus::Scheduled]);
            $locked->fill([
                'status' => MaintenanceStatus::Cancelled,
                'cancelled_at' => $now->copy()->utc(),
                'cancelled_by' => $actor->getKey(),
            ]);
        });
    }

    public function start(SuperAdmin $actor, MaintenanceWindow $window, int $expectedVersion, ?Request $request = null): MaintenanceWindow
    {
        $request ??= $this->maintenanceRequest();
        $now = now();
        [$result, $changed] = $this->commandLock->run(function () use ($actor, $window, $expectedVersion, $request, $now): array {
            return DB::transaction(function () use ($actor, $window, $expectedVersion, $request, $now): array {
                $locked = $this->lockTarget($window);
                $this->ensureVersion($locked, $expectedVersion);
                $before = $this->safeState($locked);
                $state = $this->stateService->effectiveState($locked, $now);
                if ($state === MaintenanceStatus::Active->value && $locked->status === MaintenanceStatus::Active) {
                    return [$locked, false];
                }
                $this->ensureState($locked, $now, [MaintenanceStatus::Scheduled]);
                $startsAt = $now->copy()->utc();
                $this->ensureTiming($startsAt, $locked->ends_at, $now, false);
                $locked->fill([
                    'status' => MaintenanceStatus::Active,
                    'starts_at' => $startsAt,
                    'activated_at' => $startsAt,
                    'activated_by' => $actor->getKey(),
                ]);
                $this->ensureNoOverlap($locked, $now);
                $this->saveWithAudit($locked, $actor, $request, 'platform_maintenance_activated', $before);

                return [$locked, true];
            });
        });

        if ($changed) {
            $this->stateService->forget();
        }

        return $result;
    }

    /** @param array<string, mixed> $attributes */
    public function startNow(SuperAdmin $actor, array $attributes, ?Request $request = null): MaintenanceWindow
    {
        $request ??= $this->maintenanceRequest();
        $now = now();

        [$window, $changed] = $this->commandLock->run(function () use ($actor, $attributes, $request, $now): array {
            return DB::transaction(function () use ($actor, $attributes, $request, $now): array {
                $values = $this->validatedAttributes($attributes);
                $endsAt = $values['ends_at'] ?? null;
                $this->ensureTiming($now, $endsAt, $now, false);
                $window = MaintenanceWindow::query()->create(array_merge($values, [
                    'status' => MaintenanceStatus::Active,
                    'starts_at' => $now->copy()->utc(),
                    'ends_at' => $endsAt->copy()->utc(),
                    'activated_at' => $now->copy()->utc(),
                    'version' => 1,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                    'activated_by' => $actor->getKey(),
                ]));
                $this->ensureNoOverlap($window, $now);
                $this->audit->platformMaintenanceChanged($request, $actor, $window, 'platform_maintenance_activated', [], $this->safeState($window));

                return [$window, true];
            });
        });

        if ($changed) {
            $this->stateService->forget();
        }

        return $window;
    }

    public function extend(SuperAdmin $actor, MaintenanceWindow $window, CarbonInterface $endsAt, int $expectedVersion, ?Request $request = null): MaintenanceWindow
    {
        return $this->mutate($actor, $window, $expectedVersion, $request, 'platform_maintenance_extended', function (MaintenanceWindow $locked, CarbonInterface $now) use ($endsAt): void {
            $this->ensureState($locked, $now, [MaintenanceStatus::Active]);
            if ($locked->ends_at !== null && !$endsAt->gt($locked->ends_at)) {
                throw new MaintenanceConflictException('invalid_timing');
            }
            $this->ensureTiming($locked->starts_at, $endsAt, $now, false);
            $locked->ends_at = $endsAt->copy()->utc();
            $this->ensureNoOverlap($locked, $now);
        });
    }

    public function updateProgress(SuperAdmin $actor, MaintenanceWindow $window, string $progressStage, int $expectedVersion, ?Request $request = null): MaintenanceWindow
    {
        if (!in_array($progressStage, self::PROGRESS_CHOICES, true)) {
            throw new MaintenanceConflictException('invalid_progress');
        }

        return $this->mutate($actor, $window, $expectedVersion, $request, 'platform_maintenance_progress_updated', function (MaintenanceWindow $locked, CarbonInterface $now) use ($progressStage): void {
            $this->ensureState($locked, $now, [MaintenanceStatus::Active]);
            $locked->progress_stage = $progressStage;
        });
    }

    public function publishUpdate(SuperAdmin $actor, MaintenanceWindow $window, ?string $message, int $expectedVersion, ?Request $request = null): MaintenanceWindow
    {
        return $this->mutate($actor, $window, $expectedVersion, $request, 'platform_maintenance_public_update_changed', function (MaintenanceWindow $locked, CarbonInterface $now) use ($message): void {
            $this->ensureState($locked, $now, [MaintenanceStatus::Scheduled, MaintenanceStatus::Active]);
            $locked->public_update_message = $message;
            $locked->public_update_updated_at = $message === null ? null : $now->copy()->utc();
        });
    }

    public function end(SuperAdmin $actor, MaintenanceWindow $window, int $expectedVersion, ?Request $request = null): MaintenanceWindow
    {
        $request ??= $this->maintenanceRequest();
        $now = now();
        [$result, $changed] = $this->commandLock->run(function () use ($actor, $window, $expectedVersion, $request, $now): array {
            return DB::transaction(function () use ($actor, $window, $expectedVersion, $request, $now): array {
                $locked = $this->lockTarget($window);
                $this->ensureVersion($locked, $expectedVersion);
                $state = $this->stateService->effectiveState($locked, $now);
                if ($state === MaintenanceStatus::Ended->value && $locked->status === MaintenanceStatus::Ended) {
                    return [$locked, false];
                }
                $this->ensureState($locked, $now, [MaintenanceStatus::Active]);
                $locked->fill([
                    'status' => MaintenanceStatus::Ended,
                    'ended_at' => $now->copy()->utc(),
                    'ended_by' => $actor->getKey(),
                ]);
                $this->saveWithAudit($locked, $actor, $request, 'platform_maintenance_ended');

                return [$locked, true];
            });
        });

        if ($changed) {
            $this->stateService->forget();
        }

        return $result;
    }

    /** @param Closure(MaintenanceWindow, CarbonInterface): void $change */
    private function mutate(
        SuperAdmin $actor,
        MaintenanceWindow $window,
        int $expectedVersion,
        ?Request $request,
        string $event,
        Closure $change,
    ): MaintenanceWindow {
        $request ??= $this->maintenanceRequest();
        $now = now();
        [$result, $changed] = $this->commandLock->run(function () use ($actor, $window, $expectedVersion, $request, $event, $change, $now): array {
            return DB::transaction(function () use ($actor, $window, $expectedVersion, $request, $event, $change, $now): array {
                $locked = $this->lockTarget($window);
                $this->ensureVersion($locked, $expectedVersion);
                $before = $this->safeState($locked);
                $change($locked, $now);
                $this->saveWithAudit($locked, $actor, $request, $event, $before);

                return [$locked, true];
            });
        });

        if ($changed) {
            $this->stateService->forget();
        }

        return $result;
    }

    private function saveWithAudit(
        MaintenanceWindow $window,
        SuperAdmin $actor,
        Request $request,
        string $event,
        ?array $before = null,
    ): void {
        $window->version = ((int) $window->version) + 1;
        $window->updated_by = $actor->getKey();
        $window->save();
        $this->audit->platformMaintenanceChanged(
            $request,
            $actor,
            $window,
            $event,
            $before ?? [],
            $this->safeState($window),
        );
    }

    /** @return array<string, mixed> */
    private function validatedAttributes(array $attributes): array
    {
        $values = [];
        foreach (['title', 'public_message', 'internal_note', 'progress_stage', 'public_update_message'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $values[$field] = $attributes[$field];
            }
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $values[$field] = $attributes[$field] === null ? null : $this->asCarbon($attributes[$field]);
            }
        }

        if (array_key_exists('notify_before_minutes', $attributes)) {
            $notify = (int) $attributes['notify_before_minutes'];
            if (!in_array($notify, self::NOTIFY_CHOICES, true)) {
                throw new MaintenanceConflictException('invalid_warning');
            }
            $values['notify_before_minutes'] = $notify;
        }

        if (array_key_exists('transaction_freeze_minutes', $attributes)) {
            $freeze = $attributes['transaction_freeze_minutes'] === null || $attributes['transaction_freeze_minutes'] === ''
                ? null
                : (int) $attributes['transaction_freeze_minutes'];
            if ($freeze !== null && !in_array($freeze, self::FREEZE_CHOICES, true)) {
                throw new MaintenanceConflictException('invalid_freeze');
            }
            $notify = $values['notify_before_minutes'] ?? (int) config('platform_maintenance.notify_before_minutes', 15);
            if ($freeze !== null && $freeze > $notify) {
                throw new MaintenanceConflictException('invalid_freeze');
            }
            $values['transaction_freeze_minutes'] = $freeze;
        }

        if (array_key_exists('progress_stage', $values)
            && $values['progress_stage'] !== null
            && !in_array($values['progress_stage'], self::PROGRESS_CHOICES, true)) {
            throw new MaintenanceConflictException('invalid_progress');
        }

        return $values;
    }

    private function ensureTiming(?CarbonInterface $startsAt, ?CarbonInterface $endsAt, CarbonInterface $now, bool $requiresFutureStart): void
    {
        if (!$startsAt || !$endsAt || !$startsAt->lt($endsAt)) {
            throw new MaintenanceConflictException('invalid_timing');
        }

        if ($requiresFutureStart && !$startsAt->gt($now)) {
            throw new MaintenanceConflictException('invalid_timing');
        }
    }

    private function ensureState(MaintenanceWindow $window, CarbonInterface $now, array $allowed): void
    {
        $state = $this->stateService->effectiveState($window, $now);
        $allowedValues = array_map(static fn (MaintenanceStatus $status): string => $status->value, $allowed);
        if (!in_array($state, $allowedValues, true)) {
            throw new MaintenanceConflictException('invalid_state');
        }
    }

    private function ensureVersion(MaintenanceWindow $window, int $expectedVersion): void
    {
        if ((int) $window->version !== $expectedVersion) {
            throw new MaintenanceConflictException('stale_version');
        }
    }

    private function ensureNoOverlap(MaintenanceWindow $target, CarbonInterface $now): void
    {
        if (!$target->starts_at || !$target->ends_at) {
            return;
        }

        $candidates = MaintenanceWindow::query()
            ->whereIn('status', [MaintenanceStatus::Scheduled->value, MaintenanceStatus::Active->value])
            ->when($target->exists, fn (Builder $query): Builder => $query->where(
                $target->getKeyName(),
                '!=',
                $target->getKey(),
            ))
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->stateService->effectiveState($candidate, $now) === MaintenanceStatus::Ended->value
                || !$candidate->starts_at
                || !$candidate->ends_at) {
                continue;
            }

            if ($target->starts_at->lt($candidate->ends_at) && $target->ends_at->gt($candidate->starts_at)) {
                throw new MaintenanceConflictException('overlap');
            }
        }
    }

    private function lockTarget(MaintenanceWindow $window): MaintenanceWindow
    {
        return MaintenanceWindow::query()->lockForUpdate()->findOrFail($window->getKey());
    }

    /** @return array<string, mixed> */
    private function safeState(MaintenanceWindow $window): array
    {
        return [
            'status' => $window->status instanceof MaintenanceStatus ? $window->status->value : (string) $window->status,
            'version' => (int) $window->version,
            'starts_at' => $window->starts_at?->copy()->utc()->toISOString(),
            'ends_at' => $window->ends_at?->copy()->utc()->toISOString(),
            'activated_at' => $window->activated_at?->copy()->utc()->toISOString(),
            'ended_at' => $window->ended_at?->copy()->utc()->toISOString(),
            'cancelled_at' => $window->cancelled_at?->copy()->utc()->toISOString(),
            'progress_stage' => $window->progress_stage,
            'public_update_message' => $window->public_update_message,
        ];
    }

    private function asCarbon(mixed $value): Carbon
    {
        return $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
    }

    private function maintenanceRequest(): Request
    {
        return Request::create('/admin/maintenance', 'POST');
    }
}
