import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatch } from '@/types/logistics';
import Shipments from './Shipments';

export default function MyDeliveries() {
  const { batches = [] } = usePage<{ batches: DeliveryBatch[] }>().props;
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const act = async (action: () => Promise<unknown>) => { await action(); router.reload(); };

  return <>
    {batches.length > 0 && <section className="mx-6 mt-6 space-y-3 rounded border bg-white p-4">
      <h2 className="text-lg font-bold">My delivery batches</h2>
      {batches.map((batch) => <article key={batch.id} className="rounded border p-3">
        <div className="flex justify-between"><strong>Batch #{batch.id} · {batch.delivery_date} {batch.delivery_window}</strong><span>{batch.status}</span></div>
        <ol className="my-2 list-decimal pl-6">{batch.legs.map((leg) => <li key={leg.id}>Stop {leg.stop_sequence}: Leg #{leg.id}</li>)}</ol>
        {batch.status === 'offered' && <div className="flex flex-wrap gap-2">
          <button className="rounded bg-blue-600 px-3 py-1 text-white" onClick={() => act(() => logisticsApi.acceptBatch(batch.id))}>Accept</button>
          <input aria-label={`Rejection reason for batch ${batch.id}`} className="rounded border px-2" value={reasons[batch.id] || ''} onChange={(event) => setReasons({ ...reasons, [batch.id]: event.target.value })} />
          <button disabled={!reasons[batch.id]?.trim()} className="rounded border px-3 py-1 disabled:opacity-50" onClick={() => act(() => logisticsApi.rejectBatch(batch.id, reasons[batch.id]))}>Reject</button>
        </div>}
        {batch.status === 'accepted' && <button className="rounded bg-blue-600 px-3 py-1 text-white" onClick={() => act(() => logisticsApi.startBatch(batch.id))}>Start batch</button>}
      </article>)}
    </section>}
    <Shipments />
  </>;
}
