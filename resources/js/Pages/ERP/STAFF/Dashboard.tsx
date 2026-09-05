import { Head, Link, router, usePage } from '@inertiajs/react';
import { ClipboardList, Clock3, PackageCheck, UsersRound } from 'lucide-react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import {
    DashboardMetricCard,
    DashboardPanel,
    DashboardShell,
    DashboardState,
    DashboardTrendChart,
} from '../../../components/dashboard';

type StaffDashboardData = {
    title: string;
    description: string;
    refreshed_at: string;
    summary: {
        assigned_open_work: number;
        active_orders: number;
        completed_today: number;
        attendance_status: string;
    };
    attendance: {
        status: string;
        label: string;
        recorded_at: string | null;
    };
    trend: {
        period_label: string;
        points: Array<{ label: string; start: string; assigned_orders: number }>;
    };
    recent_work: Array<{
        id: number;
        reference: string;
        status: string;
        created_at: string | null;
    }>;
    links: {
        orders: string;
        customers: string;
        attendance: string;
    };
};

type StaffPageProps = {
    dashboard?: StaffDashboardData;
};

const titleCase = (value: string): string => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const formatDate = (value: string | null): string => {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
};

export default function StaffDashboard() {
    const { dashboard } = usePage<StaffPageProps>().props;

    return (
        <AppLayoutERP>
            <Head title="Staff Dashboard - SoleSpace ERP" />
            <DashboardShell
                testId="staff-dashboard"
                title={dashboard?.title ?? 'Staff Dashboard'}
                description={dashboard?.description ?? 'Your assigned work at a glance.'}
                icon={ClipboardList}
                refreshedAt={dashboard?.refreshed_at}
                onRefresh={() => router.reload({ only: ['dashboard'], preserveScroll: true })}
                actions={
                    dashboard && (
                        <Link
                            href={dashboard.links.orders}
                            className="inline-flex min-h-11 items-center justify-center rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-800 transition-colors hover:border-gray-950 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-100 dark:hover:border-white dark:hover:bg-white/10 dark:focus-visible:ring-white"
                        >
                            Open job orders
                        </Link>
                    )
                }
            >
                {!dashboard ? (
                    <DashboardState status="empty" title="Staff dashboard unavailable" message="There is no dashboard snapshot for this account yet." />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <DashboardMetricCard
                                label="Assigned open work"
                                value={dashboard.summary.assigned_open_work.toLocaleString()}
                                description="Open orders assigned to you"
                                context="Workload"
                                icon={ClipboardList}
                                href={dashboard.links.orders}
                            />
                            <DashboardMetricCard
                                label="Active orders"
                                value={dashboard.summary.active_orders.toLocaleString()}
                                description="Pending, processing, or shipped"
                                context="In progress"
                                icon={PackageCheck}
                                tone="success"
                                href={dashboard.links.orders}
                            />
                            <DashboardMetricCard
                                label="Completed today"
                                value={dashboard.summary.completed_today.toLocaleString()}
                                description="Orders completed or delivered today"
                                context="Today"
                                icon={UsersRound}
                                tone="neutral"
                                href={dashboard.links.orders}
                            />
                            <DashboardMetricCard
                                label="Attendance"
                                value={dashboard.attendance.label}
                                description="Your latest attendance record for today"
                                context="Time & attendance"
                                icon={Clock3}
                                tone={dashboard.attendance.status === 'present' ? 'success' : 'neutral'}
                                href={dashboard.links.attendance}
                            />
                        </div>

                        <div className="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
                            <DashboardPanel
                                eyebrow="Activity trend"
                                title="Assigned orders received"
                                description="A six-month view of orders assigned to your account."
                            >
                                <DashboardTrendChart
                                    title="Assigned orders received"
                                    categories={dashboard.trend.points.map((point) => point.label)}
                                    series={[{ name: 'Assigned orders', data: dashboard.trend.points.map((point) => point.assigned_orders) }]}
                                    type="area"
                                    height={280}
                                    summary={`${dashboard.trend.period_label}: ${dashboard.trend.points.map((point) => `${point.label} ${point.assigned_orders}`).join(', ')}.`}
                                />
                            </DashboardPanel>

                            <DashboardPanel
                                eyebrow="Today"
                                title="Attendance status"
                                description="Keep your time record current before starting assigned work."
                            >
                                <div className="rounded-2xl bg-gray-50 p-5 dark:bg-white/[0.04]">
                                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Current status</p>
                                    <p className="mt-3 text-2xl font-semibold text-gray-950 dark:text-white">{dashboard.attendance.label}</p>
                                    <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        {dashboard.attendance.recorded_at ? `Recorded ${formatDate(dashboard.attendance.recorded_at)}` : 'Clock in to create today\'s record.'}
                                    </p>
                                </div>
                                <Link
                                    href={dashboard.links.attendance}
                                    className="mt-5 inline-flex text-sm font-semibold text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white"
                                >
                                    Open attendance
                                </Link>
                            </DashboardPanel>
                        </div>

                        <DashboardPanel
                            eyebrow="Assigned queue"
                            title="Recent work"
                            description="The latest orders assigned to your account."
                            action={
                                <Link href={dashboard.links.customers} className="text-sm font-semibold text-gray-700 underline underline-offset-4 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white">
                                    View customers
                                </Link>
                            }
                        >
                            {dashboard.recent_work.length === 0 ? (
                                <DashboardState status="empty" title="No assigned orders yet" message="New assignments will appear here when work is routed to you." />
                            ) : (
                                <div className="divide-y divide-gray-200 dark:divide-gray-800">
                                    {dashboard.recent_work.map((work) => (
                                        <div key={work.id} className="flex flex-col gap-2 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p className="font-semibold text-gray-950 dark:text-white">{work.reference}</p>
                                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Assigned order · {formatDate(work.created_at)}</p>
                                            </div>
                                            <span className="w-fit rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                                {titleCase(work.status)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </DashboardPanel>
                    </>
                )}
            </DashboardShell>
        </AppLayoutERP>
    );
}
