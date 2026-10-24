import { useEffect, useState } from 'react';
import { useMaintenance } from '../../providers/MaintenanceProvider';

function formatCountdown(milliseconds: number): string {
  const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  return hours > 0
    ? `${hours}h ${String(minutes).padStart(2, '0')}m ${String(seconds).padStart(2, '0')}s`
    : `${minutes}m ${String(seconds).padStart(2, '0')}s`;
}

export function MaintenanceBanner(): React.JSX.Element | null {
  const { state, isFreezeActive, hasDirtySources, serverNow } = useMaintenance();
  const [, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (state.state !== 'scheduled') {
      return;
    }

    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [state.state]);

  if (state.state !== 'scheduled') {
    return null;
  }

  const startsAt = new Date(state.starts_at).getTime();
  const remaining = Math.max(0, startsAt - serverNow());

  return (
    <div className="fixed inset-x-0 top-0 z-[60] border-b border-amber-200 bg-amber-50 px-4 py-3 text-amber-950 shadow-sm dark:border-amber-900/60 dark:bg-amber-950/90 dark:text-amber-50" role="status" aria-live="polite">
      <div className="mx-auto flex max-w-7xl flex-col gap-1 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div>
          <p className="font-semibold">{state.title}</p>
          <p>{state.message}</p>
          {hasDirtySources && <p className="font-medium">Save your changes before maintenance begins.</p>}
        </div>
        <div className="shrink-0 font-medium">
          <span className="sr-only">Maintenance begins in </span>
          <span aria-hidden="true">{isFreezeActive ? 'Critical actions are frozen · ' : ''}{formatCountdown(remaining)}</span>
        </div>
      </div>
    </div>
  );
}
