import { AlertCircle, Inbox, LoaderCircle } from 'lucide-react';

type DashboardStateStatus = 'loading' | 'empty' | 'error';

type DashboardStateProps = {
  status: DashboardStateStatus;
  title?: string;
  message?: string;
  onRetry?: () => void;
};

const defaults: Record<DashboardStateStatus, { title: string; message: string }> = {
  loading: { title: 'Loading dashboard', message: 'Gathering the latest records for this view.' },
  empty: { title: 'No records yet', message: 'New activity will appear here once this workflow starts.' },
  error: { title: 'Dashboard data unavailable', message: 'Try again or check your account access.' },
};

export default function DashboardState({ status, title, message, onRetry }: DashboardStateProps) {
  const copy = defaults[status];
  const Icon = status === 'loading' ? LoaderCircle : status === 'error' ? AlertCircle : Inbox;
  const isError = status === 'error';

  return (
    <div
      role={isError ? 'alert' : 'status'}
      aria-live="polite"
      className="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center dark:border-gray-700 dark:bg-white/[0.02]"
    >
      <Icon className={'mx-auto h-8 w-8 text-gray-500 dark:text-gray-400 ' + (status === 'loading' ? 'animate-spin motion-reduce:animate-none' : '')} aria-hidden="true" />
      <h3 className="mt-3 text-base font-semibold text-gray-950 dark:text-white">{title ?? copy.title}</h3>
      <p className="mx-auto mt-1 max-w-md text-sm leading-6 text-gray-500 dark:text-gray-400">{message ?? copy.message}</p>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-5 inline-flex min-h-11 items-center justify-center rounded-full bg-gray-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200 dark:focus-visible:ring-white"
        >
          Try again
        </button>
      )}
    </div>
  );
}
