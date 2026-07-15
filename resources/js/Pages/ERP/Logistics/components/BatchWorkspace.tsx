import React from 'react';
import { AlertTriangle, PackageCheck } from 'lucide-react';
import { DndProvider } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import type { DeliveryBatch, TrackingShipmentLeg } from '@/types/logistics';
import BatchStopRow from './BatchStopRow';

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
  onToggleUrgent?: (leg: TrackingShipmentLeg) => void;
  onSave: () => void;
  onReview: () => void;
};

export default function BatchWorkspace({
  batch, selectedLegs, date, window, dailyRiderCapacity, overrideReason, submitting, busyLegId,
  onOverrideReasonChange, onMove, onRemove, onToggleUrgent, onSave, onReview,
}: Props) {
  const legs = batch?.legs ?? selectedLegs;
  const rejectedAt = formatRejectionTime(batch?.rejected_at);
  const overCapacity = legs.length > dailyRiderCapacity;
  const canSave = !batch && Boolean(date) && legs.length > 0 && !submitting && (!overCapacity || Boolean(overrideReason.trim()));

  return <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div className="border-b border-gray-100 p-4 dark:border-gray-700">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-bold text-gray-950 dark:text-white">{batch ? `Batch #${batch.id}` : 'New batch'}</h2>
          <p className="mt-1 text-sm text-gray-500">{date || batch?.delivery_date || 'Choose a date'} · {(batch?.delivery_window || window) === 'morning' ? 'Morning' : 'Afternoon'}</p>
        </div>
        <span className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">{legs.length}/{dailyRiderCapacity} stops</span>
      </div>
      <div className="mt-3 flex items-center gap-2 rounded-xl bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
        <PackageCheck size={18} />Rider will be selected during Review &amp; Offer.
      </div>
      {batch?.status === 'draft' && batch.rejection_reason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Rejected by rider</p>
        <p className="mt-1">{batch.rejection_reason}</p>
        {rejectedAt && <time className="mt-1 block text-xs" dateTime={batch.rejected_at!}>{rejectedAt}</time>}
      </div>}
      {overCapacity && <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">
        <p className="flex items-center gap-2 text-sm font-semibold"><AlertTriangle size={17} />This batch exceeds the daily rider capacity of {dailyRiderCapacity} {dailyRiderCapacity === 1 ? 'stop' : 'stops'}.</p>
        {!batch && <label className="mt-3 block text-sm font-medium">Capacity override reason
          <textarea aria-label="Capacity override reason" value={overrideReason} onChange={(event) => onOverrideReasonChange(event.target.value)} rows={3} className="mt-1 w-full rounded-xl border border-amber-300 bg-white p-3" placeholder="Explain why this batch can exceed capacity" />
        </label>}
      </div>}
    </div>
    <DndProvider backend={HTML5Backend}>
      <div className="min-h-48 space-y-3 bg-gray-50 p-4 dark:bg-gray-900/40">
        {legs.map((leg, index) => <BatchStopRow key={leg.id} leg={leg} index={index} total={legs.length} editable={!batch || batch.status === 'draft'} busy={submitting || busyLegId === leg.id} onMove={onMove} onRemove={onRemove} onToggleUrgent={onToggleUrgent} />)}
        {!legs.length && <p className="grid min-h-40 place-items-center text-center text-sm text-gray-500">Select deliveries from the left to build the route.</p>}
      </div>
    </DndProvider>
    <div className="sticky bottom-0 flex min-h-16 flex-wrap items-center justify-end gap-2 border-t bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
      {batch?.status === 'draft' && <button type="button" onClick={onReview} className="min-h-11 rounded-xl border border-blue-600 px-4 text-sm font-semibold text-blue-700">Review &amp; Offer</button>}
      {batch && batch.status !== 'draft' && <span className="mr-auto text-sm font-medium text-gray-500">This route is read-only at the {batch.status.replaceAll('_', ' ')} stage.</span>}
      {!batch && <button type="button" disabled={!canSave} onClick={onSave} className="min-h-11 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-40">{submitting ? 'Saving Draft...' : 'Save Draft'}</button>}
    </div>
  </section>;
}
