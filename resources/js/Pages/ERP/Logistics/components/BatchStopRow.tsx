import React, { useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Flame, GripVertical, MapPin, Phone, Trash2 } from 'lucide-react';
import { useDrag, useDrop } from 'react-dnd';
import { logisticsModuleForSourceType, logisticsModuleLabel, logisticsSourceLabel, type TrackingShipmentLeg } from '@/types/logistics';
import ArrivalSummary from './ArrivalSummary';
import RetailOrderSummary from './RetailOrderSummary';

const text = (value?: string | null) => value || 'Not provided';
const label = (value?: string | null) => value ? value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not scheduled';
const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));

type Props = {
  leg: TrackingShipmentLeg;
  index: number;
  total: number;
  editable?: boolean;
  busy?: boolean;
  onMove?: (from: number, to: number) => void;
  onRemove?: (leg: TrackingShipmentLeg, index: number) => void;
  onToggleUrgent?: (leg: TrackingShipmentLeg) => void;
};

export default function BatchStopRow({ leg, index, total, editable = false, busy = false, onMove, onRemove, onToggleUrgent }: Props) {
  const { maxDeliveryAttempts = 2 } = usePage<{ maxDeliveryAttempts?: number }>().props;
  const ref = useRef<HTMLDivElement>(null);
  const terminal = ['delivered', 'cancelled'].includes(leg.status);
  const source = logisticsSourceLabel(leg.shipment);
  const destination = leg.destination_snapshot;
  const failedAttempt = leg.attempts?.[0];
  const failedAttemptCount = leg.failed_attempt_count ?? failedAttempt?.attempt_number ?? 0;
  const [{ isDragging }, drag] = useDrag(() => ({
    type: 'batch-stop',
    item: { index },
    canDrag: editable && !busy,
    collect: (monitor) => ({ isDragging: monitor.isDragging() }),
  }), [editable, busy, index]);
  const [, drop] = useDrop(() => ({
    accept: 'batch-stop',
    drop: (item: { index: number }) => item.index !== index && onMove?.(item.index, index),
  }), [index, onMove]);
  drag(drop(ref));

  return <article ref={ref} aria-label={`Stop ${index + 1}: ${destination?.name || source}`} className={`rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition dark:border-gray-700 dark:bg-gray-800 ${isDragging ? 'opacity-50' : ''}`}>
    <div className="flex items-start gap-3">
      {editable && <button type="button" aria-label={`Drag stop ${index + 1}`} title="Drag to reorder" className="mt-1 cursor-grab text-gray-400"><GripVertical size={18} /></button>}
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">{index + 1}</span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="font-semibold text-gray-950 dark:text-white">{destination?.name || source}</p>
          <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-200">{source}</span>
          <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{logisticsModuleLabel(logisticsModuleForSourceType(leg.shipment?.source_type))}</span>
          {leg.urgent_at && <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-1 text-xs font-bold text-red-700"><Flame size={12} />Urgent</span>}
          {failedAttempt?.status === 'failed' && <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800">Failed attempt - {failedAttemptCount}/{maxDeliveryAttempts}</span>}
        </div>
        <div className="mt-2 grid gap-1 text-sm text-gray-600 dark:text-gray-300">
          <span className="inline-flex items-center gap-2"><Phone size={14} />{text(destination?.phone)}</span>
          <span className="inline-flex items-start gap-2"><MapPin className="mt-0.5 shrink-0" size={14} />{text(destination?.address)}</span>
          {leg.shipment?.source_summary?.shoe_summary && <span>{leg.shipment.source_summary.shoe_summary}</span>}
          {failedAttempt?.reason_code && <span>{label(failedAttempt.reason_code)}</span>}
          <span>{leg.scheduled_delivery_date ? formatDate(leg.scheduled_delivery_date) : 'Not scheduled'}{leg.delivery_window ? ` · ${label(leg.delivery_window)}` : ''} · {label(leg.status)}</span>
        </div>
        <ArrivalSummary arrivals={leg.arrivals} />
        <div className="mt-3">
          <RetailOrderSummary summary={leg.shipment?.order_summary} instructions={destination?.delivery_instructions} />
        </div>
      </div>
      <div className="flex flex-wrap justify-end gap-1">
        {editable && <>
          <button type="button" aria-label={`Move stop ${index + 1} up`} title="Move up" disabled={busy || index === 0} onClick={() => onMove?.(index, index - 1)} className="rounded-lg border p-2 text-gray-600 hover:bg-gray-50 disabled:opacity-30"><ArrowUp size={16} /></button>
          <button type="button" aria-label={`Move stop ${index + 1} down`} title="Move down" disabled={busy || index === total - 1} onClick={() => onMove?.(index, index + 1)} className="rounded-lg border p-2 text-gray-600 hover:bg-gray-50 disabled:opacity-30"><ArrowDown size={16} /></button>
          <button type="button" aria-label={`Remove stop ${index + 1}`} title="Remove stop" disabled={busy} onClick={() => onRemove?.(leg, index)} className="rounded-lg border border-red-200 p-2 text-red-600 hover:bg-red-50 disabled:opacity-30"><Trash2 size={16} /></button>
        </>}
        {!terminal && onToggleUrgent && <button type="button" aria-label={`${leg.urgent_at ? 'Clear urgent' : 'Mark urgent'} stop ${index + 1}`} title={leg.urgent_at ? 'Clear urgent' : 'Mark urgent'} disabled={busy} onClick={() => onToggleUrgent(leg)} className={`rounded-lg border p-2 disabled:opacity-30 ${leg.urgent_at ? 'border-red-300 bg-red-50 text-red-700' : 'text-gray-600'}`}><Flame size={16} /></button>}
      </div>
    </div>
  </article>;
}
