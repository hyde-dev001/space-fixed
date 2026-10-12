import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type { CodCollectionPageData, CodCollectionView } from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';

const emptyPage: CodCollectionPageData = {
  summary: { cash_currently_held: '0.00', pending_remittance_count: 0 },
  pending_remittance: [],
  submitted_remittances: [],
  settled_history: [],
};

const money = (value: string | number) => Number(value).toLocaleString('en-PH', {
  style: 'currency',
  currency: 'PHP',
});

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : '—';

function CollectionRow({
  collection,
  selected,
  onToggle,
}: {
  collection: CodCollectionView;
  selected: boolean;
  onToggle: () => void;
}) {
  return (
    <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:border-gray-400 dark:border-gray-700">
      <input
        type="checkbox"
        className="mt-1 h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
        checked={selected}
        onChange={onToggle}
        aria-label={`Select COD collection for ${collection.order_number ?? `order ${collection.order_id}`}`}
      />
      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center justify-between gap-2">
          <span className="font-semibold text-gray-900 dark:text-white">
            {collection.order_number ?? `Order #${collection.order_id}`}
          </span>
          <span className="font-semibold text-gray-900 dark:text-white">{money(collection.expected_amount)}</span>
        </span>
        <span className="mt-1 block text-sm text-gray-500 dark:text-gray-400">
          Cash collected {dateTime(collection.collected_at)}
        </span>
      </span>
    </label>
  );
}

export default function CodCollections({ children }: React.PropsWithChildren) {
  const [page, setPage] = useState<CodCollectionPageData>(emptyPage);
  const [selected, setSelected] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await logisticsApi.codCollections();
      setPage(response.data);
      setSelected((current) => current.filter((id) => response.data.pending_remittance.some((item) => item.id === id)));
      setError(null);
    } catch {
      setError('COD collections could not be loaded. Refresh and try again.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const selectedTotal = useMemo(() => page.pending_remittance
    .filter((collection) => selected.includes(collection.id))
    .reduce((total, collection) => total + Number(collection.expected_amount), 0), [page.pending_remittance, selected]);

  const submit = async () => {
    if (selected.length === 0) return;
    const confirmation = await workflowFeedback.confirm({
      title: 'Submit COD remittance?',
      text: `${money(selectedTotal)} will be submitted to Finance for physical cash confirmation.`,
      confirmButtonText: 'Submit remittance',
    });
    if (!confirmation.isConfirmed) return;

    setSubmitting(true);
    try {
      await logisticsApi.submitCodRemittance(selected, `cod-remittance-submit:${crypto.randomUUID()}`);
      setSelected([]);
      await workflowFeedback.success({ title: 'Remittance submitted', text: 'Finance can now confirm the physical cash.' });
      await load();
    } catch {
      await workflowFeedback.error('The COD remittance was not submitted. Refresh and try again.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AppLayoutERP>
      <Head title="My COD Collections" />
      <div className="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
        <header>
          <p className="text-sm font-semibold uppercase tracking-wide text-gray-500">Logistics · Cash custody</p>
          <h1 className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">My COD Collections</h1>
          <p className="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
            Record cash collected on deliveries, then submit the held collections together for Finance confirmation.
          </p>
        </header>

        {error && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{error}</div>}

        <section className="grid gap-4 sm:grid-cols-2" aria-label="COD collection summary">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p className="text-sm text-gray-500">Cash currently held</p>
            <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{money(page.summary.cash_currently_held)}</p>
          </div>
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p className="text-sm text-gray-500">Pending remittance</p>
            <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{page.summary.pending_remittance_count}</p>
          </div>
        </section>

        <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Ready to submit</h2>
              <p className="mt-1 text-sm text-gray-500">Select only the cash you are handing to Finance now.</p>
            </div>
            <button
              type="button"
              onClick={() => void submit()}
              disabled={submitting || selected.length === 0}
              className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
            >
              {submitting ? 'Submitting…' : `Submit ${selected.length ? money(selectedTotal) : 'selected cash'}`}
            </button>
          </div>
          <div className="mt-4 space-y-3">
            {loading && <p className="text-sm text-gray-500">Loading collections…</p>}
            {!loading && page.pending_remittance.length === 0 && <p className="rounded-lg bg-gray-50 p-4 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">No held COD collections are waiting for remittance.</p>}
            {page.pending_remittance.map((collection) => (
              <CollectionRow
                key={collection.id}
                collection={collection}
                selected={selected.includes(collection.id)}
                onToggle={() => setSelected((current) => current.includes(collection.id) ? current.filter((id) => id !== collection.id) : [...current, collection.id])}
              />
            ))}
          </div>
        </section>

        <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Submitted remittances</h2>
          <div className="mt-4 space-y-3">
            {page.submitted_remittances.length === 0 && <p className="text-sm text-gray-500">No submitted remittances yet.</p>}
            {page.submitted_remittances.map((remittance) => (
              <div key={remittance.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                <div>
                  <p className="font-semibold text-gray-900 dark:text-white">{remittance.reference}</p>
                  <p className="text-sm text-gray-500">Submitted {dateTime(remittance.submitted_at)}</p>
                </div>
                <div className="text-right">
                  <p className="font-semibold text-gray-900 dark:text-white">{money(remittance.submitted_amount)}</p>
                  <p className="text-sm capitalize text-gray-500">{remittance.status.replace('_', ' ')}</p>
                  {remittance.variance_amount && <p className="text-xs text-red-700">Variance {money(remittance.variance_amount)}</p>}
                </div>
                {remittance.dispute_reason && <p className="basis-full text-sm text-red-700">{remittance.dispute_reason}</p>}
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Settled history</h2>
          <div className="mt-4 divide-y divide-gray-200 dark:divide-gray-700">
            {page.settled_history.length === 0 && <p className="text-sm text-gray-500">No settled COD collections yet.</p>}
            {page.settled_history.map((collection) => (
              <div key={collection.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <span className="text-sm text-gray-700 dark:text-gray-300">{collection.order_number ?? `Order #${collection.order_id}`}</span>
                <span className="font-semibold text-gray-900 dark:text-white">{money(collection.collected_amount)}</span>
              </div>
            ))}
          </div>
        </section>
      </div>
      {children}
    </AppLayoutERP>
  );
}
