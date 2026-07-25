import React, { useEffect, useRef, useState } from 'react';
import { AlertTriangle, CalendarDays, Flame, Route } from 'lucide-react';
import { Modal } from '@/components/ui/modal';
import type { DeliveryBatch, LogisticsRider } from '@/types/logistics';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' })
  .format(new Date(`${value.slice(0, 10)}T00:00:00Z`));

type Props = {
  isOpen: boolean;
  batch?: DeliveryBatch;
  batches: DeliveryBatch[];
  riders: LogisticsRider[];
  dailyRiderCapacity: number;
  forceCapacityOverrideForRiderId?: number;
  submitting: boolean;
  error: string;
  onClose: () => void;
  onOffer: (riderId: number, capacityOverrideReason?: string) => void;
};

export default function OfferBatchModal({ isOpen, batch, batches, riders, dailyRiderCapacity, forceCapacityOverrideForRiderId, submitting, error, onClose, onOffer }: Props) {
  const [riderId, setRiderId] = useState('');
  const [capacityOverrideReason, setCapacityOverrideReason] = useState('');
  const dialogRef = useRef<HTMLDivElement>(null);
  const riderRef = useRef<HTMLSelectElement>(null);
  const returnFocusRef = useRef<HTMLElement | null>(null);
  const rider = riders.find((candidate) => candidate.id === Number(riderId));

  useEffect(() => {
    if (!isOpen) return;
    returnFocusRef.current = document.activeElement as HTMLElement;
    setRiderId('');
    setCapacityOverrideReason('');
    window.setTimeout(() => riderRef.current?.focus(), 0);
    return () => returnFocusRef.current?.focus();
  }, [isOpen]);

  if (!batch) return null;
  const urgentCount = batch.legs.filter((leg) => leg.urgent_at).length;
  const usedBy = (candidate: LogisticsRider) => batches
    .filter((other) => other.id !== batch.id
      && other.delivery_date.slice(0, 10) === batch.delivery_date.slice(0, 10)
      && other.rider_profile?.id === candidate.id
      && ['offered', 'accepted', 'in_progress', 'completed'].includes(other.status))
    .reduce((total, other) => total + other.assigned_stop_count, 0);
  const riderCapacity = rider?.daily_capacity ?? dailyRiderCapacity;
  const used = rider ? usedBy(rider) : 0;
  const projected = used + batch.assigned_stop_count;
  const exceedsRiderCapacity = Boolean(rider && projected > riderCapacity);
  const overrideRequired = exceedsRiderCapacity || Boolean(rider && forceCapacityOverrideForRiderId === rider.id);
  const handleKeys = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'Tab') return;
    const focusable = dialogRef.current?.querySelectorAll<HTMLElement>('button:not([disabled]), select:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    if (!focusable?.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  };

  return <Modal isOpen={isOpen} onClose={onClose} size="2xl" showCloseButton={false}>
    <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby={`offer-batch-${batch.id}-title`} onKeyDown={handleKeys} className="max-h-[90vh] overflow-y-auto p-5 sm:p-7">
      <div className="flex items-start justify-between gap-4">
        <div><h2 id={`offer-batch-${batch.id}-title`} className="text-xl font-bold text-gray-950 dark:text-white">Review &amp; Offer Batch #{batch.id}</h2><p className="mt-1 text-sm text-gray-500">Confirm the route and choose the rider who will receive one batch offer.</p></div>
        <button type="button" onClick={onClose} aria-label="Close review" className="min-h-10 rounded-lg border px-3 text-sm">Close</button>
      </div>
      {error && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      <div className="mt-5 grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl bg-gray-50 p-3"><CalendarDays size={18} className="text-blue-600" /><p className="mt-2 text-xs text-gray-500">Schedule</p><p className="text-sm font-semibold">{formatDate(batch.delivery_date)} · {batch.delivery_window === 'morning' ? 'Morning' : 'Afternoon'}</p></div>
        <div className="rounded-xl bg-gray-50 p-3"><Route size={18} className="text-blue-600" /><p className="mt-2 text-sm font-semibold">{batch.legs.length} ordered stops</p></div>
        <div className="rounded-xl bg-gray-50 p-3"><Flame size={18} className="text-red-600" /><p className="mt-2 text-sm font-semibold">{urgentCount} urgent</p></div>
      </div>
      <label className="mt-5 block text-sm font-semibold text-gray-800 dark:text-gray-100">Select rider
        <select ref={riderRef} aria-label="Select rider" value={riderId} onChange={(event) => { setRiderId(event.target.value); setCapacityOverrideReason(''); }} className="mt-2 min-h-11 w-full rounded-xl border border-gray-300 px-3 font-normal">
          <option value="">Choose an available rider</option>
          {riders.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name} · {usedBy(candidate)}/{candidate.daily_capacity ?? dailyRiderCapacity} stops used today</option>)}
        </select>
      </label>
      {!riders.length && <p className="mt-2 text-sm text-amber-700">No available riders. Keep this batch as a draft and try again later.</p>}
      {rider && <p className="mt-3 text-sm font-semibold text-gray-700">{used} stops used + {batch.assigned_stop_count} stops = {projected}/{riderCapacity}</p>}
      {exceedsRiderCapacity && <p className="mt-3 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800"><AlertTriangle size={17} />This route exceeds {rider?.name}’s capacity of {riderCapacity} {riderCapacity === 1 ? 'stop' : 'stops'}.</p>}
      {overrideRequired &&
        <label className="mt-3 block text-sm font-semibold text-gray-800">Capacity override reason
          <textarea aria-label="Capacity override reason" value={capacityOverrideReason} onChange={(event) => setCapacityOverrideReason(event.target.value)} className="mt-2 min-h-24 w-full rounded-xl border border-gray-300 p-3 font-normal" />
        </label>}
      <ol className="mt-5 space-y-2 rounded-xl border border-gray-200 p-3">{batch.legs.map((leg, index) => <li key={leg.id} className="flex items-center gap-3 rounded-lg bg-gray-50 p-3 text-sm"><span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-blue-600 font-bold text-white">{index + 1}</span><span><strong>{leg.destination_snapshot?.name || `Stop ${index + 1}`}</strong><span className="block text-xs text-gray-500">{leg.destination_snapshot?.address || 'Address not provided'}</span></span></li>)}</ol>
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" onClick={onClose} className="min-h-11 rounded-xl border px-4 text-sm font-semibold">Keep as Draft</button>
        <button type="button" disabled={!riderId || submitting || (overrideRequired && !capacityOverrideReason.trim())} onClick={() => onOffer(Number(riderId), capacityOverrideReason.trim() || undefined)} className="min-h-11 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white disabled:opacity-40">{submitting ? 'Offering Batch...' : 'Offer Batch to Rider'}</button>
      </div>
    </div>
  </Modal>;
}
