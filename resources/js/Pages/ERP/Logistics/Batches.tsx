import React, { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DndProvider } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type { DeliveryBatchPageProps, TrackingShipmentLeg } from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';
import AvailableDeliveriesPanel from './components/AvailableDeliveriesPanel';
import BatchCard from './components/BatchCard';
import BatchWorkspace from './components/BatchWorkspace';

const errorMessage = (error: unknown) => {
  const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;
  return Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? 'This batch changed. Refresh and try again.';
};

const sourceLabel = (leg: TrackingShipmentLeg) => leg.shipment?.source_type === 'order'
  ? `Order #${leg.shipment.source_id}`
  : `Leg #${leg.id}`;

export default function Batches() {
  const { batches, pool, unscheduled = [], dailyRiderCapacity } = usePage<DeliveryBatchPageProps>().props;
  const [building, setBuilding] = useState(false);
  const [selectedBatchId, setSelectedBatchId] = useState<number>();
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [date, setDate] = useState('');
  const [window, setWindow] = useState('morning');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [overrideReason, setOverrideReason] = useState('');
  const [scheduledThisAttempt, setScheduledThisAttempt] = useState<number[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [busyLegId, setBusyLegId] = useState<number>();
  const [error, setError] = useState('');

  const allDeliveries = useMemo(() => {
    const rows = new Map<number, TrackingShipmentLeg>();
    [...unscheduled, ...pool].forEach((leg) => rows.set(leg.id, leg));
    return [...rows.values()];
  }, [pool, unscheduled]);
  const unscheduledIds = useMemo(() => new Set(unscheduled.map((leg) => leg.id)), [unscheduled]);
  const filteredDeliveries = useMemo(() => {
    const query = search.trim().toLowerCase();
    return allDeliveries.filter((leg) => {
      const needsScheduling = unscheduledIds.has(leg.id);
      if (status === 'unscheduled' && !needsScheduling) return false;
      if (status === 'scheduled' && needsScheduling) return false;
      if (!needsScheduling && date && leg.scheduled_delivery_date?.slice(0, 10) !== date) return false;
      if (!needsScheduling && leg.delivery_window !== window) return false;
      if (!query) return true;
      const destination = leg.destination_snapshot;
      return [sourceLabel(leg), destination?.name, destination?.phone, destination?.address]
        .some((value) => String(value ?? '').toLowerCase().includes(query));
    });
  }, [allDeliveries, date, search, status, unscheduledIds, window]);
  const selectedLegs = selectedIds.flatMap((id) => {
    const leg = allDeliveries.find((candidate) => candidate.id === id);
    return leg ? [leg] : [];
  });
  const selectedBatch = batches.find((batch) => batch.id === selectedBatchId);

  const startNewBatch = () => {
    setBuilding(true);
    setSelectedBatchId(undefined);
    setSelectedIds([]);
    setScheduledThisAttempt([]);
    setOverrideReason('');
    setError('');
  };
  const changeSlot = (nextDate: string, nextWindow: string) => {
    if (scheduledThisAttempt.length) {
      setSelectedIds([]);
      setScheduledThisAttempt([]);
      router.reload({ only: ['pool', 'unscheduled'] });
    } else {
      setSelectedIds((ids) => ids.filter((id) => unscheduledIds.has(id) || pool.some((leg) => leg.id === id
        && (!nextDate || leg.scheduled_delivery_date?.slice(0, 10) === nextDate)
        && leg.delivery_window === nextWindow)));
    }
    setDate(nextDate);
    setWindow(nextWindow);
  };
  const toggle = (id: number, checked: boolean) => setSelectedIds((ids) => checked
    ? [...ids.filter((selectedId) => selectedId !== id), id]
    : ids.filter((selectedId) => selectedId !== id));
  const selectAll = (checked: boolean) => {
    const matchingIds = filteredDeliveries.map((leg) => leg.id);
    setSelectedIds((ids) => checked
      ? [...ids.filter((id) => !matchingIds.includes(id)), ...matchingIds]
      : ids.filter((id) => !matchingIds.includes(id)));
  };
  const refreshBatchData = () => router.reload({ only: ['batches', 'pool', 'unscheduled', 'riders'] });
  const handleMutationError = async (caught: unknown) => {
    setError(errorMessage(caught));
    const statusCode = (caught as { response?: { status?: number } })?.response?.status;
    if (![409, 422].includes(statusCode ?? 0)) return;
    const result = await workflowFeedback.confirm({
      title: 'Batch changed',
      text: 'This batch was updated elsewhere. Refresh the batch data before trying again.',
      confirmButtonText: 'Refresh batch data',
    });
    if (result.isConfirmed) refreshBatchData();
  };
  const reorder = (ids: number[], from: number, to: number) => {
    if (to < 0 || to >= ids.length) return ids;
    const reordered = [...ids];
    const [moved] = reordered.splice(from, 1);
    reordered.splice(to, 0, moved);
    return reordered;
  };
  const moveStops = async (from: number, to: number) => {
    const currentIds = selectedBatch ? selectedBatch.legs.map((leg) => leg.id) : selectedIds;
    const reordered = reorder(currentIds, from, to);
    if (reordered === currentIds) return;
    if (!selectedBatch) {
      setSelectedIds(reordered);
      return;
    }
    const legId = currentIds[from];
    try {
      setBusyLegId(legId);
      setError('');
      await logisticsApi.updateBatch(selectedBatch.id, reordered);
      await workflowFeedback.toast('success', 'Stop order updated');
      refreshBatchData();
    } catch (caught) {
      await handleMutationError(caught);
    } finally {
      setBusyLegId(undefined);
    }
  };
  const removeStop = async (leg: TrackingShipmentLeg, index: number) => {
    if (!selectedBatch) {
      setSelectedIds((ids) => ids.filter((id) => id !== leg.id));
      return;
    }
    const finalStop = selectedBatch.legs.length === 1;
    const customer = leg.destination_snapshot?.name || sourceLabel(leg);
    const result = await workflowFeedback.confirm({
      title: finalStop ? 'Delete this empty batch?' : `Remove stop ${index + 1}?`,
      text: finalStop
        ? `This is the final stop for ${customer}. Removing it will delete the empty batch.`
        : `Remove stop ${index + 1} for ${customer} from this batch?`,
      confirmButtonText: finalStop ? 'Remove stop and delete batch' : 'Remove stop',
    });
    if (!result.isConfirmed) return;
    try {
      setBusyLegId(leg.id);
      setError('');
      await logisticsApi.removeBatchStop(selectedBatch.id, leg.id);
      if (finalStop) setSelectedBatchId(undefined);
      await workflowFeedback.toast('success', 'Stop removed');
      refreshBatchData();
    } catch (caught) {
      await handleMutationError(caught);
    } finally {
      setBusyLegId(undefined);
    }
  };
  const toggleUrgent = async (leg: TrackingShipmentLeg) => {
    try {
      setBusyLegId(leg.id);
      setError('');
      await logisticsApi.markUrgent(leg.id, !leg.urgent_at);
      await workflowFeedback.toast('success', 'Urgent state updated');
      refreshBatchData();
    } catch (caught) {
      await handleMutationError(caught);
    } finally {
      setBusyLegId(undefined);
    }
  };
  const moveLocal = (from: number, to: number) => {
    if (to < 0 || to >= selectedIds.length) return;
    setSelectedIds((ids) => {
      return reorder(ids, from, to);
    });
  };
  const saveDraft = async () => {
    try {
      setSubmitting(true);
      setError('');
      const toSchedule = selectedIds.filter((id) => unscheduledIds.has(id) && !scheduledThisAttempt.includes(id));
      if (toSchedule.length) {
        await logisticsApi.scheduleLegs(toSchedule, date, window);
        setScheduledThisAttempt((ids) => [...new Set([...ids, ...toSchedule])]);
      }
      const { data } = await logisticsApi.createBatch({
        delivery_date: date,
        delivery_window: window,
        leg_ids: selectedIds,
        dispatcher_override_reason: selectedIds.length > dailyRiderCapacity ? overrideReason.trim() : undefined,
      });
      setSelectedBatchId(data.batch.id);
      setBuilding(false);
      setSelectedIds([]);
      setScheduledThisAttempt([]);
      setOverrideReason('');
      await workflowFeedback.toast('success', 'Draft saved');
      router.reload({ only: ['batches', 'pool', 'unscheduled'] });
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setSubmitting(false);
    }
  };

  return <AppLayoutERP><Head title="Delivery Batches" /><main className="space-y-6 p-4 sm:p-6">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><h1 className="text-2xl font-bold text-gray-950 dark:text-white">Delivery Batches</h1><p className="mt-1 text-sm text-gray-500">Build, organize, and offer efficient delivery routes.</p></div>
      <button type="button" onClick={startNewBatch} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700"><Plus size={18} />New Batch</button>
    </div>
    {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-red-700">{error}</p>}
    <div data-testid="batch-workspace" className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
      <AvailableDeliveriesPanel
        rows={filteredDeliveries} totalRows={allDeliveries.length} selectedIds={selectedIds}
        search={search} date={date} window={window} status={status}
        onSearchChange={setSearch} onDateChange={(value) => changeSlot(value, window)} onWindowChange={(value) => changeSlot(date, value)} onStatusChange={setStatus}
        onToggle={toggle} onSelectAll={selectAll} onClearFilters={() => { setSearch(''); setStatus('all'); setDate(''); setWindow('morning'); }}
      />
      {building || selectedBatch ? <BatchWorkspace
        batch={selectedBatch} selectedLegs={selectedLegs} date={date} window={window} dailyRiderCapacity={dailyRiderCapacity}
        overrideReason={overrideReason} submitting={submitting} busyLegId={busyLegId} onOverrideReasonChange={setOverrideReason}
        onMove={selectedBatch ? moveStops : moveLocal} onRemove={removeStop} onToggleUrgent={toggleUrgent} onSave={saveDraft} onReview={() => undefined}
      /> : <section className="grid min-h-72 place-items-center rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">Choose New Batch or open an existing batch to begin.</section>}
    </div>
    <section aria-label="Existing batches" className="space-y-3">
      <h2 className="text-lg font-bold text-gray-950 dark:text-white">Existing batches</h2>
      <DndProvider backend={HTML5Backend}><div className="grid gap-4 xl:grid-cols-2">{batches.map((batch) => <BatchCard key={batch.id} batch={batch} onOpen={() => { setBuilding(false); setSelectedBatchId(batch.id); }} />)}</div></DndProvider>
      {!batches.length && <p className="rounded-xl border border-dashed p-6 text-center text-sm text-gray-500">No batches yet.</p>}
    </section>
  </main></AppLayoutERP>;
}
