import React from 'react';
import { AlertTriangle, Eye, MoreHorizontal, Pencil } from 'lucide-react';
import { logisticsModuleLabel, type DeliveryBatch, type DeliveryBatchStatus } from '@/types/logistics';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' })
  .format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const formatRejectionTime = (value?: string | null) => {
  const date = value && new Date(value);
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : null;
};
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const primaryLabel = (status: DeliveryBatchStatus) => ({
  draft: 'Edit batch', offered: 'View offer', accepted: 'View route', in_progress: 'View progress', completed: 'View summary', cancelled: 'View summary',
}[status]);

type Props = {
  batches: DeliveryBatch[];
  variant?: 'active' | 'history';
  onOpen: (batchId: number) => void;
  onDetails: (batchId: number, trigger: HTMLButtonElement) => void;
  onReview?: (batchId: number) => void;
  onCancel?: (batchId: number) => void;
  onRestore?: (batchId: number) => void;
};

const BatchStatus = ({ status }: { status: DeliveryBatchStatus }) => <span className="rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{label(status)}</span>;

function SecondaryActions({ batch, onReview, onCancel, onRestore }: Pick<Props, 'onReview' | 'onCancel' | 'onRestore'> & { batch: DeliveryBatch }) {
  const active = !['completed', 'cancelled'].includes(batch.status);
  const hasActions = (batch.status === 'draft' && Boolean(onReview))
    || (['draft', 'offered', 'accepted'].includes(batch.status) && Boolean(onCancel));

  if (batch.status === 'cancelled' && onRestore) {
    return <button type="button" aria-label={`Restore batch ${batch.id}`} onClick={() => onRestore(batch.id)} className="rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">Restore to draft</button>;
  }
  if (!active || !hasActions) return null;

  return <details className="relative">
    <summary aria-label={`More actions for batch ${batch.id}`} className="flex min-h-10 cursor-pointer list-none items-center rounded-lg border p-2 text-gray-600 hover:bg-gray-50"><MoreHorizontal size={18} /></summary>
    <div className="absolute right-0 z-10 mt-1 w-44 rounded-lg border bg-white p-1 shadow-lg">
      {batch.status === 'draft' && onReview && <button type="button" onClick={() => onReview(batch.id)} className="block w-full rounded px-3 py-2 text-left text-sm hover:bg-gray-50">Review &amp; Offer</button>}
      {['draft', 'offered', 'accepted'].includes(batch.status) && onCancel && <button type="button" onClick={() => onCancel(batch.id)} className="block w-full rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">Cancel batch</button>}
    </div>
  </details>;
}

function BatchSummary({ batch }: { batch: DeliveryBatch }) {
  const rejectedAt = formatRejectionTime(batch.rejected_at);

  return <>
    <div className="flex flex-wrap items-center gap-2">
      <span className="font-bold text-gray-950 dark:text-white">Batch #{batch.id}</span>
      <BatchStatus status={batch.status} />
      <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{logisticsModuleLabel(batch.module)}</span>
    </div>
    {batch.status === 'draft' && batch.rejection_reason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
      <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Rejected by rider</p>
      <p className="mt-1">{batch.rejection_reason}</p>
      {rejectedAt && <time className="mt-1 block text-xs" dateTime={batch.rejected_at!}>{rejectedAt}</time>}
    </div>}
  </>;
}

export default function BatchTable({ batches, variant = 'active', onOpen, onDetails, onReview, onCancel, onRestore }: Props) {
  const history = variant === 'history';
  const legsFor = (batch: DeliveryBatch) => ['completed', 'cancelled'].includes(batch.status)
    ? (batch.stop_snapshot?.length ? batch.stop_snapshot : batch.cancelled_stops?.length ? batch.cancelled_stops : batch.legs)
    : batch.legs;

  return <div>
    <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
      <table aria-label={history ? 'Batch history' : 'Active batches'} className="w-full min-w-[760px] text-left text-sm">
        <caption className="sr-only">{history ? 'Completed and cancelled delivery batches' : 'Current delivery batches'}</caption>
        <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40">
          <tr><th scope="col" className="px-4 py-3">Batch</th><th scope="col" className="px-4 py-3">Schedule</th><th scope="col" className="px-4 py-3">Rider</th><th scope="col" className="px-4 py-3">Stops</th><th scope="col" className="px-4 py-3 text-right">Actions</th></tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
          {batches.map((batch) => <tr key={batch.id}>
            <td className="px-4 py-4 align-top"><BatchSummary batch={batch} /></td>
            <td className="px-4 py-4 align-top text-gray-600 dark:text-gray-300"><span>{formatDate(batch.delivery_date)} · {label(batch.delivery_window)}</span></td>
            <td className="px-4 py-4 align-top text-gray-700 dark:text-gray-200">{batch.rider_profile?.name || 'Not assigned'}</td>
            <td className="px-4 py-4 align-top text-gray-600 dark:text-gray-300">{['completed', 'cancelled'].includes(batch.status) ? legsFor(batch).length : batch.assigned_stop_count}/{batch.capacity}<span className="block text-xs text-gray-500">{legsFor(batch).filter((leg) => leg.urgent_at).length} urgent</span></td>
            <td className="px-4 py-4 align-top"><div className="flex flex-wrap justify-end gap-2">
              <button type="button" aria-label={`${primaryLabel(batch.status)} ${batch.id}`} title={history ? 'View summary' : primaryLabel(batch.status)} onClick={() => onOpen(batch.id)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                {batch.status === 'draft' ? <Pencil aria-hidden="true" size={18} /> : <Eye aria-hidden="true" size={18} />}
              </button>
              <button type="button" aria-label={`View details for batch ${batch.id}`} title="View details" onClick={(event) => onDetails(batch.id, event.currentTarget)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-blue-200 text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500"> <Eye aria-hidden="true" size={18} /></button>
              <SecondaryActions batch={batch} onReview={onReview} onCancel={onCancel} onRestore={onRestore} />
            </div></td>
          </tr>)}
        </tbody>
      </table>
    </div>
  </div>;
}
