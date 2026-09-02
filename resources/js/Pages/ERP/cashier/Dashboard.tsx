import { Head, Link, router, usePage } from '@inertiajs/react';
import { CreditCard, HandCoins, ReceiptText, RotateCcw } from 'lucide-react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import {
    DashboardMetricCard,
    DashboardPanel,
    DashboardShell,
    DashboardState,
    DashboardTrendChart,
} from '../../../components/dashboard';

type CashierDashboardData = {
    title: string;
    description: string;
    refreshed_at: string;
    summary: {
        today_transactions: number;
        today_sales: string;
        pending_payments: number;
        refund_queue: number;
    };
    trend: {
        period_label: string;
        points: Array<{ label: string; date: string; transactions: number; sales: string }>;
    };
    status_breakdown: Array<{ status: string; count: number }>;
    recent_transactions: Array<{
        id: number;
        transaction_no: string;
        module_type: string;
        total_amount: string;
        paid_amount: string;
        status: string;
        created_at: string | null;
    }>;
    links: { point_of_sale: string };
};

type CashierPageProps = { dashboard?: CashierDashboardData };

const peso = '\u20b1';
const titleCase = (value: string): string => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const formatDate = (value: string | null): string => {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

export default function CashierDashboard() {
    const { dashboard } = usePage<CashierPageProps>().props;

    return (
        <AppLayoutERP>
            <Head title="Cashier Dashboard - SoleSpace ERP" />
            <DashboardShell
                testId="cashier-dashboard"
                title={dashboard?.title ?? 'Cashier Dashboard'}
                description={dashboard?.description ?? 'Your daily payment operations at a glance.'}
                icon={CreditCard}
                refreshedAt={dashboard?.refreshed_at}
                onRefresh={() => router.reload({ only: ['dashboard'], preserveScroll: true })}
                actions={
                    dashboard && (
                        <Link
                            href={dashboard.links.point_of_sale}
                            className="inline-flex min-h-11 items-center justify-center rounded-full bg-gray-950 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200 dark:focus-visible:ring-white"
                        >
                            Open point of sale
                        </Link>
                    )
                }
            >
                {!dashboard ? (
                    <DashboardState status="empty" title="Cashier dashboard unavailable" message="There is no dashboard snapshot for this account yet." />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <DashboardMetricCard
                                label="Today's sales"
                                value={`${peso}${dashboard.summary.today_sales}`}
                                description="Settled retail and repair POS payments"
                                context="Today"
                                icon={HandCoins}
                                tone="success"
                            />
                            <DashboardMetricCard
                                label="Transactions today"
                                value={dashboard.summary.today_transactions.toLocaleString()}
                                description="Successful payments recorded today"
                                context="Volume"
                                icon={ReceiptText}
                                href={dashboard.links.point_of_sale}
                            />
                            <DashboardMetricCard
                                label="Pending payments"
                                value={dashboard.summary.pending_payments.toLocaleString()}
                                description="Transactions that still need settlement"
                                context="Attention"
                                icon={CreditCard}
                                tone={dashboard.summary.pending_payments > 0 ? 'warning' : 'neutral'}
                                href={dashboard.links.point_of_sale}
                            />
                            <DashboardMetricCard
                                label="Refund queue"
                                value={dashboard.summary.refund_queue.toLocaleString()}
                                description="Refund requests requiring follow-up"
                                context="Follow-up"
                                icon={RotateCcw}
                                tone={dashboard.summary.refund_queue > 0 ? 'danger' : 'neutral'}
                                href={dashboard.links.point_of_sale}
                            />
                        </div>

                        <div className="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
                            <DashboardPanel
                                eyebrow="Payment activity"
                                title="Seven-day sales trend"
                                description="Settled sales and transaction volume from the unified POS records."
                            >
                                <DashboardTrendChart
                                    title="Seven-day sales trend"
                                    categories={dashboard.trend.points.map((point) => point.label)}
                                    series={[{ name: 'Sales', data: dashboard.trend.points.map((point) => Number(point.sales)) }]}
                                    type="area"
                                    height={280}
                                    options={{ yaxis: { labels: { formatter: (value) => `${peso}${value.toLocaleString()}` } }, tooltip: { y: { formatter: (value) => `${peso}${value.toLocaleString()}` } } }}
                                    summary={`${dashboard.trend.period_label}: ${dashboard.trend.points.map((point) => `${point.label} ${peso}${point.sales}`).join(', ')}.`}
                                />
                            </DashboardPanel>

                            <DashboardPanel eyebrow="All-time status" title="Transaction status" description="Current POS records grouped by payment state.">
                                {dashboard.status_breakdown.length === 0 ? (
                                    <DashboardState status="empty" title="No transaction records" message="Completed and pending POS activity will appear here." />
                                ) : (
                                    <div className="space-y-3">
                                        {dashboard.status_breakdown.map((item) => (
                                            <div key={item.status} className="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[0.04]">
                                                <span className="text-sm text-gray-600 dark:text-gray-300">{titleCase(item.status)}</span>
                                                <span className="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{item.count.toLocaleString()}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </DashboardPanel>
                        </div>

                        <DashboardPanel
                            eyebrow="Latest activity"
                            title="Recent transactions"
                            description="The newest retail and repair payments recorded for this shop."
                            action={<Link href={dashboard.links.point_of_sale} className="text-sm font-semibold text-gray-700 underline underline-offset-4 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white">View POS</Link>}
                        >
                            {dashboard.recent_transactions.length === 0 ? (
                                <DashboardState status="empty" title="No transactions yet" message="New POS activity will appear here once a payment is recorded." />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[640px] text-left text-sm">
                                        <thead className="border-b border-gray-200 text-xs uppercase tracking-[0.14em] text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <tr>
                                                <th className="pb-3 pr-4 font-semibold">Transaction</th>
                                                <th className="pb-3 pr-4 font-semibold">Module</th>
                                                <th className="pb-3 pr-4 font-semibold">Amount</th>
                                                <th className="pb-3 pr-4 font-semibold">Status</th>
                                                <th className="pb-3 font-semibold">Recorded</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                                            {dashboard.recent_transactions.map((transaction) => (
                                                <tr key={transaction.id}>
                                                    <td className="py-4 pr-4 font-semibold text-gray-950 dark:text-white">{transaction.transaction_no}</td>
                                                    <td className="py-4 pr-4 text-gray-600 dark:text-gray-300">{titleCase(transaction.module_type)}</td>
                                                    <td className="py-4 pr-4 tabular-nums text-gray-950 dark:text-white">{peso}{transaction.paid_amount}</td>
                                                    <td className="py-4 pr-4"><span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">{titleCase(transaction.status)}</span></td>
                                                    <td className="py-4 text-gray-500 dark:text-gray-400">{formatDate(transaction.created_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </DashboardPanel>
                    </>
                )}
            </DashboardShell>
        </AppLayoutERP>
    );
}
