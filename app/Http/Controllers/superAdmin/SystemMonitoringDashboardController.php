<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\PrivilegedAuditVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class SystemMonitoringDashboardController extends Controller
{
    public function __construct(private readonly PrivilegedAuditVisibility $auditVisibility)
    {
    }

    public function index(Request $request): Response
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $prevMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $totalUsers = User::query()->count();
        $totalAdmins = SuperAdmin::query()->count();
        $suspendedAdmins = SuperAdmin::query()->where('status', 'suspended')->count();

        $newUsersThisMonth = User::query()->whereBetween('created_at', [$monthStart, $now])->count();
        $newUsersPrevMonth = User::query()->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])->count();

        $newAdminsThisMonth = SuperAdmin::query()
            ->whereBetween('created_at', [$monthStart, $now])
            ->count();
        $newAdminsPrevMonth = SuperAdmin::query()
            ->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])
            ->count();

        $newSuspendedThisMonth = SuperAdmin::query()
            ->where('status', 'suspended')
            ->whereBetween('created_at', [$monthStart, $now])
            ->count();
        $newSuspendedPrevMonth = SuperAdmin::query()
            ->where('status', 'suspended')
            ->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])
            ->count();

        $databaseHealthy = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $databaseHealthy = false;
        }

        $failedJobsCount = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $viewer = $request->user('super_admin');
        $activityRows = $viewer instanceof SuperAdmin && Schema::hasTable('activity_log')
            ? $this->auditVisibility->visibleQuery($viewer)
                ->with(['causer', 'subject'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
            : collect();

        $recentActivity = $activityRows->map(function (Activity $row) use ($viewer) {
            $safe = $viewer instanceof SuperAdmin
                ? $this->auditVisibility->serialize($row, $viewer)
                : null;
            $created = $row->created_at ? Carbon::parse($row->created_at) : null;
            return [
                'activity' => $safe['event_label'] ?? 'System activity recorded',
                'time' => $created ? $created->diffForHumans() : 'just now',
                'status' => in_array($safe['event'] ?? null, [
                    'privileged_capability_denied',
                    'privileged_workflow_conflict',
                    'privileged_workflow_failed',
                ], true) ? 'Warning' : 'Info',
            ];
        })->all();

        if (empty($recentActivity)) {
            $recentActivity = [[
                'activity' => 'No recent system activity yet',
                'time' => 'just now',
                'status' => 'Info',
            ]];
        }

        return Inertia::render('superAdmin/SystemMonitoringDashboard', [
            'dashboard' => [
                'metrics' => [
                    'total_users' => $totalUsers,
                    'total_admins' => $totalAdmins,
                    'suspended_admins' => $suspendedAdmins,
                    'total_users_change' => $this->percentChange($newUsersThisMonth, $newUsersPrevMonth),
                    'total_admins_change' => $this->percentChange($newAdminsThisMonth, $newAdminsPrevMonth),
                    'suspended_admins_change' => $this->percentChange($newSuspendedThisMonth, $newSuspendedPrevMonth),
                ],
                'system_health' => [
                    [
                        'metric' => 'Database Connectivity',
                        'value' => $databaseHealthy ? 'Connected' : 'Disconnected',
                        'status' => $databaseHealthy ? 'Excellent' : 'Critical',
                    ],
                    [
                        'metric' => 'Queue Driver',
                        'value' => (string) config('queue.default', 'unknown'),
                        'status' => 'Info',
                    ],
                    [
                        'metric' => 'Failed Jobs',
                        'value' => (string) $failedJobsCount,
                        'status' => $failedJobsCount > 0 ? 'Warning' : 'Low',
                    ],
                ],
                'recent_activity' => $recentActivity,
                'performance_metrics' => [
                    [
                        'metric' => 'Total Admin Accounts',
                        'value' => (string) $totalAdmins,
                        'status' => 'Snapshot',
                    ],
                    [
                        'metric' => 'Suspended Admin Accounts',
                        'value' => (string) $suspendedAdmins,
                        'status' => 'Snapshot',
                    ],
                    [
                        'metric' => 'Failed Jobs',
                        'value' => (string) $failedJobsCount,
                        'status' => $failedJobsCount > 0 ? 'Warning' : 'Low',
                    ],
                ],
                'systems_operational' => $databaseHealthy,
            ],
        ]);
    }

    private function percentChange(int|float $current, int|float $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100.0 : 0.0;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
    }
}
