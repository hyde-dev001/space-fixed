import React, { useEffect, useRef } from 'react';
import { DndProvider } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import { CalendarDays, MapPin, Route, UserRound } from 'lucide-react';
import { Modal } from '@/components/ui/modal';
import type { DeliveryBatch } from '@/types/logistics';
import BatchStopRow from './BatchStopRow';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' })
  .format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

type Props = {
  batch?: DeliveryBatch;
  isOpen: boolean;
  onClose: () => void;
};

export default function BatchDetailsModal({ batch, isOpen, onClose }: Props) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const legs = batch?.stop_snapshot?.length ? batch.stop_snapshot : batch?.cancelled_stops?.length ? batch.cancelled_stops : batch?.legs ?? [];

  useEffect(() => {
    if (!isOpen) return;
    setTimeout(() => closeRef.current?.focus(), 0);
  }, [isOpen]);

  const handleKeys = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.stopPropagation();
      onClose();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = dialogRef.current?.querySelectorAll<HTMLElement>('button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
    if (!focusable?.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  };

  if (!batch) return null;

  return <Modal isOpen={isOpen} onClose={onClose} size="5xl" showCloseButton={false}>
    <div ref={dialogRef} role="dialog" aria-modal="true" aria-label={`Batch ${batch.id} details`} onKeyDown={handleKeys} className="max-h-[90vh] overflow-y-auto p-5 sm:p-7">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-700">Batch #{batch.id}</p>
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <h2 id={`batch-details-${batch.id}-title`} className="text-xl font-bold text-gray-950 dark:text-white">Batch details</h2>
            <span className="rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{label(batch.status)}</span>
          </div>
          <p className="mt-1 text-sm text-gray-500">Review the stops and delivery context for this batch.</p>
        </div>
        <button ref={closeRef} type="button" onClick={onClose} aria-label="Close batch details" className="min-h-10 rounded-lg border px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Close</button>
      </div>
      <div className="mt-5 grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl bg-gray-50 p-3"><CalendarDays size={18} className="text-blue-600" /><p className="mt-2 text-xs text-gray-500">Schedule</p><p className="text-sm font-semibold">{formatDate(batch.delivery_date)} · {label(batch.delivery_window)}</p></div>
        <div className="rounded-xl bg-gray-50 p-3"><UserRound size={18} className="text-blue-600" /><p className="mt-2 text-xs text-gray-500">Rider</p><p className="text-sm font-semibold">{batch.rider_profile?.name || 'Not assigned'}</p></div>
        <div className="rounded-xl bg-gray-50 p-3"><Route size={18} className="text-blue-600" /><p className="mt-2 text-xs text-gray-500">Route</p><p className="text-sm font-semibold">{legs.length}/{batch.capacity} stops</p></div>
      </div>
      <div className="mt-6 flex items-center gap-2"><MapPin size={18} className="text-blue-600" /><h3 className="font-bold text-gray-950 dark:text-white">Stops in this batch</h3></div>
      {legs.length ? <DndProvider backend={HTML5Backend}><div className="mt-3 space-y-3">{legs.map((leg, index) => <BatchStopRow key={leg.id} leg={leg} index={index} total={legs.length} />)}</div></DndProvider> : <p className="mt-3 rounded-xl border border-dashed p-6 text-center text-sm text-gray-500">Historical stop details unavailable.</p>}
    </div>
  </Modal>;
}
