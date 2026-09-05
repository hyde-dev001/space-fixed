import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, ClipboardList, Clock3, PackageCheck, Truck, UserRound, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import type { LogisticsStats } from '@/types/logistics';
import type { ErpCapabilities } from '@/types/erp';
import { erpUrl } from '@/utils/erpCapabilities';
import { DashboardMetricCard, DashboardPanel, DashboardShell, DashboardState, DashboardTrendChart } from '@/components/dashboard';

type MetricTone = 'neutral' | 'success' | 'warning';
type DashboardMetric = { label: string; context: string; value: string | number; description: string; icon: LucideIcon; tone: MetricTone; progress?: number };
type AttentionItem = { label: string; value: number; summary: string; icon: LucideIcon };

const pluralize = (value: number, singular: string, plural: string) => `${value} ${value === 1 ? singular : plural}`;

function MetricCard({ metric }: { metric: DashboardMetric }) {
    return <DashboardMetricCard label={metric.label} value={metric.value} description={metric.description} context={metric.context} icon={metric.icon} tone={metric.tone} progress={metric.progress} />;
}

function AttentionCard({ item, href }: { item: AttentionItem; href: string | null }) {
    const Icon = item.icon;
    const content = (
        <>
            <div className="flex min-w-0 items-start gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white"><Icon className="h-5 w-5" aria-hidden="true" /></div>
                <div className="min-w-0"><p className="text-sm font-semibold text-gray-950 dark:text-white">{item.label}</p><p className="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{item.summary}</p></div>
            </div>
            <span className="shrink-0 text-2xl font-bold text-gray-950 dark:text-white">{item.value}</span>
        </>
    );
    const className = 'group flex min-h-28 items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-4 text-left transition-colors hover:border-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-600 dark:focus-visible:ring-white';
    return href ? <Link href={href} className={className} aria-label={`Review ${item.label.toLowerCase()}`}>{content}</Link> : <div className={className}>{content}</div>;
}

export default function Dashboard() {
    const { stats, auth, erpCapabilities, canViewShipments = false } = usePage<{
        stats: LogisticsStats;
        auth?: { erpActor?: { ownerMode?: boolean } };
        erpCapabilities?: ErpCapabilities;
        canViewShipments?: boolean;
    }>().props;
    const ownerMode = auth?.erpActor?.ownerMode === true;
    const shipmentsUrl = erpUrl(erpCapabilities, 'GET:erp.logistics.shipments') ?? (!ownerMode && canViewShipments ? '/erp/logistics/shipments' : null);
    const successRate = Math.max(0, Math.min(100, stats.delivery_success_rate));
    const healthMetrics: DashboardMetric[] = [
        { label: 'Active shipments', context: 'Current', value: stats.active, description: 'Shipments currently moving through delivery operations.', icon: Truck, tone: 'neutral' },
        { label: 'Due today', context: 'Today', value: stats.due_today, description: 'Stops scheduled before the day closes.', icon: Clock3, tone: 'neutral' },
        { label: 'Overdue deliveries', context: 'Today', value: stats.overdue, description: stats.overdue > 0 ? 'Review these stops before they become escalations.' : 'No delivery dates have slipped.', icon: AlertTriangle, tone: stats.overdue > 0 ? 'warning' : 'success' },
        { label: 'Delivery success rate', context: 'Overall', value: `${successRate}%`, description: 'Delivered legs compared with failed delivery attempts.', icon: PackageCheck, tone: successRate >= 90 ? 'success' : 'warning', progress: successRate },
    ];
    const attentionItems: AttentionItem[] = [
        { label: 'Overdue deliveries', value: stats.overdue, summary: pluralize(stats.overdue, 'delivery needs review', 'deliveries need review'), icon: AlertTriangle },
        { label: 'Unassigned stops', value: stats.unassigned, summary: pluralize(stats.unassigned, 'stop needs a rider', 'stops need a rider'), icon: UserRound },
        { label: 'Failed attempts', value: stats.failed_attempts, summary: pluralize(stats.failed_attempts, 'attempt needs follow-up', 'attempts need follow-up'), icon: Activity },
    ];
    const hasAttention = attentionItems.some((item) => item.value > 0);
    const operationalSnapshot = [
        { label: 'Requested', value: stats.requested, description: 'Awaiting dispatch action.', icon: ClipboardList },
        { label: 'Completed', value: stats.completed, description: 'Business-complete shipments.', icon: CheckCircle2 },
        { label: 'Rider workload', value: stats.rider_workload, description: 'Assigned or accepted tasks.', icon: Users },
        { label: 'Cancelled', value: stats.cancelled, description: 'Closed without delivery.', icon: PackageCheck },
    ];

    return (
        <AppLayoutERP>
            <Head title="Logistics Dashboard" />
            <DashboardShell
                testId="logistics-dashboard"
                headerTestId="logistics-module-summary"
                title="Logistics Dashboard"
                description="Keep deliveries moving, monitor rider capacity, and catch exceptions before they become delays."
                icon={Truck}
                snapshotDescription="Current shop logistics records"
            >
                <DashboardPanel eyebrow="Delivery health" title="Where operations stand today" description="The most useful signals for prioritizing dispatch work.">
                    <div data-testid="delivery-health-metrics" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {healthMetrics.map((metric) => <MetricCard key={metric.label} metric={metric} />)}
                    </div>
                </DashboardPanel>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(21rem,0.85fr)]">
                    <DashboardPanel eyebrow="Priority queue" title="Needs attention" description="Focus here first to keep delivery promises on track.">
                        {hasAttention ? (
                            <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-1 2xl:grid-cols-3">{attentionItems.map((item) => <AttentionCard key={item.label} item={item} href={shipmentsUrl} />)}</div>
                        ) : (
                            <div className="flex items-start gap-3 rounded-xl border border-emerald-100 bg-emerald-50/70 p-4 dark:border-emerald-900/40 dark:bg-emerald-500/10"><CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-300" aria-hidden="true" /><div><p className="text-sm font-semibold text-emerald-900 dark:text-emerald-200">No urgent delivery exceptions right now.</p><p className="mt-1 text-xs leading-5 text-emerald-800/80 dark:text-emerald-300/80">Your current delivery queue is clear of overdue, unassigned, and failed-attempt items.</p></div></div>
                        )}
                    </DashboardPanel>

                    <DashboardPanel eyebrow="Operations snapshot" title="Flow at a glance" description="A quick read of the wider logistics workload.">
                        <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">{operationalSnapshot.map((item) => { const Icon = item.icon; return <div key={item.label} className="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.04]"><div className="flex items-center justify-between gap-3"><dt className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400"><Icon className="h-4 w-4 text-gray-950 dark:text-white" aria-hidden="true" />{item.label}</dt><dd className="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{item.value}</dd></div><p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{item.description}</p></div>; })}</dl>
                    </DashboardPanel>
                </div>

                <DashboardPanel eyebrow="Delivery mix" title="Dispatch health" description="Current volume across the primary delivery states.">
                    <DashboardTrendChart title="Dispatch health" categories={['Requested', 'Active', 'Completed', 'Cancelled']} series={[{ name: 'Shipments', data: [stats.requested, stats.active, stats.completed, stats.cancelled] }]} type="bar" height={240} summary={`Dispatch health: ${stats.requested} requested, ${stats.active} active, ${stats.completed} completed, ${stats.cancelled} cancelled.`} />
                </DashboardPanel>
            </DashboardShell>
        </AppLayoutERP>
    );
}
