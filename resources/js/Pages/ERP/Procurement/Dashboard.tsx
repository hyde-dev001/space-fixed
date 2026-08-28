import { Head, usePage } from '@inertiajs/react';
import {
  ArrowUpRight,
  ClipboardList,
  PackageCheck,
  ShoppingCart,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';

type MetricTone = 'blue' | 'green' | 'slate';

type DashboardCard = {
  label: string;
  value: number | string;
  description: string;
};

type ProcurementDashboardProps = {
  dashboard?: {
    cards?: DashboardCard[];
  };
};

const toneClasses: Record<MetricTone, { icon: string; value: string; border: string }> = {
  blue: {
    icon: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
    value: 'text-blue-700 dark:text-blue-300',
    border: 'border-blue-100 dark:border-blue-900/40',
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

function presentationFor(label: string): { context: string; icon: LucideIcon; tone: MetricTone } {
  const normalizedLabel = label.toLowerCase();

  if (normalizedLabel.includes('request')) {
    return { context: 'Workflow', icon: ClipboardList, tone: 'blue' };
  }

  if (normalizedLabel.includes('order')) {
    return { context: 'Commitments', icon: ShoppingCart, tone: 'green' };
  }

  return { context: 'Current', icon: PackageCheck, tone: 'slate' };
}

function MetricCard({ card }: { card: DashboardCard }) {
  const presentation = presentationFor(card.label);
  const Icon = presentation.icon;
  const tone = toneClasses[presentation.tone];

  return (
    <article
      aria-label={card.label}
      className={`rounded-2xl border bg-white p-5 shadow-sm transition-shadow duration-200 hover:shadow-md motion-reduce:transition-none dark:bg-white/[0.03] ${tone.border}`}
    >
      <div className="flex items-start justify-between gap-4">
        <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${tone.icon}`}>
          <Icon className="h-5 w-5" aria-hidden="true" />
        </div>
        <span className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400 dark:text-gray-500">
          {presentation.context}
        </span>
      </div>
      <p className="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">{card.label}</p>
      <p className={`mt-2 text-3xl font-bold tracking-tight tabular-nums ${tone.value}`}>{card.value}</p>
      <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{card.description}</p>
    </article>
  );
}

export default function ProcurementDashboard() {
  const { dashboard } = usePage<ProcurementDashboardProps>().props;
  const cards = dashboard?.cards ?? [];

  return (
    <AppLayoutERP>
      <Head title="Procurement Dashboard - SoleSpace ERP" />
      <div data-testid="procurement-dashboard" className="w-full space-y-6 px-4 py-6 sm:px-6 lg:py-8">
        <section className="overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-br from-white via-blue-50/70 to-white p-6 shadow-sm dark:border-gray-800 dark:from-gray-900 dark:via-gray-900 dark:to-gray-900 sm:p-8">
          <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
            <div className="flex min-w-0 items-start gap-4">
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-600/20">
                <ShoppingCart className="h-7 w-7" aria-hidden="true" />
              </div>
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600 dark:text-blue-400">ERP module</p>
                <h1 className="mt-2 text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-4xl">Procurement Dashboard</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                  Keep purchasing requests organized, monitor supplier commitments, and review procurement activity for your shop.
                </p>
              </div>
            </div>
            <div className="inline-flex w-fit items-center gap-3 rounded-2xl border border-blue-200 bg-white/80 px-4 py-3 text-left shadow-sm dark:border-blue-900/50 dark:bg-white/[0.04]">
              <span className="flex h-3 w-3 shrink-0 rounded-full bg-emerald-500 ring-4 ring-emerald-500/15" aria-hidden="true" />
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700 dark:text-blue-300">Operational snapshot</p>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Current shop procurement records</p>
              </div>
            </div>
          </div>
          <div className="mt-6 flex flex-wrap gap-3 border-t border-blue-100 pt-5 dark:border-gray-800">
            <span className="inline-flex min-h-9 items-center gap-2 rounded-full bg-blue-100 px-3 py-1.5 text-xs font-semibold text-blue-800 dark:bg-blue-500/10 dark:text-blue-300">
              <ClipboardList className="h-4 w-4" aria-hidden="true" />
              {cards.length} tracked workstreams
            </span>
          </div>
        </section>

        <section aria-labelledby="procurement-health-title" className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-7">
          <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Procurement health</p>
              <h2 id="procurement-health-title" className="mt-2 text-xl font-bold tracking-tight text-gray-950 dark:text-white">Where purchasing stands</h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Current tenant-scoped counts from the procurement read models.</p>
            </div>
            <span className="text-xs font-medium text-gray-400 dark:text-gray-500">Updated from current records</span>
          </div>
          <div className="mt-6 grid gap-4 md:grid-cols-2">
            {cards.map((card) => <MetricCard key={card.label} card={card} />)}
          </div>
        </section>

      </div>
    </AppLayoutERP>
  );
}
