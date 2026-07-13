import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatch, LogisticsRider, TrackingShipmentLeg } from '@/types/logistics';

type Suggestion = { rider_profile_id: number; capacity: number; overload_count: number; leg_ids: number[] };

export default function Batches() {
  const { batches, pool, riders, unscheduled = [] } = usePage<{ batches: DeliveryBatch[]; pool: TrackingShipmentLeg[]; riders: LogisticsRider[]; unscheduled: Array<TrackingShipmentLeg & { shipment?: { source_type: string; source_id: number } }> }>().props;
  const [selected, setSelected] = useState<number[]>([]);
  const [unscheduledSelected, setUnscheduledSelected] = useState<number[]>([]);
  const [scheduleDate, setScheduleDate] = useState('');
  const [scheduleWindow, setScheduleWindow] = useState('morning');
  const [date, setDate] = useState('');
  const [window, setWindow] = useState('morning');
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [error, setError] = useState('');
  const run = async (action: () => Promise<unknown>) => { try { setError(''); await action(); router.reload(); } catch { setError('This batch changed. Refresh and try again.'); } };
  const move = (batch: DeliveryBatch, index: number, offset: number) => {
    const ids = batch.legs.map((leg) => leg.id);
    [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]];
    return run(() => logisticsApi.updateBatch(batch.id, ids));
  };

  return <AppLayoutERP><Head title="Delivery Batches" /><main className="space-y-6 p-6">
    <h1 className="text-2xl font-bold">Delivery Batches</h1>
    {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{error}</p>}
    <section className="rounded border bg-white p-4"><h2 className="font-semibold">Unscheduled deliveries</h2>
      <div className="my-3 flex flex-wrap gap-2">
        <input aria-label="Schedule date" type="date" value={scheduleDate} onChange={(event) => setScheduleDate(event.target.value)} className="rounded border p-2" />
        <select aria-label="Schedule window" value={scheduleWindow} onChange={(event) => setScheduleWindow(event.target.value)} className="rounded border p-2"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select>
        <button disabled={!scheduleDate || !unscheduledSelected.length} onClick={() => run(() => logisticsApi.scheduleLegs(unscheduledSelected, scheduleDate, scheduleWindow))} className="rounded bg-blue-600 px-3 text-white disabled:opacity-50">Schedule deliveries</button>
      </div>
      {unscheduled.map((leg) => <label key={leg.id} className="block border-t py-2"><input type="checkbox" checked={unscheduledSelected.includes(leg.id)} onChange={(event) => setUnscheduledSelected(event.target.checked ? [...unscheduledSelected, leg.id] : unscheduledSelected.filter((id) => id !== leg.id))} /> {leg.shipment?.source_type === 'order' ? `Order #${leg.shipment.source_id}` : `Leg #${leg.id}`}</label>)}
      {!unscheduled.length && <p className="mt-3 text-sm text-gray-500">No unscheduled deliveries.</p>}
    </section>
    <section className="rounded border bg-white p-4"><h2 className="font-semibold">Dispatch pool</h2>
      <div className="my-3 flex flex-wrap gap-2">
        <input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="rounded border p-2" />
        <select value={window} onChange={(event) => setWindow(event.target.value)} className="rounded border p-2"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select>
        <button disabled={!date} onClick={async () => setSuggestions((await logisticsApi.suggestions(date, window)).data.suggestions)} className="rounded border px-3 disabled:opacity-50">Suggest batches</button>
        <button disabled={!date || !selected.length} onClick={() => run(() => logisticsApi.createBatch({ delivery_date: date, delivery_window: window, leg_ids: selected }))} className="rounded bg-blue-600 px-3 text-white disabled:opacity-50">Create batch</button>
      </div>
      {suggestions.map((suggestion) => <button key={suggestion.rider_profile_id} className="mr-2 rounded border p-2" onClick={() => setSelected(suggestion.leg_ids)}>Rider #{suggestion.rider_profile_id}: {suggestion.leg_ids.length}/{suggestion.capacity}{suggestion.overload_count ? ` (${suggestion.overload_count} over)` : ''}</button>)}
      {pool.map((leg) => <label key={leg.id} className="block border-t py-2"><input type="checkbox" checked={selected.includes(leg.id)} onChange={(event) => setSelected(event.target.checked ? [...selected, leg.id] : selected.filter((id) => id !== leg.id))} /> Leg #{leg.id} · {leg.scheduled_delivery_date} {leg.delivery_window}</label>)}
    </section>
    <section className="grid gap-4">{batches.map((batch) => <article key={batch.id} className="rounded border bg-white p-4">
      <div className="flex justify-between"><strong>Batch #{batch.id} · {batch.delivery_date} {batch.delivery_window}</strong><span>{batch.status}</span></div>
      <p>{batch.assigned_stop_count}/{batch.capacity} stops</p>
      {batch.status === 'draft' && <div className="mt-2 flex flex-wrap gap-2">{riders.map((rider) => <button key={rider.id} onClick={() => run(() => logisticsApi.offerBatch(batch.id, rider.id))} className="rounded border px-2 py-1">Offer to {rider.name}</button>)}</div>}
      <ol className="mt-2 list-decimal pl-6">{batch.legs.map((leg, index) => <li key={leg.id}>Leg #{leg.id}{batch.status === 'draft' && <span className="ml-2"><button disabled={index === 0} onClick={() => move(batch, index, -1)}>Up</button> <button disabled={index === batch.legs.length - 1} onClick={() => move(batch, index, 1)}>Down</button> <button onClick={() => run(() => logisticsApi.removeBatchStop(batch.id, leg.id))}>Remove</button> <button onClick={() => run(() => logisticsApi.markUrgent(leg.id))}>Urgent</button></span>}</li>)}</ol>
    </article>)}</section>
  </main></AppLayoutERP>;
}
