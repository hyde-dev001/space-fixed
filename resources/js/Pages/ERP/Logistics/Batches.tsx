import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatch, LogisticsRider, TrackingShipmentLeg } from '@/types/logistics';

export default function Batches() {
  const { batches, pool, riders } = usePage<{ batches: DeliveryBatch[]; pool: TrackingShipmentLeg[]; riders: LogisticsRider[] }>().props;
  const [selected, setSelected] = useState<number[]>([]);
  const [date, setDate] = useState('');
  const [window, setWindow] = useState('morning');
  const refresh = () => router.reload();
  const create = async () => { await logisticsApi.createBatch({ delivery_date: date, delivery_window: window, leg_ids: selected }); refresh(); };
  const offer = async (batchId: number, riderId: number) => { await logisticsApi.offerBatch(batchId, riderId); refresh(); };

  return <AppLayoutERP><Head title="Delivery Batches" /><main className="space-y-6 p-6">
    <h1 className="text-2xl font-bold">Delivery Batches</h1>
    <section className="rounded border bg-white p-4"><h2 className="font-semibold">Dispatch pool</h2>
      <div className="my-3 flex flex-wrap gap-2"><input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="rounded border p-2" /><select value={window} onChange={(e) => setWindow(e.target.value)} className="rounded border p-2"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select><button disabled={!date || !selected.length} onClick={create} className="rounded bg-blue-600 px-3 py-2 text-white disabled:opacity-50">Create batch</button></div>
      {pool.map((leg) => <label key={leg.id} className="block border-t py-2"><input type="checkbox" checked={selected.includes(leg.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, leg.id] : selected.filter((id) => id !== leg.id))} /> Leg #{leg.id} · {leg.scheduled_delivery_date} {leg.delivery_window}</label>)}
    </section>
    <section className="grid gap-4">{batches.map((batch) => <article key={batch.id} className="rounded border bg-white p-4"><div className="flex justify-between"><strong>Batch #{batch.id} · {batch.delivery_date} {batch.delivery_window}</strong><span>{batch.status}</span></div><p>{batch.assigned_stop_count}/{batch.capacity} stops</p>{batch.status === 'draft' && <div className="mt-2 flex gap-2">{riders.map((rider) => <button key={rider.id} onClick={() => offer(batch.id, rider.id)} className="rounded border px-2 py-1">Offer to {rider.name}</button>)}</div>}<ol className="mt-2 list-decimal pl-6">{batch.legs.map((leg) => <li key={leg.id}>Leg #{leg.id}</li>)}</ol></article>)}</section>
  </main></AppLayoutERP>;
}
