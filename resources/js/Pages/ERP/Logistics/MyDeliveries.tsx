import React, { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatch } from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';
import Shipments from './Shipments';

const destination = (leg: DeliveryBatch['legs'][number]) => {
  const snapshot = (leg.leg_type === 'inbound' ? leg.origin_snapshot : leg.destination_snapshot) ?? {};
  const value = (key: string) => typeof snapshot[key] === 'string' ? snapshot[key] as string : '';
  return { name: value('name'), phone: value('phone'), address: value('address'), instructions: value('delivery_instructions') };
};

const complete = (status: string) => ['awaiting_proof_approval', 'delivered'].includes(status);
const label = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const ordered = (batch: DeliveryBatch) => [...batch.legs].sort((a, b) =>
  (a.stop_sequence ?? Number.MAX_SAFE_INTEGER) - (b.stop_sequence ?? Number.MAX_SAFE_INTEGER) || a.id - b.id
);

export default function MyDeliveries() {
  const { batches = [] } = usePage<{ batches: DeliveryBatch[] }>().props;
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const [expandedLegs, setExpandedLegs] = useState<Record<number, number | null>>({});
  const [selectedByBatch, setSelectedByBatch] = useState<Record<number, number[]>>({});
  const [processingBatchId, setProcessingBatchId] = useState<number | null>(null);
  const nextSignature = batches.map((batch) => `${batch.id}:${ordered(batch).find((leg) => !complete(leg.status))?.id ?? 'done'}`).join('|');
  const act = async (action: () => Promise<unknown>) => { await action(); router.reload(); };

  const setBatchSelection = (batchId: number, legIds: number[]) => {
    setSelectedByBatch((current) => ({ ...current, [batchId]: legIds }));
  };

  const toggleStop = (batchId: number, legId: number) => {
    const selected = selectedByBatch[batchId] ?? [];
    setBatchSelection(batchId, selected.includes(legId) ? selected.filter((id) => id !== legId) : [...selected, legId]);
  };

  const runBulkAction = async (batch: DeliveryBatch, action: 'picked_up' | 'in_transit') => {
    const selected = new Set(selectedByBatch[batch.id] ?? []);
    const sourceStatus = action === 'picked_up' ? 'assigned' : 'picked_up';
    const eligible = ordered(batch).filter((leg) => selected.has(leg.id) && leg.status === sourceStatus);
    const skipped = selected.size - eligible.length;
    const actionLabel = action === 'picked_up' ? 'Mark Picked Up' : 'Mark In Transit';

    if (!eligible.length) {
      await workflowFeedback.warning('No eligible stops', `${selected.size} selected · ${skipped} skipped`);
      return;
    }

    const confirmation = await workflowFeedback.confirm({
      title: `${actionLabel}?`,
      text: `${eligible.length} eligible · ${skipped} skipped`,
      confirmButtonText: actionLabel,
    });
    if (!confirmation.isConfirmed) return;

    setProcessingBatchId(batch.id);
    try {
      const results = await Promise.allSettled(eligible.map((leg) => action === 'picked_up'
        ? logisticsApi.markPickedUp(leg.id)
        : logisticsApi.outForDelivery(leg.id)));
      const successful = results.filter((result) => result.status === 'fulfilled').length;
      const failed = results.length - successful;

      await workflowFeedback.alert({
        icon: failed ? 'warning' : 'success',
        title: `${actionLabel} complete`,
        text: `${successful} successful · ${skipped} skipped · ${failed} failed`,
      });
      setBatchSelection(batch.id, []);
      router.reload({ only: ['batches', 'shipments'] });
    } finally {
      setProcessingBatchId(null);
    }
  };

  useEffect(() => setExpandedLegs({}), [nextSignature]);

  return <Shipments>
    {batches.length > 0 && <section className="space-y-4">
      <div>
        <h2 className="text-xl font-bold text-gray-950">My delivery batches</h2>
        <p className="text-sm text-gray-500">Follow the highlighted stop and submit proof to advance your route.</p>
      </div>

      {batches.map((batch) => {
        const legs = ordered(batch);
        const completed = legs.filter((leg) => complete(leg.status)).length;
        const nextLeg = legs.find((leg) => !complete(leg.status));
        const percent = legs.length ? Math.round((completed / legs.length) * 100) : 0;
        const selectableLegs = batch.status === 'in_progress'
          ? legs.filter((leg) => ['assigned', 'picked_up'].includes(leg.status))
          : [];
        const selectedIds = selectedByBatch[batch.id] ?? [];
        const selectedAssigned = legs.filter((leg) => selectedIds.includes(leg.id) && leg.status === 'assigned').length;
        const selectedPickedUp = legs.filter((leg) => selectedIds.includes(leg.id) && leg.status === 'picked_up').length;
        const allSelected = selectableLegs.length > 0 && selectableLegs.every((leg) => selectedIds.includes(leg.id));
        const isProcessing = processingBatchId !== null;

        return <article key={batch.id} className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
          <header className="border-b border-gray-100 p-4 sm:p-5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h3 className="font-bold text-gray-950">Batch #{batch.id}</h3>
                <p className="text-sm text-gray-500">{String(batch.delivery_date).split('T')[0]} · {label(batch.delivery_window)}</p>
              </div>
              <span className="w-fit rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">{label(batch.status)}</span>
            </div>
            <div className="mt-4 flex items-center justify-between text-sm">
              <span className="font-semibold text-gray-700">{legs.length ? `${completed} of ${legs.length} completed` : '0 completed'}</span>
              <span className="text-gray-500">{percent}%</span>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
              <div className="h-full rounded-full bg-green-500 transition-all" style={{ width: `${percent}%` }} />
            </div>
            {selectableLegs.length > 0 && <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
              <label className="flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input
                  type="checkbox"
                  aria-label={`Select all eligible stops in batch ${batch.id}`}
                  checked={allSelected}
                  disabled={isProcessing}
                  onChange={() => setBatchSelection(batch.id, allSelected ? [] : selectableLegs.map((leg) => leg.id))}
                  className="h-4 w-4 rounded border-gray-300 text-blue-600"
                />
                Select all
              </label>
              <span className="mr-auto text-sm text-gray-500">{selectedIds.length} selected</span>
              <button
                type="button"
                disabled={!selectedIds.length || isProcessing}
                onClick={() => runBulkAction(batch, 'picked_up')}
                className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
              >Mark Picked Up ({selectedAssigned})</button>
              <button
                type="button"
                disabled={!selectedIds.length || isProcessing}
                onClick={() => runBulkAction(batch, 'in_transit')}
                className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
              >Mark In Transit ({selectedPickedUp})</button>
              <button
                type="button"
                disabled={!selectedIds.length || isProcessing}
                onClick={() => setBatchSelection(batch.id, [])}
                className="rounded-lg border px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
              >Clear</button>
            </div>}
          </header>

          <div className="space-y-3 p-4 sm:p-5">
            {legs.length === 0 && <p className="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">No stops in this batch</p>}
            {legs.length > 0 && !nextLeg && <p className="rounded-lg bg-green-50 p-3 text-sm font-semibold text-green-700">All stops completed</p>}

            {legs.map((leg) => {
              const recipient = destination(leg);
              const pickup = leg.proofs?.filter((proof) => proof.handoff_type === 'pickup').at(-1);
              const isDone = complete(leg.status);
              const isNext = nextLeg?.id === leg.id;
              const isExpanded = isNext || expandedLegs[batch.id] === leg.id;
              const isSelectable = batch.status === 'in_progress' && ['assigned', 'picked_up'].includes(leg.status);
              const stop = leg.stop_sequence ?? '—';

              return <div
                key={leg.id}
                data-testid="batch-stop"
                data-leg-id={leg.id}
                className={`rounded-xl border p-4 transition ${isNext ? 'border-blue-400 bg-blue-50/60 ring-1 ring-blue-200' : isDone ? 'border-gray-200 bg-gray-50 opacity-70' : 'border-gray-200'}`}
              >
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    {isSelectable && <input
                      type="checkbox"
                      aria-label={`Select stop ${stop} in batch ${batch.id}`}
                      checked={selectedIds.includes(leg.id)}
                      disabled={isProcessing}
                      onChange={() => toggleStop(batch.id, leg.id)}
                      className="h-4 w-4 rounded border-gray-300 text-blue-600"
                    />}
                    <span className={`grid h-9 w-9 place-items-center rounded-full text-sm font-bold ${isDone ? 'bg-green-100 text-green-700' : isNext ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'}`}>
                      {isDone ? '✓' : stop}
                    </span>
                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold text-gray-950">Stop {stop}</p>
                        {isNext && <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-bold text-blue-700">Next stop</span>}
                        {leg.status === 'awaiting_proof_approval' && <span className="text-xs font-semibold text-green-700">Proof submitted</span>}
                        {leg.status === 'delivered' && <span className="text-xs font-semibold text-green-700">Delivered</span>}
                      </div>
                      <p className="text-xs text-gray-500">Leg #{leg.id}</p>
                    </div>
                  </div>
                  {!isNext && <button
                    type="button"
                    aria-label={`${isExpanded ? 'Close' : 'Open delivery'} for stop ${stop}`}
                    onClick={() => setExpandedLegs((current) => ({ ...current, [batch.id]: isExpanded ? null : leg.id }))}
                    className="rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"
                  >{isExpanded ? 'Close' : 'Open delivery'}</button>}
                </div>

                {isExpanded && <div className="mt-4 border-t border-gray-200 pt-4">
                  <div className="grid gap-2 text-sm text-gray-700 sm:grid-cols-2">
                    <p><strong>Receiver:</strong> {recipient.name || 'Not provided'}</p>
                    <p><strong>Phone:</strong> {recipient.phone || 'Not provided'}</p>
                    <p className="sm:col-span-2"><strong>Address:</strong> {recipient.address || 'Not provided'}</p>
                    {recipient.instructions && <p className="sm:col-span-2"><strong>Instructions:</strong> {recipient.instructions}</p>}
                  </div>
                  <div className="mt-4 flex flex-wrap gap-2">
                    {recipient.phone && <a href={`tel:${recipient.phone}`} className="rounded-lg border px-3 py-2 text-sm font-semibold text-gray-700">Call</a>}
                    {recipient.address && <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(recipient.address)}`} target="_blank" rel="noreferrer" className="rounded-lg border px-3 py-2 text-sm font-semibold text-gray-700">Directions</a>}
                    {batch.status === 'in_progress' && leg.status === 'assigned' && pickup && <button className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white" onClick={() => act(() => logisticsApi.confirmPickup(leg.id, pickup.id))}>Confirm pickup</button>}
                    {batch.status === 'in_progress' && leg.status === 'picked_up' && <button className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white" onClick={() => act(() => logisticsApi.outForDelivery(leg.id))}>Out for delivery</button>}
                  </div>
                </div>}
              </div>;
            })}

            {batch.status === 'offered' && <div className="flex flex-wrap gap-2 border-t pt-4">
              <button className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white" onClick={() => act(() => logisticsApi.acceptBatch(batch.id))}>Accept</button>
              <input aria-label={`Rejection reason for batch ${batch.id}`} className="rounded-lg border px-3 py-2 text-sm" placeholder="Reason for rejection" value={reasons[batch.id] || ''} onChange={(event) => setReasons({ ...reasons, [batch.id]: event.target.value })} />
              <button disabled={!reasons[batch.id]?.trim()} className="rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-50" onClick={() => act(() => logisticsApi.rejectBatch(batch.id, reasons[batch.id]))}>Reject</button>
            </div>}
            {batch.status === 'accepted' && <button className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white" onClick={() => act(() => logisticsApi.startBatch(batch.id))}>Start batch</button>}
          </div>
        </article>;
      })}
    </section>}
  </Shipments>;
}
