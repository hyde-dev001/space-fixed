import { useQuery } from '@tanstack/react-query';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { useFinanceApi } from '../../../hooks/useFinanceApi';
import Chart from 'react-apexcharts';
import type { ApexOptions } from 'apexcharts';

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

const formatMoney = (value: Money | undefined): string => `₱${value ?? '0.00'}`;

const MetricCard = ({ title, value, description }: { title: string; value: Money; description: string }) => (
    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
        <h2 className="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{formatMoney(value)}</h2>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{description}</p>
    </div>
);

export default function FinanceDashboard() {
    const api = useFinanceApi();
    const { auth } = usePage().props as { auth?: { user?: { name?: string } } };
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
    const chartOptions: ApexOptions = {
        chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        colors: ['#10b981', '#f59e0b', '#3b82f6'],
        xaxis: { categories: summary?.trend.map((month) => month.month) ?? [] },
        yaxis: { labels: { formatter: (value) => `₱${value.toLocaleString()}` } },
        tooltip: { y: { formatter: (value) => `₱${value.toLocaleString()}` } },
        legend: { position: 'top' },
    };
    const chartSeries = [
        { name: 'Net revenue', data: summary?.trend.map((month) => Number(month.net_revenue)) ?? [] },
        { name: 'Incurred expenses', data: summary?.trend.map((month) => Number(month.incurred_expenses)) ?? [] },
        { name: 'Net cash movement', data: summary?.trend.map((month) => Number(month.net_cash_movement)) ?? [] },
    ];

    return (
        <AppLayoutERP>
            <Head title="Finance Dashboard - Solespace ERP" />
            <div className="px-6 py-8">
                <div className="mb-8">
                    <h1 className="mb-2 text-3xl font-bold text-gray-900 dark:text-white">Finance Dashboard</h1>
                    <p className="text-gray-600 dark:text-gray-400">Hello, {auth?.user?.name ?? 'there'}! Here is your financial overview.</p>
                </div>

                {summaryQuery.isLoading && <div className="rounded-xl border border-gray-200 bg-white p-8 text-gray-600 dark:border-gray-800 dark:bg-white/[0.03]">Loading Finance summary…</div>}
                {summaryQuery.isError && summaryQuery.error.status === 403 && <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-800">You do not have access to the Finance dashboard.</div>}
                {summaryQuery.isError && summaryQuery.error.status !== 403 && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-800">Finance summary is temporarily unavailable. Please try again.</div>}
                {!summaryQuery.isLoading && !summaryQuery.isError && !summary && <div className="rounded-xl border border-gray-200 bg-white p-8 text-gray-600 dark:border-gray-800 dark:bg-white/[0.03]">No Finance data is available for this period.</div>}

                {summary && (
                    <>
                        <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">{summary.period.start} – {summary.period.end}</p>
                        <div className="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
                            <MetricCard title="Net revenue" value={summary.primary.net_revenue} description="Gross revenue less executed refunds" />
                            <MetricCard title="Incurred expenses" value={summary.primary.incurred_expenses} description="Approved and operationally valid expenses" />
                            <MetricCard title="Net operating result" value={summary.primary.net_operating_result} description="Net revenue less incurred expenses" />
                            <MetricCard title="Net cash movement" value={summary.primary.net_cash_movement} description="Cash receipts, refunds, and paid expenses" />
                        </div>

                        <div className="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                            <h3 className="mb-4 text-lg font-bold text-gray-900 dark:text-white">Supporting financial detail</h3>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div><p className="text-sm text-gray-500">Gross revenue</p><p className="text-xl font-semibold">{formatMoney(summary.supporting.gross_revenue)}</p></div>
                                <div><p className="text-sm text-gray-500">Executed refunds</p><p className="text-xl font-semibold">{formatMoney(summary.supporting.executed_refunds)}</p></div>
                                <div><p className="text-sm text-gray-500">Paid expenses</p><p className="text-xl font-semibold">{formatMoney(summary.supporting.paid_expenses)}</p></div>
                            </div>
                            <details className="mt-4 text-sm text-gray-500">
                                <summary className="cursor-pointer font-medium">Metric definitions</summary>
                                <dl className="mt-3 space-y-2">
                                    {Object.entries(summary.definitions).map(([key, definition]) => <div key={key}><dt className="font-medium text-gray-700 dark:text-gray-300">{key.replace(/_/g, ' ')}</dt><dd>{definition}</dd></div>)}
                                </dl>
                            </details>
                        </div>

                        <div className="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                            <h3 className="mb-4 text-lg font-bold text-gray-900 dark:text-white">Six-month trend</h3>
                            <Chart options={chartOptions} series={chartSeries} type="area" height={300} />
                        </div>

                        {summary.integrity_warnings.length > 0 && (
                            <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
                                <h3 className="font-semibold">Some Finance records need review</h3>
                                <p className="mt-1 text-sm">Affected source records were excluded from the relevant authoritative metric.</p>
                                <ul className="mt-3 list-disc pl-5 text-sm">
                                    {summary.integrity_warnings.map((warning, index) => <li key={`${warning.code}-${warning.source ?? 'record'}-${index}`}>{warning.code}{warning.source ? ` · ${warning.source}` : ''}</li>)}
                                </ul>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayoutERP>
    );
}
