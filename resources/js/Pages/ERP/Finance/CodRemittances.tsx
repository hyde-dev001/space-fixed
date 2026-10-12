import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState, type PropsWithChildren } from 'react';
import axios from 'axios';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import type { CodRemittanceView } from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';

const money = (value: string | number | null | undefined) => Number(value ?? 0).toLocaleString('en-PH', {
  style: 'currency',
  currency: 'PHP',
});

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : '—';

const statusClass = (status: string) => {
  if (status === 'settled') return 'bg-emerald-100 text-emerald-800';
  if (status === 'disputed') return 'bg-red-100 text-red-800';
  return 'bg-amber-100 text-amber-800';
};

export default function CodRemittances({ children }: PropsWithChildren) {
  const [remittances, setRemittances] = useState<CodRemittanceView[]>([]);
  const [received, setReceived] = useState<Record<number, string>>({});
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await axios.get<{ data: CodRemittanceView[] }>('/api/finance/cod-remittances');
      const data = response.data.data ?? [];
      setRemittances(data);
      setReceived((current) => Object.fromEntries(data.map((item) => [item.id, current[item.id] ?? item.expected_amount])));
      setError(null);
    } catch {
      setError('COD remittances could not be loaded. Refresh and try again.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const confirm = async (remittance: CodRemittanceView) => {
    const receivedAmount = received[remittance.id] ?? remittance.expected_amount;
    const confirmation = await workflowFeedback.confirm({
      title: 'Confirm physical COD cash?',
      text: `${money(receivedAmount)} was physically received for ${remittance.reference}. An exact match settles the collections and appends the Finance payment entries. A mismatch creates a dispute and no ledger entry.`,
      confirmButtonText: 'Confirm cash',
    });
    if (!confirmation.isConfirmed) return;

    setBusyId(remittance.id);
    try {
      const response = await axios.post(`/api/finance/cod-remittances/${remittance.id}/confirm`, {
        received_amount: receivedAmount,
        dispute_reason: reasons[remittance.id] || undefined,
      });
      await workflowFeedback.success({ title: response.data.remittance?.status === 'disputed' ? 'Remittance disputed' : 'Remittance settled', text: response.data.message });
      await load();
    } catch (requestError) {
      const message = axios.isAxiosError(requestError) ? requestError.response?.data?.message : null;
      await workflowFeedback.error(message || 'The remittance could not be confirmed.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <AppLayoutERP>
      <Head title="COD Remittances" />
      <div className="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
        <header>
          <p className="text-sm font-semibold uppercase tracking-wide text-gray-500">Finance · Cash confirmation</p>
          <h1 className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">COD Remittances</h1>
          <p className="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
            Confirm the physical cash received from the rider. Only an exact match settles the remittance and records the final Finance payment entry; mismatches remain disputed.
          </p>
        </header>

        {error && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{error}</div>}
        {loading && <p className="text-sm text-gray-500">Loading remittances…</p>}
        {!loading && remittances.length === 0 && <div className="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">No COD remittances have been submitted.</div>}

        <div className="space-y-4">
          {remittances.map((remittance) => {
            const isOpen = remittance.status === 'submitted';
            return (
              <article key={remittance.id} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="font-semibold text-gray-900 dark:text-white">{remittance.reference}</h2>
                      <span className={`rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${statusClass(remittance.status)}`}>{remittance.status}</span>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">Rider: {remittance.rider_name ?? `User #${remittance.rider_user_id ?? '—'}`}</p>
                    <p className="text-sm text-gray-500">Submitted {dateTime(remittance.submitted_at)}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-sm text-gray-500">Expected</p>
                    <p className="text-xl font-bold text-gray-900 dark:text-white">{money(remittance.expected_amount)}</p>
                  </div>
                </div>

                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                  <div className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><p className="text-xs uppercase text-gray-500">Submitted</p><p className="mt-1 font-semibold">{money(remittance.submitted_amount)}</p></div>
                  <div className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><p className="text-xs uppercase text-gray-500">Received</p><p className="mt-1 font-semibold">{money(remittance.received_amount)}</p></div>
                  <div className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><p className="text-xs uppercase text-gray-500">Variance</p><p className="mt-1 font-semibold">{money(remittance.variance_amount)}</p></div>
                </div>

                {remittance.items && remittance.items.length > 0 && (
                  <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <p className="text-sm font-semibold text-gray-900 dark:text-white">Collections in this remittance</p>
                    <div className="mt-2 divide-y divide-gray-200 dark:divide-gray-700">
                      {remittance.items.map((item) => (
                        <div key={item.id} className="flex flex-wrap justify-between gap-2 py-2 text-sm">
                          <span className="text-gray-600 dark:text-gray-300">{item.order_number ?? `Order #${item.order_id ?? '—'}`}</span>
                          <span className="font-medium">{money(item.expected_amount)}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {remittance.status === 'disputed' && remittance.dispute_reason && (
                  <p className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800">{remittance.dispute_reason}</p>
                )}

                {isOpen && (
                  <div className="mt-5 grid gap-3 border-t border-gray-200 pt-4 dark:border-gray-700 sm:grid-cols-[minmax(0,220px)_minmax(0,1fr)_auto] sm:items-end">
                    <label className="text-sm font-medium text-gray-700 dark:text-gray-200">
                      Physical cash received
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={received[remittance.id] ?? remittance.expected_amount}
                        onChange={(event) => setReceived((current) => ({ ...current, [remittance.id]: event.target.value }))}
                        className="mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-gray-900 focus:ring-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </label>
                    <label className="text-sm font-medium text-gray-700 dark:text-gray-200">
                      Dispute note (required for a mismatch)
                      <input
                        type="text"
                        value={reasons[remittance.id] ?? ''}
                        onChange={(event) => setReasons((current) => ({ ...current, [remittance.id]: event.target.value }))}
                        placeholder="Optional when the amount matches"
                        className="mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-gray-900 focus:ring-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      />
                    </label>
                    <button
                      type="button"
                      onClick={() => void confirm(remittance)}
                      disabled={busyId === remittance.id}
                      className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {busyId === remittance.id ? 'Confirming…' : 'Confirm physical cash'}
                    </button>
                  </div>
                )}
              </article>
            );
          })}
        </div>
      </div>
      {children}
    </AppLayoutERP>
  );
}
