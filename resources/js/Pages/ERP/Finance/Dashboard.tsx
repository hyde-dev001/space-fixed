import type { ComponentType } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { useInvoices, useExpenses } from '../../../hooks/useFinanceQueries';
import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";

interface FinanceDashboardStats {
    totalRevenue: number;
    totalExpenses: number;
    pendingInvoices: number;
    netProfit: number;
}

type MetricColor = 'success' | 'error' | 'warning' | 'info';
type ChangeType = 'increase' | 'decrease';

const ArrowUpIcon = ({ className }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
    </svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
    </svg>
);

const TaskIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
    </svg>
);

const AlertIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
    </svg>
);

const DollarIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" />
    </svg>
);

const UserIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
    </svg>
);

interface MetricCardProps {
    title: string;
    value: number | string;
    change: number;
    changeType: ChangeType;
    icon: ComponentType<{ className?: string }>;
    color: MetricColor;
    description: string;
}

const MetricCard = ({
    title,
    value,
    change,
    changeType,
    icon: Icon,
    color,
    description,
}: MetricCardProps) => {
    const getColorClasses = () => {
        switch (color) {
            case 'success': return 'from-green-500 to-emerald-600';
            case 'error': return 'from-red-500 to-rose-600';
            case 'warning': return 'from-yellow-500 to-orange-600';
            case 'info': return 'from-blue-500 to-indigo-600';
            default: return 'from-gray-500 to-gray-600';
        }
    };

    const displayValue = typeof value === 'number' ? value.toLocaleString() : value;

    return (
        <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
            <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

            <div className="relative">
                <div className="flex items-center justify-between mb-4">
                    <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
                        <Icon className="text-white size-7 drop-shadow-sm" />
                    </div>

                    <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
                        changeType === 'increase'
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                    }`}>
                        {changeType === 'increase' ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
                        {Math.abs(change)}%
                    </div>
                </div>

                <div className="space-y-2">
                    <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
                        {title}
                    </p>
                    <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
                        {displayValue}
                    </h3>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {description}
                    </p>
                </div>
            </div>
        </div>
    );
};

export default function FinanceDashboard() {
    const { auth } = usePage().props as any;
    const { data: invoices = [], isLoading: loadingInvoices, error: invoicesError } = useInvoices();
    const { data: expenses = [], isLoading: loadingExpenses, error: expensesError } = useExpenses();
    const [currentTime, setCurrentTime] = useState<Date>(new Date());

    // Update current time every second
    useEffect(() => {
        const updateTime = () => setCurrentTime(new Date());
        updateTime();
        const interval = setInterval(updateTime, 1000);
        return () => clearInterval(interval);
    }, []);

    // Show loading state
    if (loadingInvoices || loadingExpenses) {
        return (
            <AppLayoutERP>
                <Head title="Finance Dashboard - Solespace ERP" />
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-center">
                        <div className="inline-block h-12 w-12 animate-spin rounded-full border-4 border-solid border-blue-600 border-r-transparent mb-4"></div>
                        <p className="text-gray-600 dark:text-gray-400">Loading dashboard...</p>
                    </div>
                </div>
            </AppLayoutERP>
        );
    }

    // Show error state
    if (invoicesError || expensesError) {
        return (
            <AppLayoutERP>
                <Head title="Finance Dashboard - Solespace ERP" />
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-center max-w-md">
                        <div className="text-red-600 dark:text-red-400 mb-4">
                            <svg className="h-16 w-16 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-2">Error Loading Dashboard</h3>
                        <p className="text-gray-600 dark:text-gray-400 mb-4">
                            {(invoicesError as Error)?.message || (expensesError as Error)?.message || 'Failed to load financial data'}
                        </p>
                        <button
                            onClick={() => window.location.reload()}
                            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            Reload Page
                        </button>
                    </div>
                </div>
            </AppLayoutERP>
        );
    }

    // Calculate stats from real data - only count PAID invoices for revenue
    const stats: FinanceDashboardStats = {
        totalRevenue: invoices
            .filter(inv => inv.status === 'paid')
            .reduce((sum, inv) => {
                const total = typeof inv.total === 'string' ? parseFloat(inv.total) : inv.total;
                return sum + (isNaN(total) ? 0 : total);
            }, 0),
        totalExpenses: expenses.reduce((sum, exp) => {
            const amount = typeof exp.amount === 'string' ? parseFloat(exp.amount) : exp.amount;
            return sum + (isNaN(amount) ? 0 : amount);
        }, 0),
        pendingInvoices: invoices.filter((inv) => inv.status === 'draft' || inv.status === 'sent').length,
        netProfit: 0,
    };
    
    stats.netProfit = stats.totalRevenue - stats.totalExpenses;

    const metrics: MetricCardProps[] = [
        {
            title: 'Total Revenue',
            value: `₱${stats.totalRevenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
            change: 15,
            changeType: 'increase',
            icon: DollarIconSvg,
            color: 'success',
            description: `From ${invoices.filter(inv => inv.status === 'paid').length} paid invoices`,
        },
        {
            title: 'Total Expenses',
            value: `₱${stats.totalExpenses.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
            change: 8,
            changeType: 'increase',
            icon: AlertIconSvg,
            color: 'error',
            description: `${expenses.length} expense records`,
        },
        {
            title: 'Pending Invoices',
            value: stats.pendingInvoices,
            change: 3,
            changeType: 'decrease',
            icon: TaskIconSvg,
            color: 'warning',
            description: 'Awaiting payment or processing',
        },
        {
            title: 'Net Profit',
            value: `₱${stats.netProfit.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
            change: 12,
            changeType: stats.netProfit >= 0 ? 'increase' : 'decrease',
            icon: DollarIconSvg,
            color: stats.netProfit >= 0 ? 'success' : 'error',
            description: 'Revenue minus expenses',
        },
    ];

    // Chart data
    const revenueChartOptions: ApexOptions = {
        chart: {
            type: 'area',
            toolbar: { show: false },
            zoom: { enabled: false },
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.4,
                opacityTo: 0.1,
            },
        },
        colors: ['#10b981'],
        xaxis: {
            categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        },
        tooltip: {
            y: {
                formatter: (val) => `₱${val.toLocaleString()}`,
            },
        },
    };

    const revenueChartSeries = [
        {
            name: 'Revenue',
            data: [30000, 40000, 35000, 50000, 49000, 60000],
        },
    ];

    return (
        <AppLayoutERP>
            <Head title="Finance Dashboard - Solespace ERP" />

            <div className="py-8 px-6">
                {/* Header */}
                <div className="mb-8">
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                        Finance Dashboard
                    </h1>
                    <p className="text-gray-600 dark:text-gray-400">
                        Hello, {auth?.user?.name}! Here's your financial overview.
                    </p>
                </div>

                {/* Metrics */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    {metrics.map((metric) => (
                        <MetricCard
                            key={metric.title}
                            title={metric.title}
                            value={metric.value}
                            change={metric.change}
                            changeType={metric.changeType}
                            icon={metric.icon}
                            color={metric.color}
                            description={metric.description}
                        />
                    ))}
                </div>

                {/* Charts Section */}
                <div className="grid grid-cols-1 gap-6">
                    {/* Revenue Trend */}
                    <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden p-6">
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-4">
                            Revenue Trend
                        </h3>
                        <Chart
                            options={revenueChartOptions}
                            series={revenueChartSeries}
                            type="area"
                            height={300}
                        />
                    </div>
                </div>
            </div>
        </AppLayoutERP>
    );
}
