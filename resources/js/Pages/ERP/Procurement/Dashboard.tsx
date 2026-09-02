import { Head, router, usePage } from '@inertiajs/react';
import type { ApexOptions } from 'apexcharts';
import Chart from 'react-apexcharts';
import {
  ArrowUpRight,
  BarChart3,
  ClipboardList,
  Clock3,
  PackageCheck,
  RefreshCw,
  ShoppingCart,
  Truck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import type {
  ProcurementDashboard,
  ProcurementDashboardActivity,
  ProcurementDashboardMonth,
  ProcurementDashboardStatus,
} from '../../../types/procurement';

interface ProcurementDashboardPageProps {
  dashboard?: ProcurementDashboard;
}

type SummaryCard = {
  key: string;
  label: string;
  value: number | string;
  description: string;
  context: string;
  icon: LucideIcon;
  format: (value: number | string) => string;
};

type StatusPanelProps = {
  id: string;
  title: string;
  description: string;
  statuses: ProcurementDashboardStatus[];
  barClass: string;
};

const numberFormatter = new Intl.NumberFormat('en-PH');
const moneyFormatter = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

const formatNumber = (value: number | string): string => {
  const numericValue = Number(value);

  return Number.isFinite(numericValue) ? numberFormatter.format(numericValue) : '0';
};

const formatMoney = (value: number | string): string => {
  const numericValue = Number(value);

  return Number.isFinite(numericValue) ? moneyFormatter.format(numericValue) : moneyFormatter.format(0);
};

const formatDate = (value: string): string => {
  const date = new Date(value);

  return Number.isNaN(date.getTime())
    ? '-'
    : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatDateTime = (value: string): string => {
  const date = new Date(value);

  return Number.isNaN(date.getTime())
    ? '-'
    : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
};

const statusBarClasses = {
  requests: 'bg-[#111111] dark:bg-white',
  orders: 'bg-[#707072] dark:bg-gray-300',
} as const;

function SummaryCardView({ card }: { card: SummaryCard }) {
  const Icon = card.icon;

  return (
    <article
      data-testid="procurement-summary-card"
      aria-label={card.label}
      className="rounded-2xl border border-gray-200 bg-white p-5 transition-colors hover:border-gray-400 motion-reduce:transition-none dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-600"
    >
      <div className="flex items-start justify-between gap-4">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-[#111111] dark:bg-white/10 dark:text-white">
          <Icon className="h-5 w-5" aria-hidden="true" />
        </div>
        <span className="text-right text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">
          {card.context}
        </span>
      </div>
      <p className="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">{card.label}</p>
      <p className="mt-2 text-3xl font-bold tracking-tight text-[#111111] tabular-nums dark:text-white">
        {card.format(card.value)}
      </p>
      <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{card.description}</p>
    </article>
  );
}

function StatusPanel({ id, title, description, statuses, barClass }: StatusPanelProps) {
  const total = statuses.reduce((sum, status) => sum + status.count, 0);

  return (
    <section
      aria-labelledby={id}
      className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
    >
      <div className="flex items-start gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-[#111111] dark:bg-white/10 dark:text-white">
          <BarChart3 className="h-5 w-5" aria-hidden="true" />
        </div>
        <div>
          <h2 id={id} className="text-lg font-semibold text-gray-950 dark:text-white">{title}</h2>
          <p className="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>

      <div className="mt-6 space-y-4">
        {statuses.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">No status data available.</p>
        ) : (
          statuses.map((status) => {
            const percentage = total > 0 ? Math.round((status.count / total) * 100) : 0;

            return (
              <div key={status.key}>
                <div className="flex items-center justify-between gap-4 text-sm">
                  <span className="min-w-0 truncate font-medium text-gray-700 dark:text-gray-200">{status.label}</span>
                  <span className="shrink-0 font-semibold tabular-nums text-gray-950 dark:text-white">
                    {formatNumber(status.count)}
                  </span>
                </div>
                <div
                  role="progressbar"
                  aria-label={`${status.label}: ${status.count}`}
                  aria-valuemin={0}
                  aria-valuemax={total || 1}
                  aria-valuenow={status.count}
                  className="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"
                >
                  <div
                    className={`h-full rounded-full transition-[width] duration-300 motion-reduce:transition-none ${barClass}`}
                    style={{ width: `${percentage}%` }}
                  />
                </div>
              </div>
            );
          })
        )}
      </div>
    </section>
  );
}

function TrendSummary({ months }: { months: ProcurementDashboardMonth[] }) {
  return (
    <div data-testid="procurement-trend-summary" className="sr-only">
      <p>Six-month procurement activity summary</p>
      <ul>
        {months.map((month) => (
          <li data-testid="procurement-trend-point" key={month.start}>
            {month.label}: {formatNumber(month.purchase_requests)} purchase requests, {formatNumber(month.purchase_orders)} purchase orders
          </li>
        ))}
      </ul>
    </div>
  );
}

function ActivityRow({ activity }: { activity: ProcurementDashboardActivity }) {
  const reference = activity.url ? (
    <a
      href={activity.url}
      className="font-semibold text-[#111111] underline decoration-gray-300 underline-offset-4 hover:decoration-[#111111] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white dark:focus-visible:ring-white"
    >
      {activity.reference}
    </a>
  ) : (
    <span className="font-semibold text-[#111111] dark:text-white">{activity.reference}</span>
  );

  return (
    <tr className="border-t border-gray-100 dark:border-gray-800">
      <td className="px-0 py-4 pr-4 align-top text-sm text-gray-500 dark:text-gray-400">{activity.type}</td>
      <td className="px-4 py-4 align-top">
        {reference}
        <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{activity.description}</p>
      </td>
      <td className="px-4 py-4 align-top">
        <span className="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
          {activity.status}
        </span>
      </td>
      <td className="px-4 py-4 text-right align-top text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
        {formatMoney(activity.amount)}
      </td>
      <td className="whitespace-nowrap px-0 py-4 pl-4 text-right align-top text-xs text-gray-500 dark:text-gray-400">
        <time dateTime={activity.occurred_at}>{formatDate(activity.occurred_at)}</time>
      </td>
    </tr>
  );
}

export default function ProcurementDashboard() {
  const { dashboard } = usePage<ProcurementDashboardPageProps>().props;
  const summary = dashboard?.summary;
  const months = dashboard?.trend?.months ?? [];
  const requestStatuses = dashboard?.request_statuses ?? [];
  const orderStatuses = dashboard?.order_statuses ?? [];
  const activity = dashboard?.recent_activity ?? [];
  const links = dashboard?.links;

  const summaryCards: SummaryCard[] = [
    {
      key: 'purchase_requests',
      label: 'Purchase requests',
      value: summary?.purchase_requests ?? 0,
      description: 'Requests currently in the procurement flow',
      context: 'Workflow',
      icon: ClipboardList,
      format: formatNumber,
    },
    {
      key: 'awaiting_review',
      label: 'Awaiting review',
      value: summary?.awaiting_review ?? 0,
      description: 'Requests that need the next approval step',
      context: 'Attention',
      icon: Clock3,
      format: formatNumber,
    },
    {
      key: 'purchase_orders',
      label: 'Purchase orders',
      value: summary?.purchase_orders ?? 0,
      description: 'Orders placed for this shop',
      context: 'Commitments',
      icon: ShoppingCart,
      format: formatNumber,
    },
    {
      key: 'open_order_value',
      label: 'Open order value',
      value: summary?.open_order_value ?? 0,
      description: 'Value of sent and in-progress orders',
      context: 'Exposure',
      icon: PackageCheck,
      format: formatMoney,
    },
  ];

  const chartSeries = [
    { name: 'Purchase requests', data: months.map((month) => month.purchase_requests) },
    { name: 'Purchase orders', data: months.map((month) => month.purchase_orders) },
  ];
  const chartOptions: ApexOptions = {
    chart: {
      type: 'area',
      toolbar: { show: false },
      zoom: { enabled: false },
      fontFamily: 'inherit',
    },
    colors: ['#111111', '#707072'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'solid', opacity: 0.12 },
    grid: { borderColor: '#e5e5e5', strokeDashArray: 4 },
    legend: { position: 'top', horizontalAlign: 'left' },
    xaxis: {
      categories: months.map((month) => month.label),
      labels: { style: { colors: '#707072' } },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: {
      min: 0,
      forceNiceScale: true,
      labels: { formatter: (value) => formatNumber(value) },
    },
    tooltip: { y: { formatter: (value) => `${formatNumber(value)} records` } },
  };

  const handleRefresh = () => {
    router.reload({ only: ['dashboard'], preserveScroll: true });
  };

  return (
    <AppLayoutERP>
      <Head title="Procurement Dashboard - SoleSpace ERP" />
      <div data-testid="procurement-dashboard" className="w-full space-y-8 px-4 py-6 sm:px-6 lg:py-8">
        <section className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-8">
          <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
            <div className="flex min-w-0 items-start gap-4">
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gray-100 text-[#111111] dark:bg-white/10 dark:text-white">
                <ShoppingCart className="h-7 w-7" aria-hidden="true" />
              </div>
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[#111111] dark:text-gray-300">ERP module</p>
                <h1 className="mt-2 text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-4xl">
                  {dashboard?.title ?? 'Procurement Dashboard'}
                </h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                  {dashboard?.description ?? 'Monitor purchasing activity for your shop.'}
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <button
                type="button"
                onClick={handleRefresh}
                className="inline-flex min-h-11 items-center gap-2 rounded-full bg-[#111111] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#39393b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 motion-reduce:transition-none dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950"
              >
                <RefreshCw className="h-4 w-4" aria-hidden="true" />
                Refresh data
              </button>
            </div>
          </div>

          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
            <div className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
              <span className="flex h-2.5 w-2.5 rounded-full bg-[#007d48]" aria-hidden="true" />
              <span>Operational snapshot</span>
              {dashboard?.refreshed_at && <span aria-hidden="true">·</span>}
              {dashboard?.refreshed_at && <time dateTime={dashboard.refreshed_at}>Updated {formatDateTime(dashboard.refreshed_at)}</time>}
            </div>
            <nav aria-label="Procurement shortcuts" className="flex flex-wrap gap-2">
              {links?.purchase_requests && (
                <a
                  href={links.purchase_requests}
                  className="inline-flex min-h-10 items-center gap-1.5 rounded-full border border-gray-300 px-4 py-2 text-xs font-semibold text-[#111111] transition-colors hover:border-[#111111] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 motion-reduce:transition-none dark:border-gray-700 dark:text-white dark:hover:border-white dark:focus-visible:ring-white"
                >
                  View purchase requests
                  <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                </a>
              )}
              {links?.purchase_orders && (
                <a
                  href={links.purchase_orders}
                  className="inline-flex min-h-10 items-center gap-1.5 rounded-full border border-gray-300 px-4 py-2 text-xs font-semibold text-[#111111] transition-colors hover:border-[#111111] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 motion-reduce:transition-none dark:border-gray-700 dark:text-white dark:hover:border-white dark:focus-visible:ring-white"
                >
                  View purchase orders
                  <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                </a>
              )}
            </nav>
          </div>
        </section>

        <section aria-labelledby="procurement-summary-title">
          <div className="mb-4 flex items-end justify-between gap-4">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Procurement health</p>
              <h2 id="procurement-summary-title" className="mt-2 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">Where purchasing stands</h2>
            </div>
            <span className="hidden text-xs text-gray-400 dark:text-gray-500 sm:inline">Tenant-scoped current totals</span>
          </div>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {summaryCards.map((card) => <SummaryCardView key={card.key} card={card} />)}
          </div>
        </section>

        <section
          aria-labelledby="procurement-activity-title"
          className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-7"
        >
          <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
            <div>
              <div className="flex items-center gap-2">
                <BarChart3 className="h-5 w-5 text-[#111111] dark:text-white" aria-hidden="true" />
                <h2 id="procurement-activity-title" className="text-lg font-semibold text-gray-950 dark:text-white">Procurement activity</h2>
              </div>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Purchase requests and orders created over the last six months.</p>
            </div>
            <span className="text-xs font-medium text-gray-500 dark:text-gray-400">{dashboard?.trend?.period_label ?? 'Last 6 months'}</span>
          </div>
          <div data-testid="procurement-activity-chart" className="mt-6 min-h-[280px] motion-reduce:transition-none">
            <Chart options={chartOptions} series={chartSeries} type="area" height={300} />
          </div>
          <TrendSummary months={months} />
        </section>

        <div className="grid gap-6 lg:grid-cols-2">
          <StatusPanel
            id="procurement-request-status-title"
            title="Purchase request status"
            description="Requests grouped by their current workflow stage."
            statuses={requestStatuses}
            barClass={statusBarClasses.requests}
          />
          <StatusPanel
            id="procurement-order-status-title"
            title="Purchase order status"
            description="Orders grouped by their current commitment stage."
            statuses={orderStatuses}
            barClass={statusBarClasses.orders}
          />
        </div>

        <section
          aria-labelledby="procurement-recent-activity-title"
          className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-7"
        >
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-[#111111] dark:bg-white/10 dark:text-white">
              <Truck className="h-5 w-5" aria-hidden="true" />
            </div>
            <div>
              <h2 id="procurement-recent-activity-title" className="text-lg font-semibold text-gray-950 dark:text-white">Recent activity</h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">The latest purchasing records for this shop.</p>
            </div>
          </div>

          {activity.length === 0 ? (
            <div className="mt-6 rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center dark:border-gray-700 dark:bg-white/[0.02]">
              <PackageCheck className="mx-auto h-8 w-8 text-gray-400 dark:text-gray-500" aria-hidden="true" />
              <h3 className="mt-3 text-base font-semibold text-gray-900 dark:text-white">No recent procurement activity</h3>
              <p className="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">New purchase requests and orders will appear here once your team starts a workflow.</p>
              <div className="mt-5 flex flex-wrap justify-center gap-3">
                {links?.purchase_requests && <a href={links.purchase_requests} className="inline-flex min-h-11 items-center rounded-full bg-[#111111] px-5 py-2.5 text-sm font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950">View purchase requests</a>}
                {links?.purchase_orders && <a href={links.purchase_orders} className="inline-flex min-h-11 items-center rounded-full bg-gray-100 px-5 py-2.5 text-sm font-semibold text-[#111111] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 dark:bg-white/10 dark:text-white dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950">View purchase orders</a>}
              </div>
            </div>
          ) : (
            <div className="mt-6 overflow-x-auto">
              <table className="min-w-[720px] w-full text-left">
                <caption className="sr-only">Recent procurement activity</caption>
                <thead>
                  <tr className="text-[11px] font-semibold uppercase tracking-[0.14em] text-gray-400 dark:text-gray-500">
                    <th scope="col" className="px-0 pb-3 pr-4">Type</th>
                    <th scope="col" className="px-4 pb-3">Reference</th>
                    <th scope="col" className="px-4 pb-3">Status</th>
                    <th scope="col" className="px-4 pb-3 text-right">Amount</th>
                    <th scope="col" className="px-0 pb-3 pl-4 text-right">Date</th>
                  </tr>
                </thead>
                <tbody>{activity.map((item) => <ActivityRow key={`${item.type}-${item.reference}`} activity={item} />)}</tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </AppLayoutERP>
  );
}
