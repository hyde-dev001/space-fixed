import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatch, LogisticsRider, TrackingShipmentLeg } from '@/types/logistics';
import { DndProvider } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import BatchCard from './components/BatchCard';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const errorMessage = (error: unknown) => {
  const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;
  return Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? 'This batch changed. Refresh and try again.';
};

export default function Batches() {
  const { batches, pool, riders, unscheduled = [] } = usePage<{ batches: DeliveryBatch[]; pool: TrackingShipmentLeg[]; riders: LogisticsRider[]; unscheduled: Array<TrackingShipmentLeg & { shipment?: { source_type: string; source_id: number } }> }>().props;
  const [selected, setSelected] = useState<number[]>([]);
  const [date, setDate] = useState('');
  const [window, setWindow] = useState('morning');
  const [riderId, setRiderId] = useState('');
  const [scheduledThisAttempt, setScheduledThisAttempt] = useState<number[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const run = async (action: () => Promise<unknown>) => { try { setError(''); await action(); router.reload(); } catch { setError('This batch changed. Refresh and try again.'); } };
  const eligiblePool = pool.filter((leg) => leg.scheduled_delivery_date?.slice(0, 10) === date && leg.delivery_window === window);
  const eligibleIds = [...unscheduled.map((leg) => leg.id), ...eligiblePool.map((leg) => leg.id)];
  const selectedRider = riders.find((rider) => rider.id === Number(riderId));
  const toggle = (id: number, checked: boolean) => setSelected(checked ? [...selected, id] : selected.filter((selectedId) => selectedId !== id));
  const changeSlot = (nextDate: string, nextWindow: string) => {
    if (scheduledThisAttempt.length) {
      setSelected([]);
      setScheduledThisAttempt([]);
      router.reload();
    } else {
      setSelected((ids) => ids.filter((id) => unscheduled.some((leg) => leg.id === id)
        || pool.some((leg) => leg.id === id && leg.scheduled_delivery_date?.slice(0, 10) === nextDate && leg.delivery_window === nextWindow)));
    }
    setDate(nextDate);
    setWindow(nextWindow);
  };
  const create = async () => {
    let batchCreated = false;
    try {
      setSubmitting(true);
      setError('');
      const unscheduledIds = selected.filter((id) => unscheduled.some((leg) => leg.id === id) && !scheduledThisAttempt.includes(id));
      if (unscheduledIds.length) {
        await logisticsApi.scheduleLegs(unscheduledIds, date, window);
        setScheduledThisAttempt((ids) => [...new Set([...ids, ...unscheduledIds])]);
      }
      const response = await logisticsApi.createBatch({ delivery_date: date, delivery_window: window, leg_ids: selected });
      batchCreated = true;
      setSelected([]);
      setScheduledThisAttempt([]);
      setRiderId('');
      if (riderId) await logisticsApi.offerBatch(response.data.batch.id, Number(riderId));
      router.reload();
    } catch (caught) {
      if (batchCreated) {
        setError('Draft batch created, but the rider offer failed. Assign a rider from the draft batch below.');
        router.reload();
      } else {
        setError(errorMessage(caught));
      }
    } finally {
      setSubmitting(false);
    }
  };
  const move = (batch: DeliveryBatch, index: number, offset: number) => {
    const ids = batch.legs.map((leg) => leg.id);
    [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]];
    return run(() => logisticsApi.updateBatch(batch.id, ids));
  };

  return <AppLayoutERP><Head title="Delivery Batches" /><main className="space-y-6 p-6">
    <h1 className="text-2xl font-bold">Delivery Batches</h1>
    {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{error}</p>}
    <section className="rounded border bg-white p-4"><h2 className="font-semibold">Create delivery batch</h2>
      <div className="my-3 flex flex-wrap gap-2">
        <input aria-label="Delivery date" type="date" value={date} onChange={(event) => changeSlot(event.target.value, window)} className="rounded border p-2" />
        <select aria-label="Delivery window" value={window} onChange={(event) => changeSlot(date, event.target.value)} className="rounded border p-2"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select>
        <select aria-label="Rider" value={riderId} onChange={(event) => setRiderId(event.target.value)} className="rounded border p-2"><option value="">{riders.length ? 'Assign later' : 'No available riders'}</option>{riders.map((rider) => <option key={rider.id} value={rider.id}>{rider.name}</option>)}</select>
        <button disabled={!date || !selected.length || submitting} onClick={create} className="rounded bg-blue-600 px-3 text-white disabled:opacity-50">{submitting ? 'Creating batch...' : riderId ? 'Create & offer batch' : 'Create draft batch'}</button>
      </div>
      <div className="mb-3 flex flex-wrap items-center gap-4 text-sm text-gray-600">
        <label><input aria-label="Select all eligible deliveries" type="checkbox" disabled={!eligibleIds.length} checked={eligibleIds.length > 0 && eligibleIds.every((id) => selected.includes(id))} onChange={(event) => setSelected(event.target.checked ? eligibleIds : [])} /> Select all</label>
        <span>{selected.length} selected</span>
        {selectedRider && <span>Rider capacity: {selectedRider.daily_capacity ?? 'Not set'}{selectedRider.daily_capacity ? ' stops' : ''}</span>}
      </div>
      {unscheduled.map((leg) => <label key={leg.id} className="block border-t py-2"><input type="checkbox" checked={selected.includes(leg.id)} onChange={(event) => toggle(leg.id, event.target.checked)} /> {leg.shipment?.source_type === 'order' ? `Order #${leg.shipment.source_id}` : `Leg #${leg.id}`}</label>)}
      {pool.map((leg) => {
        const eligible = eligiblePool.some((candidate) => candidate.id === leg.id);
        return <label key={leg.id} className={`block border-t py-2 ${eligible ? '' : 'text-gray-400'}`}><input type="checkbox" disabled={!eligible} checked={selected.includes(leg.id)} onChange={(event) => toggle(leg.id, event.target.checked)} /> Leg #{leg.id} · {formatDate(leg.scheduled_delivery_date!)} · {leg.delivery_window === 'morning' ? 'Morning' : 'Afternoon'}</label>;
      })}
      {!unscheduled.length && !pool.length && <p className="mt-3 text-sm text-gray-500">No deliveries ready for batching.</p>}
    </section>
    <DndProvider backend={HTML5Backend}><section className="grid gap-4">{batches.map((batch) => <BatchCard key={batch.id} batch={batch} onOpen={() => undefined} />)}</section></DndProvider>
  </main></AppLayoutERP>;
}
