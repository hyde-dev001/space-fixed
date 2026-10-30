<?php

namespace App\Console\Commands;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceWindow;
use App\Services\MaintenanceCommandLock;
use App\Services\MaintenanceStateService;
use App\Services\PrivilegedAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReconcilePlatformMaintenance extends Command
{
    protected $signature = 'maintenance:reconcile';

    protected $description = 'Reconcile persisted platform maintenance transitions';

    public function __construct(
        private readonly MaintenanceCommandLock $commandLock,
        private readonly MaintenanceStateService $stateService,
        private readonly PrivilegedAudit $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now()->copy()->utc();
        $correlationId = (string) Str::uuid();

        try {
            [$activated, $ended] = $this->commandLock->run(function () use ($now, $correlationId): array {
                return DB::transaction(function () use ($now, $correlationId): array {
                    $windows = MaintenanceWindow::query()
                        ->whereIn('status', [MaintenanceStatus::Scheduled->value, MaintenanceStatus::Active->value])
                        ->whereNotNull('starts_at')
                        ->whereNotNull('ends_at')
                        ->where(function ($query) use ($now): void {
                            $query
                                ->where('starts_at', '<=', $now)
                                ->orWhere('ends_at', '<=', $now);
                        })
                        ->orderBy('starts_at')
                        ->lockForUpdate()
                        ->get();

                    $activated = 0;
                    $ended = 0;

                    foreach ($windows as $window) {
                        if ($window->status === MaintenanceStatus::Scheduled && $window->starts_at?->lte($now)) {
                            $before = $this->safeState($window);
                            $window->status = MaintenanceStatus::Active;
                            $window->activated_at = $window->starts_at->copy()->utc();
                            $window->activated_by = null;
                            $window->version = ((int) $window->version) + 1;
                            $window->save();
                            $this->audit->platformMaintenanceReconciled(
                                $window,
                                'platform_maintenance_activated',
                                $correlationId,
                                $before,
                                $this->safeState($window),
                            );
                            $activated++;
                        }

                        if ($window->status === MaintenanceStatus::Active && $window->ends_at?->lte($now)) {
                            $before = $this->safeState($window);
                            $window->status = MaintenanceStatus::Ended;
                            $window->ended_at = $window->ends_at->copy()->utc();
                            $window->ended_by = null;
                            $window->version = ((int) $window->version) + 1;
                            $window->save();
                            $this->audit->platformMaintenanceReconciled(
                                $window,
                                'platform_maintenance_ended',
                                $correlationId,
                                $before,
                                $this->safeState($window),
                            );
                            $ended++;
                        }
                    }

                    return [$activated, $ended];
                });
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Platform maintenance reconciliation failed.');

            return self::FAILURE;
        }

        if ($activated > 0 || $ended > 0) {
            $this->stateService->forget();
        }

        $this->info(sprintf('Reconciled maintenance windows: activated=%d ended=%d', $activated, $ended));

        return self::SUCCESS;
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
        ];
    }
}
