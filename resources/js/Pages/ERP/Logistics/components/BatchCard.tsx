import React, { useState } from 'react';
import { AlertTriangle, ChevronDown, ChevronUp, MoreHorizontal } from 'lucide-react';
import type { DeliveryBatch, TrackingShipmentLeg } from '@/types/logistics';
import BatchStopRow from './BatchStopRow';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const formatRejectionTime = (value?: string | null) => {
  const date = value && new Date(value);
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : null;
};
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const primaryLabel = (status: DeliveryBatch['status']) => ({
  draft: 'Edit batch', offered: 'View offer', accepted: 'View route', in_progress: 'View progress', completed: 'View summary', cancelled: 'View summary',
}[status]);

type Props = {
  batch: DeliveryBatch;
  onOpen: (batch: DeliveryBatch) => void;
  onReview?: (batch: DeliveryBatch) => void;
  onCancel?: (batch: DeliveryBatch) => void;
  onRestore?: (batch: DeliveryBatch) => void;
  onToggleUrgent?: (leg: TrackingShipmentLeg) => void;
};

export default function BatchCard({ batch, onOpen, onReview, onCancel, onRestore, onToggleUrgent }: Props) {
  const [expanded, setExpanded] = useState(false);
  const rejectedAt = formatRejectionTime(batch.rejected_at);
  const active = !['completed', 'cancelled'].includes(batch.status);
  const legs = active ? batch.legs
    : batch.stop_snapshot?.length ? batch.stop_snapshot
      : batch.cancelled_stops?.length ? batch.cancelled_stops : batch.legs;
  const urgentCount = legs.filter((leg) => leg.urgent_at).length;
  const hasSecondaryActions = (batch.status === 'draft' && Boolean(onReview))
    || (['draft', 'offered', 'accepted'].includes(batch.status) && Boolean(onCancel));

  return <article className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-bold text-gray-950 dark:text-white">Batch #{batch.id}</h3>
            <span className="rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{label(batch.status)}</span>
          </div>
          <p className="mt-1 text-sm text-gray-500">{formatDate(batch.delivery_date)} · {label(batch.delivery_window)}</p>
          <p className="mt-1 text-sm text-gray-700 dark:text-gray-200">{batch.rider_profile?.name || 'Not assigned'}</p>
        </div>
        <div className="text-right text-sm text-gray-600 dark:text-gray-300">
          <p>{active ? batch.assigned_stop_count : legs.length}/{batch.capacity} stops</p>
          <p>{urgentCount} urgent</p>
        </div>
      </div>
      {batch.status === 'draft' && batch.rejection_reason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Rejected by rider</p>
        <p className="mt-1">{batch.rejection_reason}</p>
        {rejectedAt && <time className="mt-1 block text-xs" dateTime={batch.rejected_at!}>{rejectedAt}</time>}
      </div>}
      <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
        <button type="button" aria-label={`${primaryLabel(batch.status)} ${batch.id}`} onClick={() => onOpen(batch)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">{primaryLabel(batch.status)}</button>
        <div className="flex items-center gap-1">
          {batch.status === 'cancelled' && onRestore && <button type="button" aria-label={`Restore batch ${batch.id}`} onClick={() => onRestore(batch)} className="rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">Restore to draft</button>}
          {active && hasSecondaryActions && <details className="relative">
            <summary aria-label={`More actions for batch ${batch.id}`} className="flex cursor-pointer list-none rounded-lg border p-2 text-gray-600"><MoreHorizontal size={18} /></summary>
            <div className="absolute right-0 z-10 mt-1 w-44 rounded-lg border bg-white p-1 shadow-lg">
              {batch.status === 'draft' && onReview && <button type="button" onClick={() => onReview(batch)} className="block w-full rounded px-3 py-2 text-left text-sm hover:bg-gray-50">Review & Offer</button>}
              {['draft', 'offered', 'accepted'].includes(batch.status) && onCancel && <button type="button" onClick={() => onCancel(batch)} className="block w-full rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">Cancel batch</button>}
            </div>
          </details>}
          <button type="button" aria-label={`${expanded ? 'Collapse' : 'Expand'} batch ${batch.id}`} onClick={() => setExpanded(!expanded)} className="rounded-lg border p-2 text-gray-600">{expanded ? <ChevronUp size={18} /> : <ChevronDown size={18} />}</button>
        </div>
      </div>
    </div>
    {expanded && <div className="space-y-3 border-t bg-gray-50 p-4 dark:bg-gray-900/40">
      {legs.map((leg, index) => <BatchStopRow key={leg.id} leg={leg} index={index} total={legs.length} onToggleUrgent={onToggleUrgent} />)}
      {!active && !legs.length && <p className="text-center text-sm text-gray-500">Historical stop details unavailable</p>}
    </div>}
  </article>;
}
