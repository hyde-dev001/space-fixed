import { useCallback, useEffect, useState } from 'react';
import { CalendarDays, CircleCheck, UsersRound } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import {
    DashboardMetricCard,
    DashboardPanel,
    DashboardShell,
    DashboardState,
    DashboardTrendChart,
} from '../../../components/dashboard';

interface DepartmentData {
    department: string;
    count: number;
}

interface StatusData {
    status: string;
    count: number;
}

interface DashboardData {
    headcount: {
        current_headcount: number;
        by_department: DepartmentData[];
        by_status: StatusData[];
        monthly_trend: Array<{ month: string; count: number }>;
    };
    summary: {
        total_employees: number;
        active_employees: number;
        current_on_leave: number;
        total_departments: number;
        pending_leave_requests: number;
        this_month_payroll: number | string;
    };
}

type HRPageProps = { initialHrDashboard?: DashboardData };

const percentage = (part: number, total: number): string => (total > 0 ? ((part / total) * 100).toFixed(1) : '0.0');
const label = (value: string): string => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

export function HRDashboard() {
    const { initialHrDashboard } = usePage<HRPageProps>().props;
    const [dashboardData, setDashboardData] = useState<DashboardData | null>(initialHrDashboard ?? null);
    const [loading, setLoading] = useState(!initialHrDashboard);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadDashboard = useCallback(async () => {
        try {
            setRefreshing(true);
            const response = await fetch('/api/hr/dashboard', { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            setDashboardData(await response.json() as DashboardData);
            setError(null);
        } catch (requestError) {
            console.error('Error fetching HR dashboard data:', requestError);
            setError('Failed to load HR dashboard data.');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, []);

    useEffect(() => {
        if (!initialHrDashboard) void loadDashboard();
    }, [initialHrDashboard, loadDashboard]);

    const totalEmployees = dashboardData?.summary.total_employees ?? dashboardData?.headcount.current_headcount ?? 0;
    const activeEmployees = dashboardData?.summary.active_employees ?? 0;
    const onLeaveCount = dashboardData?.summary.current_on_leave ?? 0;
    const pendingLeaveRequests = dashboardData?.summary.pending_leave_requests ?? 0;
    const statuses = dashboardData?.headcount.by_status ?? [];
    const activeStatus = statuses.find((item) => item.status === 'active')?.count ?? activeEmployees;
    const inactiveStatus = statuses.find((item) => item.status === 'inactive')?.count ?? 0;
    const suspendedStatus = statuses.find((item) => item.status === 'suspended')?.count ?? 0;
    const availability = Math.min(100, Number(percentage(activeEmployees + onLeaveCount, totalEmployees)));

    return (
        <DashboardShell
            testId="hr-dashboard"
            title="HR Dashboard"
            description="Keep headcount, attendance, and workforce health visible from one current snapshot."
            icon={UsersRound}
            onRefresh={() => void loadDashboard()}
            isRefreshing={refreshing}
        >
            {loading && <DashboardState status="loading" title="Loading HR dashboard" />}
            {error && !dashboardData && <DashboardState status="error" title="HR dashboard unavailable" message={error} onRetry={() => void loadDashboard()} />}

            {dashboardData && !loading && (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <DashboardMetricCard label="Total employees" value={totalEmployees.toLocaleString()} description="Registered employees in this shop" context="Headcount" icon={UsersRound} />
                        <DashboardMetricCard label="Active employees" value={activeEmployees.toLocaleString()} description="Currently active workforce records" context="Workforce" icon={CircleCheck} tone="success" />
                        <DashboardMetricCard label="On leave" value={onLeaveCount.toLocaleString()} description="Employees currently marked on leave" context="Availability" icon={CalendarDays} tone={onLeaveCount > 0 ? 'warning' : 'neutral'} />
                        <DashboardMetricCard label="Pending leave requests" value={pendingLeaveRequests.toLocaleString()} description="Requests waiting for review" context="Approvals" icon={CalendarDays} tone={pendingLeaveRequests > 0 ? 'warning' : 'neutral'} />
                    </div>

                    <DashboardPanel eyebrow="Headcount" title="Employee distribution by department" description="Active workforce grouped by the department stored in the HR records.">
                        <DashboardTrendChart
                            title="Employee distribution by department"
                            categories={dashboardData.headcount.by_department.map((item) => item.department)}
                            series={[{ name: 'Employees', data: dashboardData.headcount.by_department.map((item) => item.count) }]}
                            type="bar"
                            height={320}
                            options={{ plotOptions: { bar: { columnWidth: '42%', borderRadius: 6 } }, tooltip: { y: { formatter: (value) => `${value} employees` } } }}
                            summary={`Employee distribution: ${dashboardData.headcount.by_department.map((item) => `${item.department} ${item.count}`).join(', ') || 'no departments recorded'}.`}
                        />
                    </DashboardPanel>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <DashboardPanel eyebrow="Workforce health" title="Workforce analytics" description="A compact view of current availability and deployment readiness.">
                            <div className="space-y-5">
                                {[
                                    ['Employment rate', percentage(activeEmployees, totalEmployees), 'Active vs total staff'],
                                    ['Leave rate', percentage(onLeaveCount, totalEmployees), 'Currently away from duty'],
                                    ['Availability', availability.toFixed(1), 'Active or on leave records'],
                                ].map(([name, value, detail]) => (
                                    <div key={name}>
                                        <div className="flex items-center justify-between gap-3">
                                            <div><p className="text-sm font-semibold text-gray-800 dark:text-gray-200">{name}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{detail}</p></div>
                                            <span className="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{value}%</span>
                                        </div>
                                        <div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"><div className="h-full rounded-full bg-gray-950 dark:bg-white" style={{ width: `${value}%` }} /></div>
                                    </div>
                                ))}
                            </div>
                        </DashboardPanel>

                        <DashboardPanel eyebrow="Employment status" title="Headcount by status" description="Status distribution sourced from the current employee records.">
                            {statuses.length === 0 ? (
                                <DashboardState status="empty" title="No status records yet" message="Employee status data will appear here once HR records are available." />
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {[
                                        { key: 'active', count: activeStatus },
                                        { key: 'on_leave', count: onLeaveCount },
                                        { key: 'inactive', count: inactiveStatus },
                                        { key: 'suspended', count: suspendedStatus },
                                    ].map((item) => (
                                        <div key={item.key} className="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.04]">
                                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">{label(item.key)}</p>
                                            <div className="mt-2 flex items-baseline justify-between gap-3"><span className="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{item.count.toLocaleString()}</span><span className="text-xs font-semibold text-gray-500 dark:text-gray-400">{percentage(item.count, totalEmployees)}%</span></div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </DashboardPanel>
                    </div>
                </>
            )}
        </DashboardShell>
    );
}
