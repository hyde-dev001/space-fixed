<?php

namespace App\Http\Controllers\superAdmin;

use App\Enums\AdminPage;
use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\PrivilegedAuditVisibility;
use App\Services\AdminPageAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class SystemMonitoringDashboardController extends Controller
{
    public function __construct(
        private readonly PrivilegedAuditVisibility $auditVisibility,
        private readonly AdminPageAccessService $pageAccess,
    )
    {
    }

    public function index(Request $request): Response
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $prevMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $viewer = $request->user('super_admin');
        $isSuperAdmin = $viewer instanceof SuperAdmin && $viewer->role === SuperAdmin::ROLE_SUPER_ADMIN;
        $canViewUsers = $viewer instanceof SuperAdmin
            && ($isSuperAdmin || $this->pageAccess->allows($viewer, AdminPage::USER_MANAGEMENT));
        $canViewAudit = $viewer instanceof SuperAdmin
            && ($isSuperAdmin || $this->pageAccess->allows($viewer, AdminPage::AUDIT_HISTORY));

        $totalUsers = $canViewUsers ? User::query()->count() : 0;
        $totalAdmins = $isSuperAdmin ? SuperAdmin::query()->count() : 0;
        $suspendedAdmins = $isSuperAdmin ? SuperAdmin::query()->where('status', 'suspended')->count() : 0;

        $newUsersThisMonth = $canViewUsers
            ? User::query()->whereBetween('created_at', [$monthStart, $now])->count()
            : 0;
        $newUsersPrevMonth = $canViewUsers
            ? User::query()->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])->count()
            : 0;

        $newAdminsThisMonth = $isSuperAdmin ? SuperAdmin::query()
            ->whereBetween('created_at', [$monthStart, $now])
            ->count() : 0;
        $newAdminsPrevMonth = $isSuperAdmin ? SuperAdmin::query()
            ->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])
            ->count() : 0;

        $newSuspendedThisMonth = $isSuperAdmin ? SuperAdmin::query()
            ->where('status', 'suspended')
            ->whereBetween('created_at', [$monthStart, $now])
            ->count() : 0;
        $newSuspendedPrevMonth = $isSuperAdmin ? SuperAdmin::query()
            ->where('status', 'suspended')
            ->whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])
            ->count() : 0;

        $databaseHealthy = true;
        if ($isSuperAdmin) {
            try {
                DB::connection()->getPdo();
            } catch (\Throwable) {
                $databaseHealthy = false;
            }
        }

        $failedJobsCount = $isSuperAdmin && Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $activityRows = $canViewAudit && Schema::hasTable('activity_log')
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
                'metrics' => array_filter([
                    ...($canViewUsers ? [
                        'total_users' => $totalUsers,
                        'total_users_change' => $this->percentChange($newUsersThisMonth, $newUsersPrevMonth),
                    ] : []),
                    ...($isSuperAdmin ? [
                        'total_admins' => $totalAdmins,
                        'suspended_admins' => $suspendedAdmins,
                        'total_admins_change' => $this->percentChange($newAdminsThisMonth, $newAdminsPrevMonth),
                        'suspended_admins_change' => $this->percentChange($newSuspendedThisMonth, $newSuspendedPrevMonth),
                    ] : []),
                ], static fn (mixed $value): bool => $value !== null),
                'system_health' => $isSuperAdmin ? [
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
                ] : [],
                'recent_activity' => $recentActivity,
                'performance_metrics' => $isSuperAdmin ? [
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
                ] : [],
                'systems_operational' => $isSuperAdmin ? $databaseHealthy : null,
                'can_view_audit' => $canViewAudit,
                'can_view_system_health' => $isSuperAdmin,
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
