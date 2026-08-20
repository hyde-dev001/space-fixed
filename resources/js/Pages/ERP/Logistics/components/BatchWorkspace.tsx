import React from 'react';
import { AlertTriangle, PackageCheck } from 'lucide-react';
import { DndProvider } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import type { DeliveryBatch, TrackingShipmentLeg } from '@/types/logistics';
import BatchStopRow from './BatchStopRow';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const formatRejectionTime = (value?: string | null) => {
  const date = value && new Date(value);
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : null;
};

type Props = {
  batch?: DeliveryBatch;
  selectedLegs: TrackingShipmentLeg[];
  date: string;
  window: string;
  dailyRiderCapacity: number;
  overrideReason: string;
  submitting: boolean;
  busyLegId?: number;
  onOverrideReasonChange: (value: string) => void;
  onMove: (from: number, to: number) => void;
  onRemove: (leg: TrackingShipmentLeg, index: number) => void;
  onSave: () => void;
  onReview: () => void;
};

export default function BatchWorkspace({
  batch, selectedLegs, date, window, dailyRiderCapacity, overrideReason, submitting, busyLegId,
  onOverrideReasonChange, onMove, onRemove, onSave, onReview,
}: Props) {
  const history = batch && ['completed', 'cancelled'].includes(batch.status);
  const legs = !batch ? selectedLegs : history
    ? batch.stop_snapshot?.length ? batch.stop_snapshot
      : batch.cancelled_stops?.length ? batch.cancelled_stops : batch.legs
    : batch.legs;
  const capacity = batch?.capacity ?? dailyRiderCapacity;
  const deliveryDate = date || batch?.delivery_date;
  const rejectedAt = formatRejectionTime(batch?.rejected_at);
  const cancellationReason = batch?.cancellation_reason || batch?.dispatcher_override_reason;
  const overCapacity = legs.length > capacity;
  const canSave = !batch && Boolean(date) && legs.length >= 2 && !submitting && (!overCapacity || Boolean(overrideReason.trim()));

  return <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div className="border-b border-gray-100 p-4 dark:border-gray-700 sm:p-5 xl:p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-bold text-gray-950 dark:text-white">{batch ? `Batch #${batch.id}` : 'New batch'}</h2>
          <p className="mt-1 text-sm text-gray-500">{deliveryDate ? formatDate(deliveryDate) : 'Choose a date'} · {(batch?.delivery_window || window) === 'morning' ? 'Morning' : 'Afternoon'}</p>
        </div>
        <span className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">{legs.length}/{capacity} stops</span>
      </div>
      <div className="mt-3 flex items-center gap-2 rounded-xl bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
        <PackageCheck size={18} />{batch?.status === 'cancelled' ? 'Cancelled batch summary.' : 'Rider will be selected during Review & Offer.'}
      </div>
      {batch?.status === 'draft' && batch.rejection_reason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Rejected by rider</p>
        <p className="mt-1">{batch.rejection_reason}</p>
        {rejectedAt && <time className="mt-1 block text-xs" dateTime={batch.rejected_at!}>{rejectedAt}</time>}
      </div>}
      {batch?.status === 'cancelled' && cancellationReason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Cancellation reason</p>
        <p className="mt-1">{cancellationReason}</p>
      </div>}
      {overCapacity && <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">
        <p className="flex items-center gap-2 text-sm font-semibold"><AlertTriangle size={17} />This batch exceeds the daily rider capacity of {capacity} {capacity === 1 ? 'stop' : 'stops'}.</p>
        {!batch && <label className="mt-3 block text-sm font-medium">Capacity override reason
          <textarea aria-label="Capacity override reason" value={overrideReason} onChange={(event) => onOverrideReasonChange(event.target.value)} rows={3} className="mt-1 w-full rounded-xl border border-amber-300 bg-white p-3" placeholder="Explain why this batch can exceed capacity" />
        </label>}
      </div>}
    </div>
    <DndProvider backend={HTML5Backend}>
      <div className="min-h-48 space-y-3 bg-gray-50 p-4 dark:bg-gray-900/40 sm:p-5 xl:p-4">
        {legs.map((leg, index) => <BatchStopRow key={leg.id} leg={leg} index={index} total={legs.length} editable={!batch || batch.status === 'draft'} busy={submitting || busyLegId === leg.id} onMove={onMove} onRemove={onRemove} />)}
        {!legs.length && <p className="grid min-h-40 place-items-center text-center text-sm text-gray-500">{history ? 'Historical stop details unavailable' : 'Select deliveries from the left to build the route.'}</p>}
      </div>
    </DndProvider>
    <div className="sticky bottom-0 flex min-h-16 flex-col items-stretch justify-end gap-3 border-t bg-white p-4 dark:border-gray-700 dark:bg-gray-800 sm:flex-row sm:items-center sm:gap-2 xl:flex-wrap">
      {batch?.status === 'draft' && <button type="button" onClick={onReview} className="min-h-11 w-full rounded-xl border border-blue-600 px-4 text-sm font-semibold text-blue-700 sm:w-auto">Review &amp; Offer</button>}
      {batch && batch.status !== 'draft' && <span className="mr-auto text-sm font-medium text-gray-500">This route is read-only at the {batch.status.replaceAll('_', ' ')} stage.</span>}
      {!batch && legs.length < 2 && <span className="mr-auto text-sm font-semibold text-amber-700">Select at least 2 deliveries</span>}
      {!batch && <button type="button" disabled={!canSave} onClick={onSave} className="min-h-11 w-full rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-40 sm:w-auto">{submitting ? 'Saving Draft...' : 'Save Draft'}</button>}
    </div>
  </section>;
}
