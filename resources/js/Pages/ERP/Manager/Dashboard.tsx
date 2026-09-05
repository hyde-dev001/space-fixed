import { Head, Link, usePage } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { useState } from 'react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { MoneyIcon } from '../../../components/common/MoneyIcon';
import {
    useManagerStats,
    type ManagerDashboardSignal,
    type ManagerDashboardStats,
    type ManagerDashboardTrend,
} from '../../../hooks/useManagerApi';
import { getManagerBusinessCapabilities } from '../../../utils/managerBusinessCapabilities';
import { DashboardMetricCard, DashboardPanel, DashboardShell, DashboardState, DashboardTrendChart } from '../../../components/dashboard';

type Icon = ComponentType<{ className?: string }>;

const ClipboardIcon = ({ className = '' }: { className?: string }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <rect x="5" y="4" width="14" height="16" rx="2" />
        <path d="M9 4.5V3h6v1.5M9 10h6M9 14h4" />
    </svg>
);

const WrenchIcon = ({ className = '' }: { className?: string }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <path d="M14.7 6.3a4 4 0 0 0-5.1 5.1L4 17a2.1 2.1 0 1 0 3 3l5.6-5.6a4 4 0 0 0 5.1-5.1l-2.2 2.2-2.8-.6-.6-2.8 2.6-1.8Z" />
    </svg>
);

const CheckIcon = ({ className = '' }: { className?: string }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <path d="m5 12 4 4L19 6" />
    </svg>
);

const UsersIcon = ({ className = '' }: { className?: string }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM16 11a3 3 0 0 0 0-6M16 14.5a3.5 3.5 0 0 1 4 3.5V20" />
    </svg>
);

const BoxIcon = ({ className = '' }: { className?: string }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" />
        <path d="m4.3 7.7 7.7 4.4 7.7-4.4M12 12.1V21" />
    </svg>
);

const AlertIcon = ({ className = '' }: { className?: string }) => (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <path d="M12 3 2.8 19h18.4L12 3Z" />
        <path d="M12 9v4M12 16h.01" />
    </svg>
);

const rangeOptions = [
    { value: 'last_7_days', label: 'Last 7 days' },
    { value: 'last_30_days', label: 'Last 30 days' },
    { value: 'last_90_days', label: 'Last 90 days' },
    { value: 'month_to_date', label: 'Month to date' },
];

const formatNumber = (value: number) => value.toLocaleString('en-US');

const formatDateTime = (value: string | undefined) => {
    if (!value) return 'Not available';

    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? 'Not available'
        : date.toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' });
};

const trendText = (trend: ManagerDashboardTrend) => {
    if (!trend.baseline_available) return 'No prior baseline';
    return `${trend.percent >= 0 ? '+' : ''}${trend.percent}% vs comparison period`;
};

const trendClass = (trend: ManagerDashboardTrend) => {
    if (!trend.baseline_available || trend.direction === 'flat') return 'text-gray-500 dark:text-gray-400';
    return trend.direction === 'increase'
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-rose-600 dark:text-rose-400';
};

function MetricCard({ title, value, description, icon: Icon, tone }: {
    title: string;
    value: number;
    description: string;
    icon: Icon;
    tone: 'blue' | 'violet' | 'amber' | 'emerald';
}) {
    return (
        <DashboardMetricCard
            label={title}
            value={formatNumber(value)}
            description={description}
            context="Current"
            icon={Icon}
            tone={tone === 'amber' ? 'warning' : tone === 'emerald' ? 'success' : 'neutral'}
        />
    );
}

function SignalRow({ signal }: { signal: ManagerDashboardSignal }) {
    const severityClass = signal.severity === 'critical'
        ? 'border-rose-200 bg-rose-50/70 dark:border-rose-950 dark:bg-rose-950/20'
        : signal.severity === 'warning'
            ? 'border-amber-200 bg-amber-50/70 dark:border-amber-950 dark:bg-amber-950/20'
            : 'border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-gray-900/50';

    return (
        <div className={`rounded-xl border p-4 ${severityClass}`}>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-gray-950 dark:text-white">{signal.status}</span>
                        {signal.reference && <span className="text-xs text-gray-500 dark:text-gray-400">{signal.reference}</span>}
                    </div>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{signal.next_action}</p>
                    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <span>Age: {signal.age_days} day{signal.age_days === 1 ? '' : 's'}</span>
                        <span>Responsible: {signal.responsible.label}</span>
                        <span>Waiting on: {signal.waiting_on.label}</span>
                    </div>
                </div>
                <Link
                    href={signal.href}
                    className="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-800 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
                >
                    Open page
                </Link>
            </div>
        </div>
    );
}

function ApprovalLink({ label, value, href }: { label: string; value: number; href: string }) {
    return (
        <Link
            href={href}
            className="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 transition hover:border-blue-300 hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-950/40 dark:hover:border-blue-900 dark:hover:bg-blue-950/20"
        >
            <span className="text-sm text-gray-700 dark:text-gray-300">{label}</span>
            <span className="text-lg font-semibold text-gray-950 dark:text-white">{formatNumber(value)}</span>
        </Link>
    );
}

export default function ManagerDashboard() {
    const { props } = usePage<{
        auth?: {
            business_type?: string | null;
            shop_owner?: { business_type?: string | null };
            user?: {
                business_type?: string | null;
                shop_owner?: { business_type?: string | null };
            };
        };
    }>();
    const authBusinessType = props.auth?.shop_owner?.business_type
        ?? props.auth?.user?.shop_owner?.business_type
        ?? props.auth?.business_type
        ?? props.auth?.user?.business_type;
    const [range, setRange] = useState('last_30_days');
    const { data: stats, error, isError, isLoading, isFetching, isStale, refetch } = useManagerStats(range);
    const typedStats = stats as ManagerDashboardStats | undefined;
    const capabilities = typedStats?.businessCapabilities
        ?? getManagerBusinessCapabilities(authBusinessType);
    const canRetail = capabilities.canRetail;
    const canRepair = capabilities.canRepair;
    const pendingApprovalDescription = canRepair
        ? 'Leave, suspension, and repair review'
        : 'Leave and suspension review';
    const snapshotAt = typedStats?.snapshot?.captured_at;
    const staleByAge = snapshotAt
        ? Date.now() - new Date(snapshotAt).getTime() > (typedStats?.freshness?.stale_after_seconds ?? 60) * 1000
        : false;
    const isStaleSnapshot = isStale || staleByAge;
    const workloadCategories = [
        ...(canRetail ? ['Open job orders'] : []),
        ...(canRepair ? ['Active repair jobs'] : []),
        'Pending approvals',
    ];
    const workloadValues = [
        ...(canRetail ? [typedStats?.current_state.job_orders.open ?? 0] : []),
        ...(canRepair ? [typedStats?.current_state.repair_jobs.active ?? 0] : []),
        typedStats?.current_state.approvals.total ?? 0,
    ];

    return (
        <AppLayoutERP>
            <Head title="Manager Dashboard - Solespace ERP" />
            <DashboardShell
                testId="manager-dashboard"
                title="Manager Dashboard"
                description="Monitor shop workload, approvals, staffing exceptions, and inventory health from one current snapshot."
                icon={UsersIcon}
                refreshedAt={snapshotAt}
                onRefresh={() => void refetch()}
                isRefreshing={isFetching}
                actions={
                    <label className="flex min-h-11 items-center gap-2 rounded-full border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:bg-transparent dark:text-gray-200">
                        <span className="sr-only">Period</span>
                        <select
                            id="dashboard-range"
                            value={range}
                            onChange={(event) => setRange(event.target.value)}
                            className="bg-transparent text-sm font-semibold text-gray-800 outline-none dark:text-gray-100"
                        >
                            {rangeOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </label>
                }
            >

                {isStaleSnapshot && typedStats && (
                    <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-950 dark:bg-amber-950/30 dark:text-amber-200" role="status">
                        <AlertIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p className="font-semibold">This snapshot may be stale.</p>
                            <p className="mt-1">Refresh to load one consistent set of KPI, approval, and operational-signal data.</p>
                        </div>
                    </div>
                )}

                {isLoading && <DashboardState status="loading" title="Loading manager dashboard" />}

                {isError && <DashboardState status="error" title="Manager dashboard unavailable" message={error?.message || 'Try again or check your Manager dashboard access.'} onRetry={() => void refetch()} />}

                {typedStats && !isLoading && !isError && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {canRetail && <MetricCard title="Open Job Orders" value={typedStats.current_state.job_orders.open} description={`${typedStats.current_state.job_orders.reassignment_required} require reassignment`} icon={ClipboardIcon} tone="blue" />}
                            {canRepair && <MetricCard title="Active Repair Jobs" value={typedStats.current_state.repair_jobs.active} description={`${typedStats.current_state.repair_jobs.reassignment_required} require reassignment`} icon={WrenchIcon} tone="violet" />}
                            <MetricCard title="Pending Approvals" value={typedStats.current_state.approvals.total} description={pendingApprovalDescription} icon={CheckIcon} tone="amber" />
                            <MetricCard title="Active Staff" value={typedStats.current_state.staff.active} description={`${typedStats.current_state.staff.unavailable_with_active_work} unavailable with active work`} icon={UsersIcon} tone="emerald" />
                        </div>

                        <div className="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
                            <DashboardPanel eyebrow="Period performance" title="Period performance" description={`${typedStats.period_metrics.range.label}; comparison uses the preceding period of equal length.`}>
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p className="text-xs text-gray-500 dark:text-gray-400">Data range</p>
                                    </div>
                                    <span className="text-xs text-gray-500 dark:text-gray-400">{typedStats.period_metrics.range.timezone}</span>
                                </div>
                                <div className="mt-6 grid gap-4 sm:grid-cols-3">
                                    {canRetail && <div className="rounded-xl bg-gray-50 p-4 dark:bg-gray-950/50">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Orders received</p>
                                        <p className="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{formatNumber(typedStats.period_metrics.orders.received)}</p>
                                        <p className={`mt-1 text-xs ${trendClass(typedStats.period_metrics.trends.orders)}`}>{trendText(typedStats.period_metrics.trends.orders)}</p>
                                    </div>}
                                    {canRepair && <div className="rounded-xl bg-gray-50 p-4 dark:bg-gray-950/50">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Repairs received</p>
                                        <p className="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{formatNumber(typedStats.period_metrics.repairs.received)}</p>
                                        <p className={`mt-1 text-xs ${trendClass(typedStats.period_metrics.trends.repairs)}`}>{trendText(typedStats.period_metrics.trends.repairs)}</p>
                                    </div>}
                                    {canRetail && <div className="rounded-xl bg-gray-50 p-4 dark:bg-gray-950/50">
                                         <div className="flex items-center justify-between gap-2">
                                             <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Paid order revenue</p>
                                             <MoneyIcon className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                         </div>
                                        <p className="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">₱{typedStats.period_metrics.revenue.current.toLocaleString('en-US', { minimumFractionDigits: 2 })}</p>
                                        <p className={`mt-1 text-xs ${trendClass(typedStats.period_metrics.revenue.trend)}`}>{trendText(typedStats.period_metrics.revenue.trend)}</p>
                                    </div>}
                                </div>
                                <div className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                                    {canRetail && <span>Completed orders: {formatNumber(typedStats.period_metrics.orders.completed)}</span>}
                                    {canRepair && <span>Completed repairs: {formatNumber(typedStats.period_metrics.repairs.completed)}</span>}
                                    {canRepair && <span>Rejected repairs: {formatNumber(typedStats.period_metrics.repairs.rejected)}</span>}
                                </div>
                                <DashboardTrendChart
                                    title="Current workload mix"
                                    categories={workloadCategories}
                                    series={[{ name: 'Open items', data: workloadValues }]}
                                    type="bar"
                                    height={240}
                                    summary={`Current workload mix: ${workloadCategories.map((category, index) => `${category} ${workloadValues[index]}`).join(', ')}.`}
                                />
                            </DashboardPanel>

                            <DashboardPanel eyebrow="Approval queue" title="Approval summary" description="Open counts routed to their page-specific queues.">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-sm text-gray-500 dark:text-gray-400">Review each queue in its owning workflow.</p>
                                    </div>
                                    <CheckIcon className="h-6 w-6 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div className="mt-5 space-y-3">
                                    <ApprovalLink label="Leave approvals" value={typedStats.current_state.approvals.leave} href="/erp/manager/leave-approvals" />
                                    <ApprovalLink label="Suspension approvals" value={typedStats.current_state.approvals.suspension} href="/erp/manager/suspension-approvals" />
                                    {canRepair && <ApprovalLink label="Repair review" value={typedStats.current_state.approvals.repair_review} href="/erp/manager/repair-jobs?review=pending" />}
                                </div>
                            </DashboardPanel>
                        </div>

                        <div className="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
                            <DashboardPanel eyebrow="Exceptions" title="Operational signals" description="Signals include ownership, waiting state, and the next page to open.">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-sm text-gray-500 dark:text-gray-400">{typedStats.signals.length} signal{typedStats.signals.length === 1 ? '' : 's'} currently require attention.</p>
                                    </div>
                                    <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">{typedStats.signals.length}</span>
                                </div>
                                <div className="mt-5 space-y-3">
                                    {typedStats.signals.length === 0 ? (
                                        <div className="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">No operational signals require attention.</div>
                                    ) : typedStats.signals.map((signal) => <SignalRow key={signal.id} signal={signal} />)}
                                </div>
                            </DashboardPanel>

                            <DashboardPanel eyebrow="Inventory" title="Inventory health" description="Server-calculated shop-wide stock snapshot.">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-sm text-gray-500 dark:text-gray-400">Stock counts available to the manager view.</p>
                                    </div>
                                    <BoxIcon className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div className="mt-5 space-y-3 text-sm">
                                    <div className="flex items-center justify-between"><span className="text-gray-600 dark:text-gray-400">Items tracked</span><span className="font-semibold text-gray-950 dark:text-white">{formatNumber(typedStats.current_state.inventory.total_items)}</span></div>
                                    <div className="flex items-center justify-between"><span className="text-gray-600 dark:text-gray-400">Total quantity</span><span className="font-semibold text-gray-950 dark:text-white">{formatNumber(typedStats.current_state.inventory.total_quantity)}</span></div>
                                    <div className="flex items-center justify-between"><span className="text-amber-700 dark:text-amber-300">Low stock</span><span className="font-semibold text-amber-700 dark:text-amber-300">{formatNumber(typedStats.current_state.inventory.low_stock_count)}</span></div>
                                    <div className="flex items-center justify-between"><span className="text-rose-700 dark:text-rose-300">Out of stock</span><span className="font-semibold text-rose-700 dark:text-rose-300">{formatNumber(typedStats.current_state.inventory.out_of_stock_count)}</span></div>
                                </div>
                                <Link href="/erp/manager/inventory-overview" className="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-gray-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-800 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200">Open Inventory Overview</Link>
                            </DashboardPanel>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <span>Snapshot captured: {formatDateTime(typedStats.snapshot.captured_at)}</span>
                            <span>Data is shop-scoped; refresh updates KPI and drilldown sections together.</span>
                        </div>
                    </>
                )}
            </DashboardShell>
        </AppLayoutERP>
    );
}
