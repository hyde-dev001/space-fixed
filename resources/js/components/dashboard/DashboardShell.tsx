import type { ComponentType, ReactNode } from 'react';
import { RefreshCw } from 'lucide-react';

type DashboardShellProps = {
  children: ReactNode;
  title: string;
  description: string;
  testId?: string;
  headerTestId?: string;
  icon?: ComponentType<{ className?: string }>;
  snapshotLabel?: string;
  snapshotDescription?: string;
  refreshedAt?: string | null;
  onRefresh?: () => void;
  isRefreshing?: boolean;
  actions?: ReactNode;
};

const formatSnapshotTime = (value?: string | null): string | null => {
  if (!value) {
    return null;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
};

export default function DashboardShell({
  children,
  title,
  description,
  testId,
  headerTestId,
  icon: Icon,
  snapshotLabel = 'Operational snapshot',
  snapshotDescription = 'Current shop records',
  refreshedAt,
  onRefresh,
  isRefreshing = false,
  actions,
}: DashboardShellProps) {
  const snapshotTime = formatSnapshotTime(refreshedAt);

  return (
    <div data-testid={testId} className="w-full space-y-6">
      <section data-testid={headerTestId} className={'rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-8 ' + (headerTestId ? 'metrics-card' : '')}>
        <div className="flex flex-col justify-between gap-6 lg:flex-row lg:items-start">
          <div className="flex min-w-0 items-start gap-4">
            {Icon && (
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white">
                <Icon className="h-7 w-7" />
              </div>
            )}
            <div className="min-w-0">
              <h1 className="text-3xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-4xl">{title}</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">{description}</p>
            </div>
          </div>

          {(actions || onRefresh) && (
            <div className="flex flex-wrap items-center gap-3">
              {actions}
              {onRefresh && (
                <button
                  type="button"
                  onClick={onRefresh}
                  disabled={isRefreshing}
                  aria-busy={isRefreshing}
                  aria-label="Refresh dashboard data"
                  className="inline-flex min-h-11 items-center gap-2 rounded-full bg-gray-950 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transition-none dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200 dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950"
                >
                  <RefreshCw className={isRefreshing ? 'h-4 w-4 animate-spin motion-reduce:animate-none' : 'h-4 w-4'} aria-hidden="true" />
                  {isRefreshing ? 'Refreshing…' : 'Refresh data'}
                </button>
              )}
            </div>
          )}
        </div>

        <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
          <div className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span className="h-2.5 w-2.5 rounded-full bg-emerald-600" aria-hidden="true" />
            <span>{snapshotLabel}</span>
            {snapshotTime && <span aria-hidden="true">·</span>}
            {snapshotTime && <time dateTime={refreshedAt ?? undefined}>Updated {snapshotTime}</time>}
          </div>
          <span className="text-xs text-gray-400 dark:text-gray-500">{snapshotDescription}</span>
        </div>
      </section>

      {children}
    </div>
  );
}
