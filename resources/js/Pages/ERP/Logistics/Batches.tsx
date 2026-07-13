import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatch, LogisticsRider, TrackingShipmentLeg } from '@/types/logistics';

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
  const toggle = (id: number, checked: boolean) => setSelected(checked ? [...selected, id] : selected.filter((selectedId) => selectedId !== id));
  const create = async () => {
    try {
      setSubmitting(true);
      setError('');
      const unscheduledIds = selected.filter((id) => unscheduled.some((leg) => leg.id === id) && !scheduledThisAttempt.includes(id));
      if (unscheduledIds.length) {
        await logisticsApi.scheduleLegs(unscheduledIds, date, window);
        setScheduledThisAttempt((ids) => [...new Set([...ids, ...unscheduledIds])]);
      }
      const response = await logisticsApi.createBatch({ delivery_date: date, delivery_window: window, leg_ids: selected });
      if (riderId) await logisticsApi.offerBatch(response.data.batch.id, Number(riderId));
      router.reload();
    } catch {
      setError('This batch changed. Refresh and try again.');
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
        <input aria-label="Delivery date" type="date" value={date} onChange={(event) => setDate(event.target.value)} className="rounded border p-2" />
        <select aria-label="Delivery window" value={window} onChange={(event) => setWindow(event.target.value)} className="rounded border p-2"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select>
        <select aria-label="Rider" value={riderId} onChange={(event) => setRiderId(event.target.value)} className="rounded border p-2"><option value="">Assign later</option>{riders.map((rider) => <option key={rider.id} value={rider.id}>{rider.name}</option>)}</select>
        <button disabled={!date || !selected.length || submitting} onClick={create} className="rounded bg-blue-600 px-3 text-white disabled:opacity-50">{riderId ? 'Create & offer batch' : 'Create draft batch'}</button>
      </div>
      {unscheduled.map((leg) => <label key={leg.id} className="block border-t py-2"><input type="checkbox" checked={selected.includes(leg.id)} onChange={(event) => toggle(leg.id, event.target.checked)} /> {leg.shipment?.source_type === 'order' ? `Order #${leg.shipment.source_id}` : `Leg #${leg.id}`}</label>)}
      {pool.map((leg) => <label key={leg.id} className="block border-t py-2"><input type="checkbox" checked={selected.includes(leg.id)} onChange={(event) => toggle(leg.id, event.target.checked)} /> Leg #{leg.id} · {leg.scheduled_delivery_date} {leg.delivery_window}</label>)}
    </section>
    <section className="grid gap-4">{batches.map((batch) => <article key={batch.id} className="rounded border bg-white p-4">
      <div className="flex justify-between"><strong>Batch #{batch.id} · {batch.delivery_date} {batch.delivery_window}</strong><span>{batch.status}</span></div>
      <p>{batch.assigned_stop_count}/{batch.capacity} stops</p>
      {batch.status === 'draft' && <div className="mt-2 flex flex-wrap gap-2">{riders.map((rider) => <button key={rider.id} onClick={() => run(() => logisticsApi.offerBatch(batch.id, rider.id))} className="rounded border px-2 py-1">Offer to {rider.name}</button>)}</div>}
      <ol className="mt-2 list-decimal pl-6">{batch.legs.map((leg, index) => <li key={leg.id}>Leg #{leg.id}{batch.status === 'draft' && <span className="ml-2"><button disabled={index === 0} onClick={() => move(batch, index, -1)}>Up</button> <button disabled={index === batch.legs.length - 1} onClick={() => move(batch, index, 1)}>Down</button> <button onClick={() => run(() => logisticsApi.removeBatchStop(batch.id, leg.id))}>Remove</button> <button onClick={() => run(() => logisticsApi.markUrgent(leg.id))}>Urgent</button></span>}</li>)}</ol>
    </article>)}</section>
  </main></AppLayoutERP>;
}
