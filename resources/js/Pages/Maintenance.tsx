import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useMaintenance } from '../providers/MaintenanceProvider';
import { MaintenanceSnapshot } from '../types/maintenance';

type MaintenancePageProps = {
  status: MaintenanceSnapshot;
  safe_return_to: string;
};

function formatDate(value: string): string {
  return new Date(value).toLocaleString('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
  });
}

function formatDuration(milliseconds: number): string {
  const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  return hours > 0
    ? `${hours}h ${String(minutes).padStart(2, '0')}m ${String(seconds).padStart(2, '0')}s`
    : `${minutes}m ${String(seconds).padStart(2, '0')}s`;
}

function isWindowState(state: MaintenanceSnapshot): state is Extract<MaintenanceSnapshot, { starts_at: string }> {
  return state.state === 'scheduled' || state.state === 'active';
}

export default function Maintenance({ status, safe_return_to }: MaintenancePageProps): React.JSX.Element {
  const { state, refresh, refreshError, serverNow } = useMaintenance();
  const [, setTick] = useState(0);
  const displayState = state.state === 'unavailable' && (status.state === 'scheduled' || status.state === 'active')
    ? status
    : state;

  useEffect(() => {
    if (!isWindowState(displayState)) {
      return;
    }

    const timer = window.setInterval(() => setTick((value) => value + 1), 1000);
    return () => window.clearInterval(timer);
  }, [displayState]);

  const safeReturnPath = safe_return_to.startsWith('/') && !safe_return_to.startsWith('//')
    ? safe_return_to
    : '/';
  const remaining = isWindowState(displayState)
    ? Math.max(0, Date.parse(displayState.ends_at) - serverNow())
    : 0;

  const handleSafeReturn = () => {
    router.visit(safeReturnPath, { method: 'get', replace: true });
  };

  return (
    <>
      <Head title="Maintenance" />
      <main className="min-h-screen bg-gray-50 px-4 py-10 text-gray-900 dark:bg-gray-950 dark:text-white sm:px-6 lg:px-8">
        <div className="mx-auto flex min-h-[80vh] max-w-3xl items-center justify-center">
          <section className="w-full rounded-3xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-white/[0.04] sm:p-10">
            <div className="flex items-center gap-3 text-sm font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
              <span className="grid h-10 w-10 place-items-center rounded-xl bg-black text-white">SS</span>
              <span>SoleSpace</span>
            </div>

            {displayState.state === 'unavailable' && (
              <div className="mt-10">
                <h1 className="text-3xl font-bold sm:text-4xl">Maintenance status unavailable</h1>
                <p className="mt-4 text-gray-600 dark:text-gray-300">We could not confirm the current platform status. Please check again.</p>
              </div>
            )}

            {displayState.state === 'operational' && (
              <div className="mt-10" role="status" aria-live="polite" aria-atomic="true">
                <h1 className="text-3xl font-bold sm:text-4xl">Service Restored</h1>
                <p className="mt-4 text-gray-600 dark:text-gray-300">SoleSpace is operational again. Choose Safe Return when you are ready.</p>
                <button type="button" onClick={handleSafeReturn} className="mt-8 rounded-xl bg-black px-5 py-3 text-sm font-semibold text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2">
                  Safe Return
                </button>
              </div>
            )}

            {isWindowState(displayState) && (
              <div className="mt-10">
                <p className="text-sm font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-300">{displayState.state === 'active' ? 'Maintenance Active' : 'Scheduled Maintenance'}</p>
                <h1 className="mt-3 text-3xl font-bold sm:text-4xl">{displayState.title}</h1>
                <p className="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">{displayState.message}</p>

                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                  <div className="rounded-2xl bg-gray-50 p-4 dark:bg-white/[0.05]">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Expected completion</p>
                    <p className="mt-2 font-semibold">{formatDate(displayState.ends_at)}</p>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{formatDuration(remaining)} remaining</p>
                  </div>
                  <div className="rounded-2xl bg-gray-50 p-4 dark:bg-white/[0.05]">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Progress</p>
                    <p className="mt-2 font-semibold">{displayState.progress_stage ?? 'Maintenance in progress'}</p>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">Updates are synchronized from the server.</p>
                  </div>
                </div>

                {displayState.update_message && (
                  <div className="mt-6 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Latest update</p>
                    <p className="mt-2 text-sm leading-6">{displayState.update_message}</p>
                    {displayState.update_message_updated_at && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Updated {formatDate(displayState.update_message_updated_at)}</p>}
                  </div>
                )}
              </div>
            )}

            {refreshError && (
              <div className="mt-8 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100" role="alert">
                We could not refresh the latest maintenance status. The displayed state is still the last known state.
              </div>
            )}

            <button type="button" onClick={() => void refresh()} className="mt-8 rounded-xl border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-900 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2 dark:border-gray-700 dark:text-white dark:hover:bg-white/[0.06]">
              Check Again
            </button>
          </section>
        </div>
      </main>
    </>
  );
}
