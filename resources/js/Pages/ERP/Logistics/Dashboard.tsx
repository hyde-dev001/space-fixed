import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  ClipboardList,
  Clock3,
  PackageCheck,
  Truck,
  UserRound,
  Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import type { LogisticsStats } from '@/types/logistics';
import type { ErpCapabilities } from '@/types/erp';
import { erpUrl } from '@/utils/erpCapabilities';

type MetricTone = 'blue' | 'amber' | 'green' | 'slate';

type DashboardMetric = {
  label: string;
  context: string;
  value: string | number;
  description: string;
  icon: LucideIcon;
  tone: MetricTone;
  progress?: number;
};

type AttentionItem = {
  label: string;
  value: number;
  summary: string;
  icon: LucideIcon;
  tone: MetricTone;
};

const toneClasses: Record<MetricTone, { icon: string; value: string; border: string }> = {
  blue: {
    icon: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
    value: 'text-blue-700 dark:text-blue-300',
    border: 'border-blue-100 dark:border-blue-900/40',
  },
  amber: {
    icon: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    value: 'text-amber-700 dark:text-amber-300',
    border: 'border-amber-200 dark:border-amber-900/50',
  },
  green: {
    icon: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    value: 'text-emerald-700 dark:text-emerald-300',
    border: 'border-emerald-100 dark:border-emerald-900/40',
  },
  slate: {
    icon: 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
    value: 'text-gray-900 dark:text-white',
    border: 'border-gray-200 dark:border-gray-800',
  },
};

const pluralize = (value: number, singular: string, plural: string) => `${value} ${value === 1 ? singular : plural}`;

function MetricCard({ metric }: { metric: DashboardMetric }) {
  const Icon = metric.icon;
  const tone = toneClasses[metric.tone];

  return (
    <article
      aria-label={metric.label}
      className={`rounded-2xl border bg-white p-5 shadow-sm transition-shadow duration-200 hover:shadow-md motion-reduce:transition-none dark:bg-white/[0.03] ${tone.border}`}
    >
      <div className="flex items-start justify-between gap-4">
        <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${tone.icon}`}>
          <Icon className="h-5 w-5" aria-hidden="true" />
        </div>
        <span className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400 dark:text-gray-500">{metric.context}</span>
      </div>
      <p className="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">{metric.label}</p>
      <p className={`mt-2 text-3xl font-bold tracking-tight ${tone.value}`}>{metric.value}</p>
      <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{metric.description}</p>
      {metric.progress !== undefined && (
        <div className="mt-4">
          <div
            role="progressbar"
            aria-label={metric.label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={metric.progress}
            className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"
          >
            <div
              className="h-full rounded-full bg-emerald-500 transition-[width] duration-300 motion-reduce:transition-none"
              style={{ width: `${metric.progress}%` }}
            />
          </div>
        </div>
      )}
    </article>
  );
}

function AttentionCard({ item, href }: { item: AttentionItem; href: string | null }) {
  const Icon = item.icon;
  const tone = toneClasses[item.tone];
  const cardClassName = `group flex min-h-28 items-center justify-between gap-4 rounded-xl border bg-white p-4 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 motion-reduce:transform-none motion-reduce:transition-none dark:bg-white/[0.03] ${tone.border}`;
  const content = (
    <>
      <div className="flex min-w-0 items-start gap-3">
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tone.icon}`}>
          <Icon className="h-5 w-5" aria-hidden="true" />
        </div>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-gray-900 dark:text-white">{item.label}</p>
          <p className="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{item.summary}</p>
        </div>
      </div>
      <span className={`shrink-0 text-2xl font-bold ${tone.value}`}>{item.value}</span>
    </>
  );

  return href ? (
    <Link href={href} className={cardClassName} aria-label={`Review ${item.label.toLowerCase()}`}>
      {content}
    </Link>
  ) : (
    <div className={cardClassName}>{content}</div>
  );
}

export default function Dashboard() {
  const { stats, auth, erpCapabilities, canViewShipments = false } = usePage<{
    stats: LogisticsStats;
    auth?: { permissions?: string[]; erpActor?: { ownerMode?: boolean } };
    erpCapabilities?: ErpCapabilities;
    canViewShipments?: boolean;
  }>().props;
  const ownerMode = auth?.erpActor?.ownerMode === true;
  const shipmentsUrl = erpUrl(erpCapabilities, 'GET:erp.logistics.shipments')
    ?? (!ownerMode && canViewShipments ? '/erp/logistics/shipments' : null);
  const successRate = Math.max(0, Math.min(100, stats.delivery_success_rate));

  const healthMetrics: DashboardMetric[] = [
    {
      label: 'Active shipments',
      context: 'Current',
      value: stats.active,
      description: 'Shipments currently moving through delivery operations.',
      icon: Truck,
      tone: 'blue',
    },
    {
      label: 'Due today',
      context: 'Today',
      value: stats.due_today,
      description: 'Stops scheduled for delivery before the day closes.',
      icon: Clock3,
      tone: 'slate',
    },
    {
      label: 'Overdue deliveries',
      context: 'Today',
      value: stats.overdue,
      description: stats.overdue > 0 ? 'Review these stops before they become escalations.' : 'No delivery dates have slipped.',
      icon: AlertTriangle,
      tone: stats.overdue > 0 ? 'amber' : 'green',
    },
    {
      label: 'Delivery success rate',
      context: 'Overall',
      value: `${successRate}%`,
      description: 'Delivered legs compared with failed delivery attempts.',
      icon: PackageCheck,
      tone: successRate >= 90 ? 'green' : 'amber',
      progress: successRate,
    },
  ];

  const attentionItems: AttentionItem[] = [
    {
      label: 'Overdue deliveries',
      value: stats.overdue,
      summary: pluralize(stats.overdue, 'delivery needs review', 'deliveries need review'),
      icon: AlertTriangle,
      tone: 'amber',
    },
    {
      label: 'Unassigned stops',
      value: stats.unassigned,
      summary: pluralize(stats.unassigned, 'stop needs a rider', 'stops need a rider'),
      icon: UserRound,
      tone: 'blue',
    },
    {
      label: 'Failed attempts',
      value: stats.failed_attempts,
      summary: pluralize(stats.failed_attempts, 'attempt needs follow-up', 'attempts need follow-up'),
      icon: Activity,
      tone: 'amber',
    },
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
      <div data-testid="logistics-dashboard" className="w-full space-y-6 px-4 py-6 sm:px-6 lg:py-8">
        <section className="overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-br from-white via-blue-50/70 to-white p-6 shadow-sm dark:border-gray-800 dark:from-gray-900 dark:via-gray-900 dark:to-gray-900 sm:p-8">
          <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
            <div className="flex min-w-0 items-start gap-4">
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-600/20">
                <Truck className="h-7 w-7" aria-hidden="true" />
              </div>
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600 dark:text-blue-400">ERP module</p>
                <h1 className="mt-2 text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-4xl">Logistics Dashboard</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                  Keep deliveries moving, monitor rider capacity, and catch exceptions before they become delays.
                </p>
              </div>
            </div>
            <div className="inline-flex w-fit items-center gap-3 rounded-2xl border border-blue-200 bg-white/80 px-4 py-3 text-left shadow-sm dark:border-blue-900/50 dark:bg-white/[0.04]">
              <span className="flex h-3 w-3 shrink-0 rounded-full bg-emerald-500 ring-4 ring-emerald-500/15" aria-hidden="true" />
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700 dark:text-blue-300">Operational snapshot</p>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Current shop logistics records</p>
              </div>
            </div>
          </div>
          <div className="mt-6 flex flex-wrap gap-3 border-t border-blue-100 pt-5 dark:border-gray-800">
            <span className="inline-flex min-h-9 items-center gap-2 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-semibold text-blue-800 dark:bg-blue-500/10 dark:text-blue-300">
              <Activity className="h-4 w-4" aria-hidden="true" />
              {pluralize(stats.active, 'active shipment', 'active shipments')}
            </span>
            <span className="inline-flex min-h-9 items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
              <Clock3 className="h-4 w-4" aria-hidden="true" />
              {pluralize(stats.due_today, 'stop due today', 'stops due today')}
            </span>
          </div>
        </section>

        <section aria-labelledby="delivery-health-title" className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-7">
          <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Delivery health</p>
              <h2 id="delivery-health-title" className="mt-2 text-xl font-bold tracking-tight text-gray-950 dark:text-white">Where operations stand today</h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">The most useful signals for prioritizing dispatch work.</p>
            </div>
            <span className="text-xs font-medium text-gray-400 dark:text-gray-500">Updated from current records</span>
          </div>
          <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {healthMetrics.map((metric) => <MetricCard key={metric.label} metric={metric} />)}
          </div>
        </section>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(21rem,0.85fr)]">
          <section aria-labelledby="needs-attention-title" className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-7">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Priority queue</p>
              <h2 id="needs-attention-title" className="mt-2 text-xl font-bold tracking-tight text-gray-950 dark:text-white">Needs attention</h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Focus here first to keep delivery promises on track.</p>
            </div>
            {hasAttention ? (
              <div className="mt-6 grid gap-3 md:grid-cols-3 xl:grid-cols-1 2xl:grid-cols-3">
                {attentionItems.map((item) => <AttentionCard key={item.label} item={item} href={shipmentsUrl} />)}
              </div>
            ) : (
              <div className="mt-6 flex items-start gap-3 rounded-xl border border-emerald-100 bg-emerald-50/70 p-4 dark:border-emerald-900/40 dark:bg-emerald-500/10">
                <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-300" aria-hidden="true" />
                <div>
                  <p className="text-sm font-semibold text-emerald-900 dark:text-emerald-200">No urgent delivery exceptions right now.</p>
                  <p className="mt-1 text-xs leading-5 text-emerald-800/80 dark:text-emerald-300/80">Your current delivery queue is clear of overdue, unassigned, and failed-attempt items.</p>
                </div>
              </div>
            )}
          </section>

          <section aria-labelledby="operations-snapshot-title" className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-7">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Operations snapshot</p>
              <h2 id="operations-snapshot-title" className="mt-2 text-xl font-bold tracking-tight text-gray-950 dark:text-white">Flow at a glance</h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">A quick read of the wider logistics workload.</p>
            </div>
            <dl className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
              {operationalSnapshot.map((item) => {
                const Icon = item.icon;
                return (
                  <div key={item.label} className="rounded-xl border border-gray-100 bg-gray-50/80 p-4 dark:border-gray-800 dark:bg-gray-900/60">
                    <div className="flex items-center justify-between gap-3">
                      <dt className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                        <Icon className="h-4 w-4 text-blue-600 dark:text-blue-400" aria-hidden="true" />
                        {item.label}
                      </dt>
                      <dd className="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{item.value}</dd>
                    </div>
                    <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{item.description}</p>
                  </div>
                );
              })}
            </dl>
          </section>
        </div>

      </div>
    </AppLayoutERP>
  );
}
