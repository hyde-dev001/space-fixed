<?php

namespace App\Services;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceWindow;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

class MaintenanceStateService
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function effectiveState(MaintenanceWindow $window, CarbonInterface $now): string
    {
        $status = $window->status instanceof MaintenanceStatus
            ? $window->status
            : MaintenanceStatus::from((string) $window->status);

        if ($status->isTerminal()) {
            return $status->value;
        }

        if ($status === MaintenanceStatus::Draft) {
            return MaintenanceStatus::Draft->value;
        }

        if ($window->starts_at === null || $window->ends_at === null) {
            return $status->value;
        }

        if ($now->lt($window->starts_at)) {
            return MaintenanceStatus::Scheduled->value;
        }

        if ($now->lt($window->ends_at)) {
            return MaintenanceStatus::Active->value;
        }

        return MaintenanceStatus::Ended->value;
    }

    /** @return array<string, mixed> */
    public function resolve(CarbonInterface $now): array
    {
        $cached = null;

        try {
            $cached = $this->cache->get($this->cacheKey());
            if (is_array($cached) && $this->snapshotIsUsable($cached, $now)) {
                return $cached;
            }

            $snapshot = $this->resolveFromDatabase($now);
            $this->cache->put($this->cacheKey(), $snapshot, Carbon::parse($snapshot['expires_at']));

            return $snapshot;
        } catch (Throwable $exception) {
            if (is_array($cached)
                && $this->snapshotIsUsable($cached, $now)
                && $this->snapshotIsRestrictive($cached, $now)) {
                return $cached;
            }

            throw new \RuntimeException('Maintenance state is unavailable.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function publicProjection(array $snapshot, CarbonInterface $now): array
    {
        $projection = [
            'state' => (string) ($snapshot['state'] ?? 'operational'),
            'server_time' => $now->copy()->utc()->toISOString(),
        ];

        if (($snapshot['window_id'] ?? null) === null || $projection['state'] === 'operational') {
            return $projection;
        }

        return array_merge($projection, [
            'id' => (int) $snapshot['window_id'],
            'title' => (string) ($snapshot['title'] ?? ''),
            'message' => (string) ($snapshot['public_message'] ?? ''),
            'progress_stage' => $snapshot['progress_stage'] ?? null,
            'update_message' => $snapshot['public_update_message'] ?? null,
            'update_message_updated_at' => $snapshot['public_update_updated_at'] ?? null,
            'starts_at' => $snapshot['starts_at'] ?? null,
            'ends_at' => $snapshot['ends_at'] ?? null,
            'notify_before_minutes' => (int) ($snapshot['notify_before_minutes'] ?? 0),
            'transaction_freeze_minutes' => $snapshot['transaction_freeze_minutes'] === null
                ? null
                : (int) $snapshot['transaction_freeze_minutes'],
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public function isFreezeActive(array $snapshot, CarbonInterface $now): bool
    {
        $window = $snapshot['enforcement_window'] ?? null;
        if (!is_array($window) || ($window['status'] ?? null) !== MaintenanceStatus::Scheduled->value) {
            return false;
        }

        $startsAt = Carbon::parse((string) ($window['starts_at'] ?? ''));
        $freezeMinutes = (int) ($window['transaction_freeze_minutes'] ?? 0);

        return $freezeMinutes > 0
            && $now->gte($startsAt->copy()->subMinutes($freezeMinutes))
            && $now->lt($startsAt);
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /** @return array<string, mixed> */
    private function resolveFromDatabase(CarbonInterface $now): array
    {
        $windows = MaintenanceWindow::query()
            ->whereIn('status', [MaintenanceStatus::Scheduled->value, MaintenanceStatus::Active->value])
            ->orderBy('starts_at')
            ->get();

        $effectiveActive = $windows
            ->first(fn (MaintenanceWindow $window): bool => $this->effectiveState($window, $now) === MaintenanceStatus::Active->value);

        $warnedScheduled = $windows
            ->filter(fn (MaintenanceWindow $window): bool => $this->isWarnedScheduled($window, $now))
            ->sortBy('starts_at')
            ->first();

        $nearestScheduled = $windows
            ->filter(fn (MaintenanceWindow $window): bool => $this->effectiveState($window, $now) === MaintenanceStatus::Scheduled->value)
            ->sortBy('starts_at')
            ->first();

        $publicWindow = $effectiveActive ?? $warnedScheduled;
        $enforcementWindow = $effectiveActive ?? $nearestScheduled;
        $nextTransition = $this->nextTransition($windows, $now);
        $expiresAt = $nextTransition?->lt($now->copy()->addSeconds((int) config('platform_maintenance.max_cache_seconds', 30)))
            ? $nextTransition
            : $now->copy()->addSeconds((int) config('platform_maintenance.max_cache_seconds', 30));

        return array_merge(
            $this->windowSnapshot($publicWindow),
            [
                'state' => $publicWindow instanceof MaintenanceWindow
                    ? ($effectiveActive ? MaintenanceStatus::Active->value : MaintenanceStatus::Scheduled->value)
                    : 'operational',
                'enforcement_window' => $this->enforcementWindowSnapshot($enforcementWindow),
                'next_transition_at' => $nextTransition?->copy()->utc()->toISOString(),
                'resolved_at' => $now->copy()->utc()->toISOString(),
                'expires_at' => $expiresAt->copy()->utc()->toISOString(),
            ],
        );
    }

    private function isWarnedScheduled(MaintenanceWindow $window, CarbonInterface $now): bool
    {
        if ($this->effectiveState($window, $now) !== MaintenanceStatus::Scheduled->value || $window->starts_at === null) {
            return false;
        }

        $minutes = $window->notify_before_minutes ?? (int) config('platform_maintenance.notify_before_minutes', 15);

        return $now->gte($window->starts_at->copy()->subMinutes($minutes));
    }

    /** @param iterable<MaintenanceWindow> $windows */
    private function nextTransition(iterable $windows, CarbonInterface $now): ?CarbonInterface
    {
        $boundaries = [];

        foreach ($windows as $window) {
            $state = $this->effectiveState($window, $now);
            if ($state === MaintenanceStatus::Active->value && $window->ends_at?->gt($now)) {
                $boundaries[] = $window->ends_at;
                continue;
            }

            if ($state !== MaintenanceStatus::Scheduled->value || $window->starts_at === null) {
                continue;
            }

            $warningAt = $window->starts_at->copy()->subMinutes(
                $window->notify_before_minutes ?? (int) config('platform_maintenance.notify_before_minutes', 15),
            );
            $freezeMinutes = $window->transaction_freeze_minutes ?? 0;
            $freezeAt = $freezeMinutes > 0
                ? $window->starts_at->copy()->subMinutes($freezeMinutes)
                : null;

            foreach ([$warningAt, $freezeAt, $window->starts_at] as $boundary) {
                if ($boundary instanceof CarbonInterface && $boundary->gt($now)) {
                    $boundaries[] = $boundary;
                }
            }
        }

        if ($boundaries === []) {
            return null;
        }

        usort($boundaries, static fn (CarbonInterface $left, CarbonInterface $right): int => $left->getTimestamp() <=> $right->getTimestamp());

        return $boundaries[0];
    }

    /** @return array<string, mixed> */
    private function windowSnapshot(?MaintenanceWindow $window): array
    {
        if (!$window) {
            return [
                'window_id' => null,
                'version' => null,
                'title' => null,
                'public_message' => null,
                'starts_at' => null,
                'ends_at' => null,
                'notify_before_minutes' => null,
                'transaction_freeze_minutes' => null,
                'progress_stage' => null,
                'public_update_message' => null,
                'public_update_updated_at' => null,
            ];
        }

        return [
            'window_id' => $window->id,
            'version' => $window->version,
            'title' => $window->title,
            'public_message' => $window->public_message,
            'starts_at' => $window->starts_at?->copy()->utc()->toISOString(),
            'ends_at' => $window->ends_at?->copy()->utc()->toISOString(),
            'notify_before_minutes' => $window->notify_before_minutes,
            'transaction_freeze_minutes' => $window->transaction_freeze_minutes,
            'progress_stage' => $window->progress_stage,
            'public_update_message' => $window->public_update_message,
            'public_update_updated_at' => $window->public_update_updated_at?->copy()->utc()->toISOString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function enforcementWindowSnapshot(?MaintenanceWindow $window): ?array
    {
        if (!$window) {
            return null;
        }

        return [
            'id' => $window->id,
            'status' => $window->status instanceof MaintenanceStatus ? $window->status->value : (string) $window->status,
            'starts_at' => $window->starts_at?->copy()->utc()->toISOString(),
            'ends_at' => $window->ends_at?->copy()->utc()->toISOString(),
            'transaction_freeze_minutes' => $window->transaction_freeze_minutes,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotIsUsable(array $snapshot, CarbonInterface $now): bool
    {
        if (!isset($snapshot['expires_at'])) {
            return false;
        }

        return $now->lt(Carbon::parse((string) $snapshot['expires_at']));
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotIsRestrictive(array $snapshot, CarbonInterface $now): bool
    {
        return ($snapshot['state'] ?? 'operational') === MaintenanceStatus::Active->value
            || $this->isFreezeActive($snapshot, $now);
    }

    private function cacheKey(): string
    {
        return (string) config('platform_maintenance.cache_key', 'platform_maintenance.snapshot');
    }
}
