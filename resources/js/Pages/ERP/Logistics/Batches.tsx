import React, { useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import {
  logisticsModuleForSourceType,
  logisticsModuleLabel,
  logisticsSourceLabel,
  type DeliveryBatchPageProps,
  type DeliveryBatchStatus,
  type LogisticsModule,
  type TrackingShipmentLeg,
} from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';
import AvailableDeliveriesPanel from './components/AvailableDeliveriesPanel';
import BatchDetailsModal from './components/BatchDetailsModal';
import BatchHistoryModal from './components/BatchHistoryModal';
import BatchTable from './components/BatchTable';
import BatchWorkspace from './components/BatchWorkspace';
import OfferBatchModal from './components/OfferBatchModal';

const errorMessage = (error: unknown) => {
  const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;
  return Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? 'This batch changed. Refresh and try again.';
};

const sourceLabel = (leg: TrackingShipmentLeg) => logisticsSourceLabel(leg.shipment);

export default function Batches() {
  const {
    auth,
    batches,
    pool,
    unscheduled = [],
    riders,
    dailyRiderCapacity,
    filters = {},
    availableModules = [],
    showModuleFilter = false,
    today = '',
  } = usePage<DeliveryBatchPageProps & { auth?: any }>().props;
  const ownerMode = (auth as any)?.erpActor?.ownerMode === true;
  const batchesPath = ownerMode ? '/shop-owner/erp/logistics/batches' : '/erp/logistics/batches';
  const [building, setBuilding] = useState(false);
  const [selectedBatchId, setSelectedBatchId] = useState<number>();
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [date, setDate] = useState(filters.date ?? '');
  const [window, setWindow] = useState(filters.window && filters.window !== 'all' ? filters.window : 'morning');
  const [module, setModule] = useState<'all' | LogisticsModule>(
    filters.module ?? (availableModules.length === 1 ? availableModules[0] : 'all'),
  );
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [overrideReason, setOverrideReason] = useState('');
  const [scheduledThisAttempt, setScheduledThisAttempt] = useState<number[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [busyLegId, setBusyLegId] = useState<number>();
  const [error, setError] = useState('');
  const [reviewOpen, setReviewOpen] = useState(false);
  const [offerSubmitting, setOfferSubmitting] = useState(false);
  const [offerError, setOfferError] = useState('');
  const [offerOverrideRiderId, setOfferOverrideRiderId] = useState<number>();
  const [activeStatus, setActiveStatus] = useState<'all' | DeliveryBatchStatus>('all');
  const [detailsBatchId, setDetailsBatchId] = useState<number>();
  const [historyOpen, setHistoryOpen] = useState(false);
  const [deliveriesCollapsed, setDeliveriesCollapsed] = useState(false);
  const detailsTriggerRef = useRef<HTMLButtonElement | null>(null);
  const historyTriggerRef = useRef<HTMLButtonElement | null>(null);
  const [refreshing, setRefreshing] = useState(false);

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
      const products = leg.shipment?.order_summary?.items.flatMap((item) => [item.brand, item.model]) ?? [];
      return [sourceLabel(leg), destination?.name, destination?.phone, destination?.address, ...products]
        .some((value) => String(value ?? '').toLowerCase().includes(query));
    });
  }, [allDeliveries, date, search, status, unscheduledIds, window]);
  const selectedLegs = selectedIds.flatMap((id) => {
    const leg = allDeliveries.find((candidate) => candidate.id === id);
    return leg ? [leg] : [];
  });
  const selectedModule = selectedLegs.length
    ? logisticsModuleForSourceType(selectedLegs[0].shipment?.source_type)
    : null;
  const selectedBatch = batches.find((batch) => batch.id === selectedBatchId);
  const activeBatches = batches.filter((batch) => !['completed', 'cancelled'].includes(batch.status));
  const historyBatches = batches.filter((batch) => ['completed', 'cancelled'].includes(batch.status));
  const visibleActiveBatches = activeStatus === 'all' ? activeBatches : activeBatches.filter((batch) => batch.status === activeStatus);
  const detailsBatch = batches.find((batch) => batch.id === detailsBatchId);

  const startNewBatch = () => {
    setBuilding(true);
    setDeliveriesCollapsed(false);
    setSelectedBatchId(undefined);
    setSelectedIds([]);
    setScheduledThisAttempt([]);
    setOverrideReason('');
    setError('');
  };
  const openBatch = (batchId: number) => {
    setBuilding(false);
    setDeliveriesCollapsed(true);
    setSelectedBatchId(batchId);
    const workspace = document.getElementById('batch-workspace');

    if (typeof workspace?.scrollIntoView === 'function') {
      workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };
  const openDetails = (batchId: number, trigger: HTMLButtonElement) => {
    detailsTriggerRef.current = trigger;
    setDetailsBatchId(batchId);
  };
  const closeDetails = () => {
    setDetailsBatchId(undefined);
    setTimeout(() => detailsTriggerRef.current?.focus(), 0);
  };
  const openHistory = (trigger: HTMLButtonElement) => {
    historyTriggerRef.current = trigger;
    setHistoryOpen(true);
  };
  const closeHistory = () => {
    setHistoryOpen(false);
    setTimeout(() => historyTriggerRef.current?.focus(), 0);
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
    router.get(batchesPath, { module, date: nextDate || undefined, window: nextWindow }, {
      only: ['batches', 'pool', 'unscheduled', 'filters'],
      preserveScroll: true,
      preserveState: true,
    });
  };
  const changeModule = (nextModule: 'all' | LogisticsModule) => {
    setModule(nextModule);
    setSelectedIds([]);
    setScheduledThisAttempt([]);
    router.get(batchesPath, { module: nextModule, date: date || undefined, window }, {
      only: ['batches', 'pool', 'unscheduled', 'filters'],
      preserveScroll: true,
      preserveState: true,
    });
  };
  const clearFilters = () => {
    setSearch('');
    setStatus('all');
    changeSlot('', 'morning');
  };
  const toggle = (id: number, checked: boolean) => setSelectedIds((ids) => {
    if (!checked) return ids.filter((selectedId) => selectedId !== id);
    const leg = allDeliveries.find((candidate) => candidate.id === id);
    const legModule = logisticsModuleForSourceType(leg?.shipment?.source_type);
    if (selectedModule && legModule !== selectedModule) return ids;
    return [...ids.filter((selectedId) => selectedId !== id), id];
  });
  const selectAll = (checked: boolean) => {
    const targetModule = selectedModule ?? logisticsModuleForSourceType(filteredDeliveries[0]?.shipment?.source_type);
    const matchingIds = filteredDeliveries
      .filter((leg) => logisticsModuleForSourceType(leg.shipment?.source_type) === targetModule)
      .map((leg) => leg.id);
    setSelectedIds((ids) => checked
      ? [...ids.filter((id) => !matchingIds.includes(id)), ...matchingIds]
      : ids.filter((id) => !matchingIds.includes(id)));
  };
  const refreshBatchData = () => {
    setRefreshing(true);
    router.reload({ only: ['batches', 'pool', 'unscheduled', 'riders'], onFinish: () => setRefreshing(false) });
  };
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
  const openReview = (batchId: number) => {
    setBuilding(false);
    setSelectedBatchId(batchId);
    setOfferError('');
    setOfferOverrideRiderId(undefined);
    setReviewOpen(true);
  };
  const offerBatch = async (riderId: number, capacityOverrideReason?: string) => {
    if (!selectedBatch) return;
    const rider = riders.find((candidate) => candidate.id === riderId);
    if (!rider) return;
    const confirmation = await workflowFeedback.confirm({
      title: `Offer Batch #${selectedBatch.id} to ${rider.name}?`,
      text: `The rider will receive one notification for all ${selectedBatch.legs.length} ordered stops.`,
      confirmButtonText: 'Offer batch',
    });
    if (!confirmation.isConfirmed) return;
    try {
      setOfferSubmitting(true);
      setOfferError('');
      await logisticsApi.offerBatch(selectedBatch.id, riderId, capacityOverrideReason?.trim() || undefined);
      setOfferOverrideRiderId(undefined);
      setReviewOpen(false);
      await workflowFeedback.toast('success', 'Batch offered');
      refreshBatchData();
    } catch (caught) {
      const errors = (caught as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
      if (errors?.capacity_override_reason) setOfferOverrideRiderId(riderId);
      setOfferError(errorMessage(caught));
    } finally {
      setOfferSubmitting(false);
    }
  };
  const cancelBatch = async (batchId: number) => {
    const result = await workflowFeedback.confirm({
      title: `Cancel Batch #${batchId}?`,
      text: 'The stops will return to the available delivery pool.',
      input: 'textarea',
      inputLabel: 'Cancellation reason',
      inputPlaceholder: 'Explain why this batch is being cancelled',
      confirmButtonText: 'Cancel batch',
      inputValidator: (value) => String(value ?? '').trim() ? undefined : 'A cancellation reason is required.',
    });
    const reason = String(result.value ?? '').trim();
    if (!result.isConfirmed || !reason) return;
    try {
      setError('');
      await logisticsApi.cancelBatch(batchId, reason);
      if (selectedBatchId === batchId) setSelectedBatchId(undefined);
      await workflowFeedback.toast('success', 'Batch cancelled');
      refreshBatchData();
    } catch (caught) {
      await handleMutationError(caught);
    }
  };
  const restoreBatch = async (batchId: number) => {
    const result = await workflowFeedback.confirm({
      title: `Restore Batch #${batchId}?`,
      text: 'The saved stops will return to this batch as an editable draft.',
      confirmButtonText: 'Restore to draft',
    });
    if (!result.isConfirmed) return;
    try {
      setError('');
      await logisticsApi.restoreBatch(batchId);
      await workflowFeedback.toast('success', 'Batch restored to draft');
      refreshBatchData();
    } catch (caught) {
      await handleMutationError(caught);
    }
  };
  const moveLocal = (from: number, to: number) => {
    if (to < 0 || to >= selectedIds.length) return;
    setSelectedIds((ids) => {
      return reorder(ids, from, to);
    });
  };
  const saveDraft = async () => {
    if (selectedIds.length < 2) {
      setError('Select at least 2 deliveries.');
      return;
    }
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
      const message = errorMessage(caught);
      setError(message);
      await workflowFeedback.error(message, 'Draft not saved');
    } finally {
      setSubmitting(false);
    }
  };

  return <AppLayoutERP><Head title="Delivery Batches" /><main data-testid="batch-page-main" className="min-w-0 overflow-x-clip space-y-6 p-4 sm:p-6 xl:overflow-x-visible">
    <div className="flex min-w-0 flex-wrap items-start justify-between gap-3">
      <div className="min-w-0"><h1 className="text-2xl font-bold text-gray-950 dark:text-white">Delivery Batches</h1><p className="mt-1 text-sm text-gray-500">Build, organize, and offer efficient delivery routes.</p></div>
      <div className="flex min-w-0 flex-wrap items-center gap-2">
        {showModuleFilter && <select
          aria-label="Filter batches by module"
          value={module}
          onChange={(event) => changeModule(event.target.value as 'all' | LogisticsModule)}
          className="min-h-11 rounded-xl border border-gray-300 bg-white px-3 text-sm font-semibold"
        >
          <option value="all">All modules</option>
          {availableModules.map((available) => <option key={available} value={available}>{logisticsModuleLabel(available)}</option>)}
        </select>}
        <button type="button" onClick={startNewBatch} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700"><Plus size={18} />New Batch</button>
      </div>
    </div>
    {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-red-700">{error}</p>}
    <div id="batch-workspace" data-testid="batch-workspace" className={`grid min-w-0 scroll-mt-28 gap-5 ${deliveriesCollapsed ? 'xl:grid-cols-1' : 'xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]'}`}>
      <AvailableDeliveriesPanel
        rows={filteredDeliveries} totalRows={allDeliveries.length} selectedIds={selectedIds} loading={refreshing}
        selectedModule={selectedModule}
        collapsed={deliveriesCollapsed} onCollapse={() => setDeliveriesCollapsed(true)} onExpand={() => setDeliveriesCollapsed(false)}
        search={search} date={date} today={today} window={window} status={status}
        onSearchChange={setSearch} onDateChange={(value) => changeSlot(value, window)} onWindowChange={(value) => changeSlot(date, value)} onStatusChange={setStatus}
        onToggle={toggle} onSelectAll={selectAll} onClearFilters={clearFilters}
      />
      {building || selectedBatch ? <BatchWorkspace
        batch={selectedBatch} selectedLegs={selectedLegs} date={date} window={window} dailyRiderCapacity={dailyRiderCapacity}
        overrideReason={overrideReason} submitting={submitting} busyLegId={busyLegId} onOverrideReasonChange={setOverrideReason}
        onMove={selectedBatch ? moveStops : moveLocal} onRemove={removeStop}
        onSave={saveDraft} onReview={() => selectedBatch && openReview(selectedBatch.id)}
      /> : <section className="grid min-h-72 place-items-center rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">Choose New Batch or open an existing batch to begin.</section>}
    </div>
    <section aria-label="Active batches" className="min-w-0 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-bold text-gray-950 dark:text-white">Active batches</h2><div className="flex flex-wrap gap-1">{(['all', 'draft', 'offered', 'accepted', 'in_progress'] as const).map((tab) => {
        const count = tab === 'all' ? activeBatches.length : activeBatches.filter((batch) => batch.status === tab).length;
        const tabLabel = tab === 'all' ? 'All' : tab.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
        return <button key={tab} type="button" aria-pressed={activeStatus === tab} onClick={() => setActiveStatus(tab)} className={`min-h-10 rounded-lg px-3 text-sm font-semibold ${activeStatus === tab ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'}`}>{tabLabel} ({count})</button>;
      })}<button type="button" onClick={(event) => openHistory(event.currentTarget)} className="min-h-10 rounded-lg border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">History ({historyBatches.length})</button></div></div>
      <BatchTable batches={visibleActiveBatches} onOpen={openBatch} onDetails={openDetails} onReview={openReview} onCancel={cancelBatch} />
      {!visibleActiveBatches.length && <p className="rounded-xl border border-dashed p-6 text-center text-sm text-gray-500">No active batches in this status.</p>}
    </section>
    <BatchHistoryModal batches={historyBatches} isOpen={historyOpen} onClose={closeHistory} onOpen={(batchId) => { closeHistory(); openBatch(batchId); }} onDetails={openDetails} onRestore={restoreBatch} />
    <BatchDetailsModal batch={detailsBatch} isOpen={Boolean(detailsBatch)} onClose={closeDetails} />
    <OfferBatchModal isOpen={reviewOpen} batch={selectedBatch} batches={batches} riders={riders} dailyRiderCapacity={dailyRiderCapacity} forceCapacityOverrideForRiderId={offerOverrideRiderId} submitting={offerSubmitting} error={offerError} onClose={() => { setReviewOpen(false); setOfferOverrideRiderId(undefined); }} onOffer={offerBatch} />
  </main></AppLayoutERP>;
}
