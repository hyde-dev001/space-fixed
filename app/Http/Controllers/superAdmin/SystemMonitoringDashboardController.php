<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SystemMonitoringDashboardController extends Controller
{
    public function index(): Response
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

        $activityRows = collect();
        if (Schema::hasTable('audit_logs')) {
            $activityRows = AuditLog::query()
                ->select(['action', 'created_at'])
                ->whereIn('target_type', ['user', 'super_admin'])
                ->latest('created_at')
                ->limit(5)
                ->get();
        } elseif (Schema::hasTable('activity_log')) {
            $activityRows = DB::table('activity_log')
                ->select(['description', 'created_at'])
                ->where('description', 'not like', '%shop%')
                ->where('description', 'not like', '%subscription%')
                ->latest('created_at')
                ->limit(5)
                ->get();
        }

        $recentActivity = $activityRows->map(function ($row) {
            $label = (string) ($row->action ?? $row->description ?? 'System activity recorded');
            $created = $row->created_at ? Carbon::parse($row->created_at) : null;
            return [
                'activity' => str_replace('_', ' ', ucfirst($label)),
                'time' => $created ? $created->diffForHumans() : 'just now',
                'status' => 'Info',
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
                        'status' => 'Live',
                    ],
                    [
                        'metric' => 'Suspended Admin Accounts',
                        'value' => (string) $suspendedAdmins,
                        'status' => 'Live',
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
