import { useQuery } from '@tanstack/react-query';
import { Head } from '@inertiajs/react';
import { CircleDollarSign, Receipt, TrendingUp, Wallet } from 'lucide-react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { useFinanceApi } from '../../../hooks/useFinanceApi';
import {
    DashboardMetricCard,
    DashboardPanel,
    DashboardShell,
    DashboardState,
    DashboardTrendChart,
} from '../../../components/dashboard';

type Money = string;

interface FinanceSummary {
    period: { type: string; start: string; end: string; timezone: string };
    primary: {
        net_revenue: Money;
        incurred_expenses: Money;
        net_operating_result: Money;
        net_cash_movement: Money;
    };
    supporting: {
        gross_revenue: Money;
        executed_refunds: Money;
        paid_expenses: Money;
    };
    trend: Array<{
        month: string;
        net_revenue: Money;
        incurred_expenses: Money;
        net_cash_movement: Money;
    }>;
    definitions: Record<string, string>;
    integrity_warnings: Array<{ code: string; source?: string; reason?: string }>;
}

class FinanceSummaryError extends Error {
    constructor(public readonly status: number, message: string) {
        super(message);
    }
}

const formatMoney = (value: Money | undefined): string => `\u20b1${value ?? '0.00'}`;

export default function FinanceDashboard() {
    const api = useFinanceApi();
    const summaryQuery = useQuery<FinanceSummary, FinanceSummaryError>({
        queryKey: ['finance', 'dashboard'],
        queryFn: async () => {
            const response = await api.get<FinanceSummary>('/api/finance/dashboard');
            if (!response.ok || !response.data) {
                throw new FinanceSummaryError(response.status, response.error || 'Finance summary unavailable');
            }
            return response.data;
        },
    });

    const summary = summaryQuery.data;
    const hasForbiddenError = summaryQuery.isError && summaryQuery.error.status === 403;
    const chartSeries = summary
        ? [
              { name: 'Net revenue', data: summary.trend.map((month) => Number(month.net_revenue)) },
              { name: 'Incurred expenses', data: summary.trend.map((month) => Number(month.incurred_expenses)) },
              { name: 'Net cash movement', data: summary.trend.map((month) => Number(month.net_cash_movement)) },
          ]
        : [];

    return (
        <AppLayoutERP>
            <Head title="Finance Dashboard - SoleSpace ERP" />
            <DashboardShell
                testId="finance-dashboard"
                title="Finance Dashboard"
                description="Review revenue, expenses, and cash movement from one server-calculated financial snapshot."
                icon={CircleDollarSign}
                onRefresh={() => void summaryQuery.refetch()}
                isRefreshing={summaryQuery.isFetching}
            >
                {summaryQuery.isLoading && <DashboardState status="loading" title="Loading Finance summary" />}
                {hasForbiddenError && <DashboardState status="error" title="Finance access required" message="You do not have access to the Finance dashboard." />}
                {summaryQuery.isError && !hasForbiddenError && (
                    <DashboardState status="error" title="Finance summary unavailable" message="Finance summary is temporarily unavailable. Please try again." onRetry={() => void summaryQuery.refetch()} />
                )}
                {!summaryQuery.isLoading && !summaryQuery.isError && !summary && <DashboardState status="empty" title="No Finance data is available" message="There are no financial records for this period yet." />}

                {summary && (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
                            <span>{summary.period.start} — {summary.period.end}</span>
                            <span>{summary.period.timezone}</span>
                        </div>

                        <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <DashboardMetricCard label="Net revenue" value={formatMoney(summary.primary.net_revenue)} description="Gross revenue less executed refunds" context="Primary" icon={CircleDollarSign} testId="finance-metric-card" iconTestId="finance-metric-icon" />
                            <DashboardMetricCard label="Incurred expenses" value={formatMoney(summary.primary.incurred_expenses)} description="Approved and operationally valid expenses" context="Primary" icon={Receipt} testId="finance-metric-card" iconTestId="finance-metric-icon" />
                            <DashboardMetricCard label="Net operating result" value={formatMoney(summary.primary.net_operating_result)} description="Net revenue less incurred expenses" context="Primary" icon={TrendingUp} tone="success" testId="finance-metric-card" iconTestId="finance-metric-icon" />
                            <DashboardMetricCard label="Net cash movement" value={formatMoney(summary.primary.net_cash_movement)} description="Cash receipts, refunds, and paid expenses" context="Primary" icon={Wallet} testId="finance-metric-card" iconTestId="finance-metric-icon" />
                        </div>

                        <div className="mt-6 grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
                            <DashboardPanel eyebrow="Supporting detail" title="Financial detail" description="The source values behind the primary cards.">
                                <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                                    <div className="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.04]"><p className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">Gross revenue</p><p className="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{formatMoney(summary.supporting.gross_revenue)}</p></div>
                                    <div className="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.04]"><p className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">Executed refunds</p><p className="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{formatMoney(summary.supporting.executed_refunds)}</p></div>
                                    <div className="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.04]"><p className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">Paid expenses</p><p className="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{formatMoney(summary.supporting.paid_expenses)}</p></div>
                                </div>
                                <details className="mt-5 text-sm text-gray-500 dark:text-gray-400">
                                    <summary className="cursor-pointer font-semibold text-gray-700 dark:text-gray-200">Metric definitions</summary>
                                    <dl className="mt-3 space-y-3">
                                        {Object.entries(summary.definitions).map(([key, definition]) => <div key={key}><dt className="font-semibold text-gray-800 dark:text-gray-200">{key.replace(/_/g, ' ')}</dt><dd className="mt-1">{definition}</dd></div>)}
                                    </dl>
                                </details>
                            </DashboardPanel>

                            <DashboardPanel eyebrow="Performance" title="Six-month trend" description="Revenue, incurred expenses, and net cash movement by month.">
                                <DashboardTrendChart
                                    title="Six-month financial trend"
                                    categories={summary.trend.map((month) => month.month)}
                                    series={chartSeries}
                                    height={300}
                                    options={{ yaxis: { labels: { formatter: (value) => `\u20b1${value.toLocaleString()}` } }, tooltip: { y: { formatter: (value) => `\u20b1${value.toLocaleString()}` } } }}
                                    summary="Six-month financial trend comparing net revenue, incurred expenses, and net cash movement."
                                />
                            </DashboardPanel>
                        </div>
                    </>
                )}
            </DashboardShell>
        </AppLayoutERP>
    );
}
