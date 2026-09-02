import type { ComponentType, ReactNode } from 'react';
import { Link } from '@inertiajs/react';

export type DashboardMetricTone = 'neutral' | 'success' | 'warning' | 'danger';

type DashboardMetricCardProps = {
  label: string;
  value: ReactNode;
  description: string;
  context?: string;
  icon: ComponentType<{ className?: string }>;
  tone?: DashboardMetricTone;
  href?: string | null;
  testId?: string;
  iconTestId?: string;
  progress?: number;
};

const toneClasses: Record<DashboardMetricTone, string> = {
  neutral: 'bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white',
  success: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
  warning: 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
  danger: 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
};

export default function DashboardMetricCard({
  label,
  value,
  description,
  context,
  icon: Icon,
  tone = 'neutral',
  href,
  testId = 'dashboard-metric-card',
  iconTestId,
  progress,
}: DashboardMetricCardProps) {
  const card = (
    <article
      data-testid={testId}
      aria-label={label}
      className="metrics-card h-full rounded-2xl border border-gray-200 bg-white p-5 transition-colors hover:border-gray-400 motion-reduce:transition-none dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-600"
    >
      <div className="flex items-start justify-between gap-4">
        <div data-testid={iconTestId} className={'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ' + toneClasses[tone]}>
          <Icon className="h-5 w-5" />
        </div>
        <div className="flex items-center gap-2">
          {context && <span className="text-right text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">{context}</span>}
        </div>
      </div>
      <p className="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">{label}</p>
      <p className="mt-2 text-3xl font-bold tracking-tight text-gray-950 tabular-nums dark:text-white">{value}</p>
      <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{description}</p>
      {progress !== undefined && (
        <div className="mt-4" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress}>
          <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div className="h-full rounded-full bg-gray-950 transition-[width] duration-300 motion-reduce:transition-none dark:bg-white" style={{ width: `${Math.max(0, Math.min(100, progress))}%` }} />
          </div>
        </div>
      )}
    </article>
  );

  if (!href) {
    return card;
  }

  return (
    <Link
      href={href}
      aria-label={'Open ' + label}
      className="block h-full rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950"
    >
      {card}
    </Link>
  );
}
