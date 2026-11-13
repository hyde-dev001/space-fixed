import { useMaintenance } from '../../providers/MaintenanceProvider';

export function MaintenanceWarningModal(): React.JSX.Element | null {
  const { state, warningVisible, dismissWarning } = useMaintenance();

  if (!warningVisible || state.state !== 'scheduled') {
    return null;
  }

  return (
    <div className="fixed inset-0 z-[70] grid place-items-center bg-black/40 p-4 erp-modal-backdrop" role="presentation">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900" role="dialog" aria-modal="true" aria-labelledby="maintenance-warning-title" aria-describedby="maintenance-warning-message">
        <h2 id="maintenance-warning-title" className="text-xl font-bold text-gray-900 dark:text-white">Maintenance warning</h2>
        <p id="maintenance-warning-message" className="mt-3 text-sm text-gray-600 dark:text-gray-300">{state.message}</p>
        <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">
          Maintenance is scheduled to begin at {new Date(state.starts_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}.
        </p>
        <button type="button" onClick={dismissWarning} className="mt-6 w-full rounded-lg bg-black px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2">
          I understand
        </button>
      </div>
    </div>
  );
}
