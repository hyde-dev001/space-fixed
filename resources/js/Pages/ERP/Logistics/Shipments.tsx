import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays, ExternalLink, MapPin, Search, UserRound, X } from 'lucide-react';
import Swal from 'sweetalert2';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Modal } from '@/components/ui/modal';
import ArrivalSummary from './components/ArrivalSummary';
import RetailOrderSummary from './components/RetailOrderSummary';
import { riderResolutionInstruction } from './riderDeliveryPresentation';
import { logisticsApi } from '@/services/logisticsApi';
import { erpUrl } from '@/utils/erpCapabilities';
import type { ErpCapabilities } from '@/types/erp';
import {
  logisticsModuleForSourceType,
  logisticsModuleLabel,
  logisticsSourceLabel,
  type LogisticsModule,
  type LogisticsIncident,
  type LogisticsShipment,
  type PaginatedResponse,
  type TrackingShipmentLeg,
} from '@/types/logistics';

type ShipmentFilters = {
  status: string;
  purpose?: string;
  window: string;
  module?: 'all' | LogisticsModule;
  search?: string;
};

const statusOptions = [
  ['all', 'All'],
  ['incomplete', 'Incomplete'],
  ['requested', 'Requested'],
  ['active', 'Active'],
  ['awaiting_proof_approval', 'Awaiting Proof Approval'],
  ['failed_attempts', 'Failed attempts'],
  ['failed_pickups', 'Failed pickups'],
  ['completed', 'Completed'],
  ['cancelled', 'Cancelled'],
];

const riderStatusOptions = [
  ['all', 'All'],
  ['assigned', 'Assigned'],
  ['picked_up', 'Picked Up'],
  ['in_transit', 'In Transit'],
  ['delivery_attempted', 'Delivery Attempted'],
  ['awaiting_proof_approval', 'Awaiting Proof Approval'],
  ['delivered', 'Delivered'],
  ['cancelled', 'Cancelled'],
];

const photoIssueReasons = new Set([
  'recipient_unavailable',
  'wrong_or_incomplete_address',
  'recipient_refused',
  'item_damaged',
]);

const noteIssueReasons = new Set([
  'unsafe_location',
  'vehicle_or_delivery_problem',
  'other',
]);

const purposeOptions: Array<[string, string, 'all' | LogisticsModule]> = [
  ['all', 'All Types', 'all'],
  ['retail_delivery', 'Retail Delivery', 'retail'],
  ['refund_return', 'Refund Return', 'retail'],
  ['repair_pickup', 'Repair Pickup', 'repair'],
  ['repair_return', 'Repair Return', 'repair'],
];

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function statusClass(status: string) {
  if (status === 'completed') return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
  if (status === 'cancelled') return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
  if (status === 'active') return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
  return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
}

function contact(leg?: TrackingShipmentLeg) {
  const snapshot = (leg?.leg_type === 'inbound' ? leg.origin_snapshot : leg?.destination_snapshot) ?? {};
  const value = (key: string) => typeof snapshot[key] === 'string' ? snapshot[key] as string : '';
  return { name: value('name'), phone: value('phone'), address: value('address'), instructions: value('delivery_instructions') };
}

const formatDate = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`))
  : 'Not scheduled';

const formatDateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : null;

const shortAddress = (value: string, max = 72) =>
  value.length > max ? `${value.slice(0, max - 1).trimEnd()}…` : value;

const toast = (icon: 'success' | 'error' | 'warning', title: string) => Swal.fire({
  toast: true,
  position: 'top-end',
  icon,
  title,
  showConfirmButton: false,
  timer: 2500,
  timerProgressBar: true,
});

export default function Shipments({ children }: React.PropsWithChildren) {
  const { shipments, filters, assignableRiders, canAssign: serverCanAssign, canUpdateStatus: serverCanUpdateStatus, canRecordProof: serverCanRecordProof, canApproveProof: serverCanApproveProof, riderMode, maxDeliveryAttempts = 2, availableModules = [], showModuleFilter = false, today, auth, erpCapabilities } = usePage<{
    shipments: PaginatedResponse<LogisticsShipment>;
    filters: ShipmentFilters;
    assignableRiders: Array<{ id: number; name: string; phone?: string | null }>;
    canAssign: boolean;
    canUpdateStatus: boolean;
    canRecordProof: boolean;
    canApproveProof: boolean;
    riderMode: boolean;
    maxDeliveryAttempts?: number;
    availableModules?: LogisticsModule[];
    showModuleFilter?: boolean;
    today: string;
    auth?: { erpActor?: { ownerMode?: boolean } };
    erpCapabilities?: ErpCapabilities;
  }>().props;
  const ownerMode = auth?.erpActor?.ownerMode === true;
  const canAssign = !ownerMode && serverCanAssign;
  const canUpdateStatus = !ownerMode && serverCanUpdateStatus;
  const canRecordProof = !ownerMode && serverCanRecordProof;
  const canApproveProof = !ownerMode && serverCanApproveProof;
  const [selectedShipmentId, setSelectedShipmentId] = useState<number | null>(null);
  const [selectedProofUrl, setSelectedProofUrl] = useState<string | null>(null);
  const returnFocusRef = useRef<HTMLButtonElement | null>(null);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const proofTriggerRef = useRef<HTMLButtonElement | null>(null);
  const proofCloseButtonRef = useRef<HTMLButtonElement | null>(null);
  const [selectedRiders, setSelectedRiders] = useState<Record<number, string>>({});
  const [deliverySchedules, setDeliverySchedules] = useState<Record<number, { date: string; window: string }>>({});
  const [assigningLegId, setAssigningLegId] = useState<number | null>(null);
  const [assignmentError, setAssignmentError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [proofFiles, setProofFiles] = useState<Record<number, File | null>>({});
  const [issueProofFiles, setIssueProofFiles] = useState<Record<number, File | null>>({});
  const [issueForms, setIssueForms] = useState<Record<number, { reason_code: string; notes: string }>>({});
  const [incidentNotes, setIncidentNotes] = useState<Record<number, string>>({});
  const [incidentResolutions, setIncidentResolutions] = useState<Record<number, string>>({});
  const [incidentEvidenceFiles, setIncidentEvidenceFiles] = useState<Record<number, File | null>>({});
  const [deliveryOutcomes, setDeliveryOutcomes] = useState<Record<number, 'proof' | 'issue'>>({});
  const [search, setSearch] = useState(filters.search ?? '');

  const openShipment = (shipmentId: number, trigger: HTMLButtonElement) => {
    returnFocusRef.current = trigger;
    setSelectedProofUrl(null);
    setSelectedShipmentId(shipmentId);
  };

  const closeShipment = () => {
    const trigger = returnFocusRef.current;
    setSelectedProofUrl(null);
    setSelectedShipmentId(null);
    trigger?.focus();
  };

  const openProof = (url: string, trigger: HTMLButtonElement) => {
    proofTriggerRef.current = trigger;
    setSelectedProofUrl(url);
  };

  const closeProof = () => {
    const trigger = proofTriggerRef.current;
    setSelectedProofUrl(null);
    window.requestAnimationFrame(() => trigger?.focus());
  };

  useEffect(() => {
    if (selectedShipmentId === null) return;

    const frame = window.requestAnimationFrame(() => closeButtonRef.current?.focus());
    return () => window.cancelAnimationFrame(frame);
  }, [selectedShipmentId]);

  useEffect(() => {
    if (selectedProofUrl === null) return;

    const frame = window.requestAnimationFrame(() => proofCloseButtonRef.current?.focus());
    return () => window.cancelAnimationFrame(frame);
  }, [selectedProofUrl]);

  const updateFilter = (key: keyof ShipmentFilters, value: string) => {
    const next = { ...filters, [key]: value, page: 1 };
    if (key === 'module') {
      const purpose = purposeOptions.find(([option]) => option === filters.purpose);
      if (purpose && purpose[2] !== 'all' && purpose[2] !== value) next.purpose = 'all';
    }
    const shipmentsUrl = erpUrl(erpCapabilities, 'GET:erp.logistics.shipments')
      ?? (ownerMode ? null : '/erp/logistics/shipments');
    const deliveriesUrl = erpUrl(erpCapabilities, 'GET:erp.logistics.deliveries')
      ?? (ownerMode ? null : '/erp/logistics/deliveries');
    const targetUrl = riderMode ? deliveriesUrl : shipmentsUrl;

    if (!targetUrl) return;

    router.get(targetUrl, next, {
      preserveScroll: true,
      preserveState: true,
    });
  };
  const selectedModule = filters.module ?? (availableModules.length === 1 ? availableModules[0] : 'all');
  const visiblePurposeOptions = purposeOptions.filter(([, , module]) => selectedModule === 'all' || module === 'all' || module === selectedModule);
  const hasActiveFilters = Boolean(filters.search?.trim())
    || filters.status !== 'all'
    || (filters.purpose ?? 'all') !== 'all'
    || (filters.window ?? 'all') !== 'all'
    || (showModuleFilter && selectedModule !== 'all');

  const act = async (
    target: string | (() => Promise<unknown>),
    body?: FormData | Record<string, string>,
    issue = false,
  ) => {
    if (ownerMode) return false;

    setActionError(null);
    try {
      await (typeof target === 'string'
        ? axios.post(target, body, body instanceof FormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : undefined)
        : target());
      toast(issue ? 'warning' : 'success', issue ? 'Delivery issue reported.' : 'Delivery updated.');
      router.reload({ only: issue && riderMode ? ['shipments', 'batches'] : ['shipments'] });
      return true;
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const message = error.response?.data?.message ?? (errors ? Object.values(errors).flat().join(' ') : 'Unable to update this delivery.');
      setActionError(message);
      toast('error', message);
      return false;
    }
  };

  const confirmAct = async (url: string, title: string, text: string) => {
    const result = await Swal.fire({ title, text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Confirm', cancelButtonText: 'Back' });
    if (result.isConfirmed) await act(url);
  };

  const rejectProof = async (proofId: number) => {
    const result = await Swal.fire({
      title: 'Reject delivery proof?',
      text: 'The rider will be asked to submit a replacement proof.',
      icon: 'warning',
      input: 'textarea',
      inputLabel: 'Rejection reason',
      inputPlaceholder: 'Explain what is missing or unclear...',
      inputValidator: (value) => value.trim().length < 3 ? 'Enter a clear rejection reason.' : undefined,
      showCancelButton: true,
      confirmButtonText: 'Reject proof',
      cancelButtonText: 'Back',
    });
    if (result.isConfirmed) await act(`/api/logistics/proofs/${proofId}/reject`, { rejection_reason: result.value.trim() });
  };

  const resolveFailedPickup = async (legId: number, action: 'retry' | 'cancel') => {
    const result = await Swal.fire({
      title: action === 'retry' ? 'Reschedule pickup?' : 'Cancel pickup?',
      input: 'textarea',
      inputLabel: action === 'retry' ? 'Dispatcher note' : 'Cancellation reason',
      inputValidator: (value) => value.trim() ? undefined : 'Enter a reason.',
      showCancelButton: true,
      confirmButtonText: action === 'retry' ? 'Reschedule Pickup' : 'Cancel Pickup',
    });
    if (!result.isConfirmed) return;
    const reason = String(result.value ?? '').trim();
    if (!reason) return;

    await act(
      `/api/logistics/legs/${legId}/${action === 'retry' ? 'resolve/retry' : 'cancel'}`,
      { reason },
    );
  };

  const resolveFailedDelivery = async (legId: number, action: 'retry' | 'return') => {
    const result = await Swal.fire({
      title: action === 'retry' ? 'Retry delivery?' : 'Return to shop?',
      input: 'textarea',
      inputLabel: 'Resolution reason',
      inputPlaceholder: 'Explain why this recovery path was selected...',
      inputValidator: (value) => value.trim() ? undefined : 'Enter a reason.',
      showCancelButton: true,
      confirmButtonText: action === 'retry' ? 'Retry delivery' : 'Return to shop',
      cancelButtonText: 'Back',
    });
    if (!result.isConfirmed) return;
    const reason = String(result.value ?? '').trim();
    if (!reason) return;

    await act(() => action === 'retry'
      ? logisticsApi.retryDelivery(legId, reason)
      : logisticsApi.returnDelivery(legId, reason));
  };

  const submitProof = (legId: number) => {
    const file = proofFiles[legId];
    if (!file) return setActionError('Select a proof image first.');
    const form = new FormData();
    form.append('handoff_type', 'delivery');
    form.append('proof_type', 'photo');
    form.append('proof_file', file);
    void act(`/api/logistics/legs/${legId}/proof`, form);
  };

  const submitReturnHandoff = async (legId: number) => {
    const file = proofFiles[legId];
    if (!file) return setActionError('Select a return handoff photo first.');
    const form = new FormData();
    form.append('handoff_type', 'receive');
    form.append('proof_type', 'photo');
    form.append('proof_file', file);

    setActionError(null);
    try {
      const response = await axios.post(`/api/logistics/legs/${legId}/proof`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
      await axios.post(`/api/logistics/legs/${legId}/return-proofs/${response.data.proof.id}/handoff`);
      toast('success', 'Return handed to the shop for inspection.');
      router.reload({ only: ['shipments'] });
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const message = error.response?.data?.message ?? (errors ? Object.values(errors).flat().join(' ') : 'Unable to confirm the return handoff.');
      setActionError(message);
      toast('error', message);
    }
  };

  const assignRider = async (legId: number) => {
    const riderProfileId = Number(selectedRiders[legId]);
    if (!riderProfileId) return;

    const result = await Swal.fire({ title: 'Assign rider?', text: 'The rider will be assigned to this delivery.', icon: 'question', showCancelButton: true, confirmButtonText: 'Assign', cancelButtonText: 'Back' });
    if (!result.isConfirmed) return;

    setAssigningLegId(legId);
    setAssignmentError(null);

    try {
      await axios.post(`/api/logistics/legs/${legId}/assign`, {
        assignment_type: 'internal_rider',
        rider_profile_id: riderProfileId,
      });
      toast('success', 'Rider assigned.');
      router.reload({ only: ['shipments', 'assignableRiders'] });
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const message = error.response?.data?.message ?? (errors ? Object.values(errors).flat().join(' ') : 'Unable to assign this rider. Refresh the page and try again.');
      setAssignmentError(message);
      toast('error', message);
    } finally {
      setAssigningLegId(null);
    }
  };

  const scheduleLeg = async (legId: number, assignAfter: boolean) => {
    const schedule = deliverySchedules[legId];
    const riderProfileId = Number(selectedRiders[legId]);
    if (!schedule?.date || !schedule.window || (assignAfter && !riderProfileId)) return;

    setAssigningLegId(legId);
    setAssignmentError(null);
    let scheduled = false;
    let awaitingReload = false;
    try {
      await axios.post('/api/logistics/legs/schedule', {
        delivery_date: schedule.date,
        delivery_window: schedule.window,
        leg_ids: [legId],
      });
      scheduled = true;
      if (assignAfter) {
        await axios.post(`/api/logistics/legs/${legId}/assign`, {
          assignment_type: 'internal_rider',
          rider_profile_id: riderProfileId,
        });
      }
      toast('success', assignAfter ? 'Delivery scheduled and rider assigned.' : 'Delivery scheduled.');
      awaitingReload = true;
      router.reload({ only: assignAfter ? ['shipments', 'assignableRiders'] : ['shipments'], onFinish: () => setAssigningLegId(null) });
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const message = error.response?.data?.message ?? (errors ? Object.values(errors).flat().join(' ') : 'Unable to schedule this delivery.');
      setAssignmentError(message);
      toast('error', message);
      if (scheduled && assignAfter) {
        awaitingReload = true;
        router.reload({ only: ['shipments', 'assignableRiders'], onFinish: () => setAssigningLegId(null) });
      }
    } finally {
      if (!awaitingReload) setAssigningLegId(null);
    }
  };

  const reportIssue = (legId: number, assignmentId?: number) => {
    const form = issueForms[legId] ?? { reason_code: '', notes: '' };
    if (!form.reason_code) return setActionError('Choose an issue reason first.');
    const file = issueProofFiles[legId];
    if (photoIssueReasons.has(form.reason_code) && !file) {
      return setActionError('Upload a photo showing the delivery attempt.');
    }
    if (noteIssueReasons.has(form.reason_code) && !form.notes.trim()) {
      return setActionError('Add a note explaining the delivery issue.');
    }
    const data = new FormData();
    if (!assignmentId) return setActionError('The active rider assignment could not be verified. Refresh and try again.');
    data.append('delivery_assignment_id', String(assignmentId));
    data.append('reason_code', form.reason_code);
    if (form.notes.trim()) data.append('notes', form.notes.trim());
    if (file) data.append('proof_file', file);
    void act(`/api/logistics/legs/${legId}/report-issue`, data, true);
  };

  const resolveIncident = (incident: LogisticsIncident) => {
    const resolution = incidentResolutions[incident.id] ?? '';
    const note = incidentNotes[incident.id]?.trim() ?? '';
    const evidence = incidentEvidenceFiles[incident.id];
    if (!resolution) return setActionError('Choose an incident resolution first.');
    if (!note) return setActionError('Add a resolution note.');
    if (resolution === 'loss_confirmed' && !evidence) return setActionError('Upload investigation evidence for confirmed loss.');

    const form = new FormData();
    form.append('resolution', resolution);
    form.append('note', note);
    if (evidence) form.append('evidence_files[]', evidence);
    void act(() => logisticsApi.resolveIncident(incident.id, form));
  };

  const trapDialogFocus = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'Tab') return;

    const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>(
      'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ));
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  const chooseDeliveryOutcome = (legId: number, outcome: 'proof' | 'issue') => {
    setDeliveryOutcomes((current) => ({ ...current, [legId]: outcome }));

    if (outcome === 'proof') {
      setIssueProofFiles((current) => ({ ...current, [legId]: null }));
      setIssueForms((current) => ({ ...current, [legId]: { reason_code: '', notes: '' } }));
    } else {
      setProofFiles((current) => ({ ...current, [legId]: null }));
    }
  };

  return (
    <AppLayoutERP>
      <Head title={riderMode ? "My Deliveries" : "ERP Logistics Shipments"} />
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-950 dark:text-white">{riderMode ? 'My Deliveries' : 'Shipments'}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">{riderMode ? 'Process your assigned deliveries.' : 'Assign riders and approve delivery proof.'}</p>
        </div>
        {children}

        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <form role="search" onSubmit={(event) => {
            event.preventDefault();
            updateFilter('search', search.trim());
          }} className="flex w-full max-w-md gap-2">
            <label className="relative min-w-0 flex-1">
              <span className="sr-only">Search shipments</span>
              <Search className="absolute left-3 top-2.5 text-gray-400" size={18} />
              <input
                aria-label="Search shipments"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Shipment, order, customer, or product"
                className="w-full rounded-lg border border-gray-300 py-2 pl-10 pr-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              />
            </label>
            <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Search</button>
          </form>
          <div className="flex flex-wrap items-center gap-3">
            <select
              value={filters.status}
              onChange={(event) => updateFilter('status', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by status"
            >
              {(riderMode ? riderStatusOptions : statusOptions).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
            {!riderMode && <select
              value={filters.purpose ?? 'all'}
              onChange={(event) => updateFilter('purpose', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by type"
            >
              {visiblePurposeOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>}
            {!riderMode && showModuleFilter && <select
              value={selectedModule}
              onChange={(event) => updateFilter('module', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by module"
            >
              <option value="all">All modules</option>
              {availableModules.map((module) => <option key={module} value={module}>{logisticsModuleLabel(module)}</option>)}
            </select>}
            {!riderMode && <select
              value={filters.window ?? 'all'}
              onChange={(event) => updateFilter('window', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by delivery window"
            >
              <option value="all">All windows</option>
              <option value="morning">Morning</option>
              <option value="afternoon">Afternoon</option>
            </select>}
            {riderMode && <select
              value={filters.window}
              onChange={(event) => updateFilter('window', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter deliveries by time"
            >
              <option value="all">All time</option>
              <option value="today">Today</option>
              <option value="week">This week</option>
            </select>}
          </div>
        </div>

        <div className="space-y-3">
          {shipments.data.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800">
              {hasActiveFilters ? 'No shipments match your filters.' : 'No shipments yet.'}
            </div>
          ) : shipments.data.map((shipment) => {
            const legs = shipment.legs ?? [];
            const firstLeg = legs[0];
            const recipient = contact(firstLeg);
            const activeAssignments = legs
              .flatMap((leg) => leg.assignments ?? [])
              .filter((assignment) => ['assigned', 'accepted'].includes(assignment.status));
            const rider = activeAssignments[0]?.rider_profile?.name ?? 'Unassigned';
            const urgent = legs.some((leg) => Boolean(leg.urgent_at));
            const pickupAttempt = legs.some((leg) => shipment.purpose === 'repair_pickup'
              && leg.attempts?.[0]?.attempt_type === 'pickup');
            const failedPickup = legs.some((leg) => shipment.purpose === 'repair_pickup'
              && leg.status === 'needs_resolution'
              && leg.resolution_type === 'pickup_failed'
              && leg.attempts?.[0]?.attempt_type === 'pickup');
            const pickupRescheduled = pickupAttempt && !failedPickup;
            const failed = legs.some((leg) => leg.attempts?.[0]?.status === 'failed');
            const awaitingProof = legs.some((leg) => leg.status === 'awaiting_proof_approval');
            const overdue = legs.some((leg) => Boolean(leg.scheduled_delivery_date)
              && leg.scheduled_delivery_date!.slice(0, 10) < today
              && !['delivered', 'cancelled'].includes(leg.status));
            const selected = selectedShipmentId === shipment.id;

            return <article key={shipment.id} className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
              <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] lg:items-center">
                <div className="min-w-0 space-y-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <strong className="text-gray-950 dark:text-white">Shipment #{shipment.id}</strong>
                    <span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusClass(shipment.status)}`}>{label(shipment.status)}</span>
                    <span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">{label(shipment.purpose)}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                      {logisticsModuleLabel(logisticsModuleForSourceType(shipment.source_type))}
                    </span>
                    <span className="text-sm font-medium text-gray-600 dark:text-gray-300">
                      {shipment.source_type === 'order' && shipment.order_summary?.order_number
                        ? `Order ${shipment.order_summary.order_number}`
                        : logisticsSourceLabel(shipment)}
                    </span>
                  </div>
                  {shipment.source_summary && <p className="text-sm text-gray-600 dark:text-gray-300">{shipment.source_summary.customer_name} · {shipment.source_summary.shoe_summary}</p>}
                  <RetailOrderSummary summary={shipment.order_summary} />
                </div>
                <div className="grid gap-2 text-sm text-gray-600 dark:text-gray-300">
                  <span className="inline-flex items-center gap-2"><UserRound size={16} />{recipient.name || 'Customer not provided'}</span>
                  <span title={recipient.address || undefined} className="inline-flex items-start gap-2">
                    <MapPin className="mt-0.5 shrink-0" size={16} />
                    {recipient.address ? shortAddress(recipient.address) : 'Address not provided'}
                  </span>
                  <span className="inline-flex items-center gap-2">
                    <CalendarDays size={16} />
                    {formatDate(firstLeg?.scheduled_delivery_date)}{firstLeg?.delivery_window ? ` · ${label(firstLeg.delivery_window)}` : ''}
                  </span>
                  <span>Rider: {rider}</span>
                  <div className="flex flex-wrap gap-2">
                    {urgent && <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-700">Urgent</span>}
                    {overdue && <span className="rounded-full bg-orange-100 px-2 py-1 text-xs font-semibold text-orange-700">Overdue</span>}
                    {failedPickup
                      ? <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Failed pickup · Needs action</span>
                      : pickupRescheduled
                        ? <span className="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-800">Pickup rescheduled</span>
                      : failed && <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Failed attempt</span>}
                    {awaitingProof && <span className="rounded-full bg-purple-100 px-2 py-1 text-xs font-semibold text-purple-700">Awaiting proof</span>}
                  </div>
                </div>
                <button
                  type="button"
                  aria-label={shipments.data.length > 1 ? `Open delivery for Shipment ${shipment.id}` : undefined}
                  aria-haspopup="dialog"
                  onClick={(event) => openShipment(shipment.id, event.currentTarget)}
                  className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300 dark:hover:bg-blue-950/50"
                >
                  Open delivery
                  <ExternalLink aria-hidden="true" size={16} />
                </button>
              </div>
              <Modal
                isOpen={selected}
                onClose={selectedProofUrl ? closeProof : closeShipment}
                size="6xl"
                showCloseButton={false}
                className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden"
              >
                <>
                <div
                  role="dialog"
                  aria-modal="true"
                  aria-hidden={selectedProofUrl !== null}
                  aria-labelledby={`shipment-${shipment.id}-details-title`}
                  onKeyDown={trapDialogFocus}
                  className={selectedProofUrl ? 'hidden' : 'flex max-h-[min(92dvh,60rem)] flex-col overflow-hidden'}
                >
                  <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
                    <div className="min-w-0">
                      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">Shipment #{shipment.id}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-2">
                        <h2 id={`shipment-${shipment.id}-details-title`} aria-label={`Shipment ${shipment.id} delivery details`} className="text-xl font-bold tracking-tight text-gray-950 dark:text-white">Delivery details</h2>
                        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusClass(shipment.status)}`}>{label(shipment.status)}</span>
                      </div>
                      <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {shipment.source_type === 'order' && shipment.order_summary?.order_number
                          ? `Order ${shipment.order_summary.order_number}`
                          : logisticsSourceLabel(shipment)}
                      </p>
                    </div>
                    <button
                      ref={closeButtonRef}
                      type="button"
                      aria-label={`Close delivery details for Shipment ${shipment.id}`}
                      onClick={closeShipment}
                      className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                      <X aria-hidden="true" size={20} />
                    </button>
                  </header>
                  <div className="min-h-0 flex-1 overflow-y-auto border-t border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40 sm:p-6">
                  {shipment.source_type === 'order' && <RetailOrderSummary summary={shipment.order_summary} expanded />}
                  <div className="space-y-3">
                    {(shipment.legs ?? []).map((leg) => {
                            const recipient = contact(leg);
                            const activeAssignment = leg.assignments?.find((assignment) => ['assigned', 'accepted'].includes(assignment.status));
                            const latestAttempt = leg.attempts?.[0];
                            const isPickupAttempt = shipment.purpose === 'repair_pickup'
                              && latestAttempt?.attempt_type === 'pickup';
                            const isFailedPickup = isPickupAttempt
                              && leg.status === 'needs_resolution'
                              && leg.resolution_type === 'pickup_failed';
                            const failedAttemptCount = leg.failed_attempt_count ?? latestAttempt?.attempt_number ?? 0;
                            const failedPickupCount = leg.failed_pickup_count ?? latestAttempt?.attempt_number ?? 0;
                            const attemptsMaxed = !isPickupAttempt && failedAttemptCount >= maxDeliveryAttempts;
                            const canAssignLeg = leg.status === 'pending' && !activeAssignment && !attemptsMaxed;
                            const isReturnToShop = leg.leg_type === 'return_to_shop';
                            const hasReturnLeg = (shipment.legs ?? []).some((candidate) => candidate.return_for_leg_id === leg.id);
                            const canResolveDelivery = canAssign
                              && !riderMode
                              && !isReturnToShop
                              && leg.status === 'needs_resolution'
                              && !leg.resolution_type
                              && !hasReturnLeg
                              && Boolean(activeAssignment);
                            const returnProof = leg.proofs?.find((proof) => proof.handoff_type === 'receive');
                            const canReportIssue = !ownerMode && riderMode && !isReturnToShop && ['in_transit', 'delivery_attempted'].includes(leg.status);
                            const canScheduleLeg = canAssign && !riderMode && !leg.scheduled_delivery_date && leg.delivery_batch_id == null && ['pending', 'assigned'].includes(leg.status);
                            const schedule = deliverySchedules[leg.id] ?? { date: '', window: 'morning' };
                            const issueForm = issueForms[leg.id] ?? { reason_code: '', notes: '' };
                            const requiresIssuePhoto = photoIssueReasons.has(issueForm.reason_code);
                            const requiresIssueNotes = noteIssueReasons.has(issueForm.reason_code);
                            const resolutionInstruction = riderResolutionInstruction(leg);
                            const canSubmitProof = canRecordProof && !isReturnToShop && leg.status === 'in_transit';
                            const canSubmitReturnHandoff = riderMode && canRecordProof && isReturnToShop && leg.status === 'in_transit' && !returnProof;
                            const showOutcomeChoice = canSubmitProof && canReportIssue;
                            const deliveryOutcome = deliveryOutcomes[leg.id];
                            const incidents = leg.incidents ?? [];

                            return (
                              <div key={leg.id} className="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)] dark:border-gray-700 dark:bg-gray-800">
                                <div>
                                  <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Delivery details · {label(leg.leg_type)} leg</h3>
                                  <p className="text-xs text-gray-500 dark:text-gray-400">{label(leg.status)}</p>
                                  <div className="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                    <p><strong>Receiver:</strong> {recipient.name || 'Not provided'}</p>
                                    <p><strong>Phone:</strong> {recipient.phone || 'Not provided'}</p>
                                    <p><strong>Address:</strong> {recipient.address || 'Not provided'}</p>
                                    {recipient.instructions && <p><strong>Instructions:</strong> {recipient.instructions}</p>}
                                    <p><strong>Schedule:</strong> {formatDate(leg.scheduled_delivery_date)}{leg.delivery_window ? ` · ${label(leg.delivery_window)}` : ''}</p>
                                    {leg.stop_sequence && <p><strong>Stop:</strong> {leg.stop_sequence}</p>}
                                  </div>
                                  {!riderMode && <ArrivalSummary arrivals={leg.arrivals} />}
                                  {resolutionInstruction && (
                                    <p
                                      role="status"
                                      className="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
                                    >
                                      <span aria-hidden="true">!</span> {resolutionInstruction}
                                    </p>
                                  )}
                                  {incidents.map((incident) => (
                                    <div key={incident.id} className="mt-2 space-y-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-950/30">
                                      <p className="font-semibold text-red-900 dark:text-red-100">
                                        Incident #{incident.id} · {label(incident.type)} · {label(incident.status)}
                                      </p>
                                      {incident.notes && <p className="text-red-800 dark:text-red-200">{incident.notes}</p>}
                                      {incident.evidence_urls?.map((url, index) => (
                                        <a key={url} href={url} target="_blank" rel="noreferrer" className="mr-3 inline-block font-semibold text-blue-700 hover:underline dark:text-blue-300">
                                          View evidence {index + 1}
                                        </a>
                                      ))}
                                      {incident.status === 'resolved' ? (
                                        <p className="font-semibold text-emerald-800 dark:text-emerald-300">Resolution: {label(incident.resolution ?? 'resolved')}</p>
                                      ) : !riderMode && canAssign ? (
                                        <div className="space-y-2">
                                          <select
                                            aria-label={`Resolution for incident ${incident.id}`}
                                            value={incidentResolutions[incident.id] ?? ''}
                                            onChange={(event) => setIncidentResolutions({ ...incidentResolutions, [incident.id]: event.target.value })}
                                            className="block w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm dark:bg-gray-800 dark:text-white"
                                          >
                                            <option value="">Choose resolution</option>
                                            {incident.type === 'lost' && <option value="loss_confirmed">Confirm loss</option>}
                                            <option value="dismissed">Dismiss / issue addressed</option>
                                            {leg.status === 'needs_resolution' && <>
                                              <option value="retry">Authorize retry</option>
                                              <option value="return_required">Require return to shop</option>
                                            </>}
                                          </select>
                                          <textarea
                                            aria-label={`Resolution note for incident ${incident.id}`}
                                            value={incidentNotes[incident.id] ?? ''}
                                            onChange={(event) => setIncidentNotes({ ...incidentNotes, [incident.id]: event.target.value })}
                                            placeholder="Explain the decision"
                                            rows={2}
                                            className="block w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm dark:bg-gray-800 dark:text-white"
                                          />
                                          {(incidentResolutions[incident.id] ?? '') === 'loss_confirmed' && (
                                            <input
                                              type="file"
                                              accept="image/jpeg,image/png,image/webp"
                                              aria-label={`Investigation evidence for incident ${incident.id}`}
                                              onChange={(event) => setIncidentEvidenceFiles({ ...incidentEvidenceFiles, [incident.id]: event.target.files?.[0] ?? null })}
                                              className="block w-full text-sm"
                                            />
                                          )}
                                          <button
                                            type="button"
                                            disabled={!incidentResolutions[incident.id] || !(incidentNotes[incident.id] ?? '').trim() || (incidentResolutions[incident.id] === 'loss_confirmed' && !incidentEvidenceFiles[incident.id])}
                                            onClick={() => resolveIncident(incident)}
                                            className="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                          >
                                            Save incident resolution
                                          </button>
                                        </div>
                                      ) : null}
                                    </div>
                                  ))}
                                  {!riderMode && latestAttempt?.status === 'failed' && (
                                    isPickupAttempt
                                      ? <div className="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                          <span className="inline-flex rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{isFailedPickup ? 'Failed pickup · Needs action' : 'Pickup rescheduled'}</span>
                                          <p>Failed pickup · {failedPickupCount} {failedPickupCount === 1 ? 'attempt' : 'attempts'}</p>
                                          {latestAttempt.reason_code && <p>{label(latestAttempt.reason_code)}</p>}
                                          {formatDateTime(latestAttempt.attempted_at) && <p>Reported {formatDateTime(latestAttempt.attempted_at)}</p>}
                                          {latestAttempt.proof_url && <a href={latestAttempt.proof_url} target="_blank" rel="noreferrer" className="inline-block font-semibold text-blue-600 hover:underline">View failed-pickup photo</a>}
                                        </div>
                                      : <div className="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                                          <span className="inline-flex rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Failed attempt - {failedAttemptCount}/{maxDeliveryAttempts}</span>
                                          {attemptsMaxed && leg.status === 'needs_resolution' && !leg.resolution_type && <p className="font-semibold text-red-600">Resolution required</p>}
                                          {latestAttempt.reason_code && <p>{label(latestAttempt.reason_code)}</p>}
                                          {latestAttempt.notes && <p>Internal note: {latestAttempt.notes}</p>}
                                          {latestAttempt.proof_url && <a href={latestAttempt.proof_url} target="_blank" rel="noreferrer" className="inline-block font-semibold text-blue-600 hover:underline">View failed-attempt photo</a>}
                                        </div>
                                  )}
                                </div>
                                <div className="flex min-w-0 flex-col gap-3">
                                  <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Assignment and progress</h3>
                                  {canAssign && !riderMode && isFailedPickup && (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                      <button type="button" onClick={() => void resolveFailedPickup(leg.id, 'retry')} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Reschedule Pickup</button>
                                      <button type="button" onClick={() => void resolveFailedPickup(leg.id, 'cancel')} className="rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Cancel Pickup</button>
                                    </div>
                                  )}
                                  {canResolveDelivery && (
                                    <div className="grid gap-2 rounded-lg border border-red-200 bg-red-50 p-3 sm:grid-cols-2 dark:border-red-900 dark:bg-red-950/30">
                                      <p className="sm:col-span-2 text-sm font-semibold text-red-900 dark:text-red-100">
                                        Choose one recovery path for this exhausted delivery.
                                      </p>
                                      <button
                                        type="button"
                                        onClick={() => void resolveFailedDelivery(leg.id, 'retry')}
                                        className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                                      >
                                        Retry delivery
                                      </button>
                                      <button
                                        type="button"
                                        onClick={() => void resolveFailedDelivery(leg.id, 'return')}
                                        className="rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 dark:text-red-300"
                                      >
                                        Return to shop
                                      </button>
                                    </div>
                                  )}
                                  {canUpdateStatus && leg.status === 'assigned' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/picked-up`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Picked up</button>}
                                  {canUpdateStatus && leg.status === 'picked_up' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/in-transit`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">In transit</button>}
                                  {showOutcomeChoice && <div role="group" aria-label="Choose delivery outcome" className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900/40"><p className="mb-2 text-sm font-semibold text-gray-900 dark:text-white">What happened with this delivery?</p><div className="grid gap-2 sm:grid-cols-2"><button type="button" onClick={() => chooseDeliveryOutcome(leg.id, 'proof')} className={`rounded-lg border px-3 py-2 text-sm font-semibold transition-colors ${deliveryOutcome === 'proof' ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'}`}>Delivered successfully</button><button type="button" onClick={() => chooseDeliveryOutcome(leg.id, 'issue')} className={`rounded-lg border px-3 py-2 text-sm font-semibold transition-colors ${deliveryOutcome === 'issue' ? 'border-amber-600 bg-amber-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-amber-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'}`}>Couldn't deliver</button></div></div>}
                                  {canSubmitProof && (!showOutcomeChoice || deliveryOutcome === 'proof') && <div className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30"><div><p className="text-sm font-semibold text-blue-950 dark:text-blue-100">Delivery proof</p><p className="text-xs text-blue-700 dark:text-blue-300">Upload a clear photo showing the successful handoff.</p></div><input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Delivery proof photo" onChange={(event) => setProofFiles({ ...proofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-700 dark:text-gray-200 dark:file:bg-gray-800 dark:file:text-blue-300" /><div className="flex flex-wrap gap-2"><button type="button" onClick={() => submitProof(leg.id)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Submit proof</button>{proofFiles[leg.id] && <button type="button" onClick={(event) => { setProofFiles({ ...proofFiles, [leg.id]: null }); const input = event.currentTarget.closest('div.space-y-3')?.querySelector<HTMLInputElement>('input[type="file"]'); if (input) input.value = ''; }} className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">Clear photo</button>}</div></div>}
                                  {canSubmitReturnHandoff && <div className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30"><div><p className="text-sm font-semibold text-blue-950 dark:text-blue-100">Return handoff</p><p className="text-xs text-blue-700 dark:text-blue-300">At the shop, upload a clear photo of the parcel handoff. Staff will confirm physical receipt.</p></div><input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Return handoff photo" onChange={(event) => setProofFiles({ ...proofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-700 dark:text-gray-200 dark:file:bg-gray-800 dark:file:text-blue-300" /><button type="button" disabled={!proofFiles[leg.id]} onClick={() => void submitReturnHandoff(leg.id)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">Confirm return handoff</button></div>}
                                  {!ownerMode && riderMode && isReturnToShop && returnProof?.review_status === 'pending' && <button type="button" onClick={() => void confirmAct(`/api/logistics/legs/${leg.id}/return-proofs/${returnProof.id}/handoff`, 'Confirm return handoff?', 'Confirm that the parcel was handed to shop staff.')} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Confirm return handoff</button>}
                                  {riderMode && isReturnToShop && returnProof?.review_status === 'rider_confirmed' && <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">Awaiting shop receipt confirmation</p>}
                                  {!riderMode && leg.proofs?.filter((proof) => ['delivery', 'receive'].includes(proof.handoff_type)).map((proof) => (
                                    <div key={proof.id} className="space-y-2">
                                      {proof.proof_url && <div className="relative h-48 w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-900"><img src={proof.proof_url} alt="Uploaded delivery proof" loading="lazy" className="h-full w-full object-cover" /><div className="absolute inset-0 flex items-center justify-center bg-black/15 transition-colors hover:bg-black/25"><button type="button" aria-label="View delivery proof" onClick={(event) => openProof(proof.proof_url!, event.currentTarget)} className="min-h-11 cursor-pointer rounded-lg bg-black/65 px-4 py-2 text-sm font-semibold text-white shadow-lg backdrop-blur-sm transition-colors hover:bg-black/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">View</button></div></div>}
                                      <div className="flex flex-wrap gap-2">
                                        {canApproveProof && leg.status === 'awaiting_proof_approval' && proof.review_status === 'pending' && <><button type="button" onClick={() => void confirmAct(`/api/logistics/proofs/${proof.id}/approve`, 'Confirm delivery?', 'This will complete the delivery.')} className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">Confirm delivery</button><button type="button" onClick={() => void rejectProof(proof.id)} className="rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Reject proof</button></>}
                                        {canApproveProof && isReturnToShop && proof.handoff_type === 'receive' && proof.review_status === 'rider_confirmed' && <button type="button" onClick={() => void confirmAct(`/api/logistics/legs/${leg.id}/return-proofs/${proof.id}/receipt`, 'Confirm return received?', 'Confirm the physical parcel handoff. Item inspection continues in the refund workflow.')} className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">Confirm return received</button>}
                                      </div>
                                    </div>
                                  ))}
                                  {canReportIssue && (!showOutcomeChoice || deliveryOutcome === 'issue') && (
                                    <div className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                                      <div>
                                        <p className="text-sm font-semibold text-amber-950 dark:text-amber-100">Failed delivery attempt</p>
                                        <p className="text-xs text-amber-700 dark:text-amber-300">Choose a reason and add the evidence requested below.</p>
                                      </div>
                                      <label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                                        Reason
                                        <select
                                          value={issueForm.reason_code}
                                          onChange={(event) => setIssueForms({ ...issueForms, [leg.id]: { ...issueForm, reason_code: event.target.value } })}
                                          className="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                          aria-label="Issue reason"
                                        >
                                          <option value="">Choose a reason</option>
                                          <option value="recipient_unavailable">Recipient unavailable</option>
                                          <option value="wrong_or_incomplete_address">Wrong or incomplete address</option>
                                          <option value="recipient_refused">Recipient refused</option>
                                          <option value="item_damaged">Item damaged</option>
                                          <option value="unsafe_location">Unsafe location</option>
                                          <option value="vehicle_or_delivery_problem">Vehicle or delivery problem</option>
                                          <option value="other">Other</option>
                                        </select>
                                      </label>
                                      <label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                                        Attempt photo <span className={requiresIssuePhoto ? 'text-red-600' : 'font-normal text-gray-500'}>({requiresIssuePhoto ? 'required' : 'optional'})</span>
                                        <input
                                          type="file"
                                          required={requiresIssuePhoto}
                                          accept="image/jpeg,image/png,image/webp"
                                          aria-label="Issue photo"
                                          onChange={(event) => setIssueProofFiles({ ...issueProofFiles, [leg.id]: event.target.files?.[0] ?? null })}
                                          className="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-amber-700 dark:text-gray-200 dark:file:bg-gray-800 dark:file:text-amber-300"
                                        />
                                      </label>
                                      <label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                                        Note <span className={requiresIssueNotes ? 'text-red-600' : 'font-normal text-gray-500'}>({requiresIssueNotes ? 'required' : 'optional'})</span>
                                        <textarea
                                          required={requiresIssueNotes}
                                          value={issueForm.notes}
                                          onChange={(event) => setIssueForms({ ...issueForms, [leg.id]: { ...issueForm, notes: event.target.value } })}
                                          placeholder="Optional note"
                                          rows={3}
                                          className="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        />
                                      </label>
                                      <button
                                        type="button"
                                        disabled={
                                          !issueForm.reason_code ||
                                          (requiresIssuePhoto && !issueProofFiles[leg.id]) ||
                                          (requiresIssueNotes && !issueForm.notes.trim())
                                        }
                                        onClick={() => reportIssue(leg.id, activeAssignment?.id)}
                                        className="w-full rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                                      >
                                        {leg.status === 'delivery_attempted' ? 'Request cancellation' : 'Report issue'}
                                      </button>
                                    </div>
                                  )}
                                  {!riderMode && leg.status === 'delivery_attempted' && <button type="button" onClick={() => void confirmAct(`/api/logistics/legs/${leg.id}/cancel`, 'Cancel delivery?', 'This is the final cancellation action.')} className="rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Cancel delivery</button>}
                                </div>
                                {canScheduleLeg && (
                                  <div className="flex flex-col gap-2">
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                      <label className="text-xs font-semibold text-gray-700 dark:text-gray-200">Delivery date<input type="date" aria-label="Delivery date" value={schedule.date} onChange={(event) => setDeliverySchedules({ ...deliverySchedules, [leg.id]: { ...schedule, date: event.target.value } })} className="mt-1 block rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white" /></label>
                                      <label className="text-xs font-semibold text-gray-700 dark:text-gray-200">Delivery window<select aria-label="Delivery window" value={schedule.window} onChange={(event) => setDeliverySchedules({ ...deliverySchedules, [leg.id]: { ...schedule, window: event.target.value } })} className="mt-1 block rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"><option value="">Choose a window</option><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select></label>
                                    </div>
                                    {activeAssignment ? (
                                      <><p className="text-sm font-medium text-gray-700 dark:text-gray-200">Assigned to {activeAssignment.rider_profile?.name ?? 'rider'}</p><button type="button" disabled={!schedule.date || !schedule.window || assigningLegId === leg.id} onClick={() => void scheduleLeg(leg.id, false)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Save schedule</button></>
                                    ) : (
                                      <div className="flex flex-col gap-2 sm:flex-row">
                                        <select
                                          value={selectedRiders[leg.id] ?? ''}
                                          onChange={(event) => setSelectedRiders({ ...selectedRiders, [leg.id]: event.target.value })}
                                          className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                          aria-label={`Choose rider for ${leg.leg_type} leg`}
                                        >
                                          <option value="">Choose available rider</option>
                                          {assignableRiders.map((rider) => <option key={rider.id} value={rider.id}>{rider.name}{rider.phone ? ` (${rider.phone})` : ''}</option>)}
                                        </select>
                                        <button type="button" disabled={!schedule.date || !schedule.window || !selectedRiders[leg.id] || assigningLegId === leg.id} onClick={() => void scheduleLeg(leg.id, true)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{assigningLegId === leg.id ? 'Scheduling...' : 'Schedule & assign rider'}</button>
                                      </div>
                                    )}
                                  </div>
                                )}
                                {!canScheduleLeg && (activeAssignment ? (
                                  <p className="text-sm font-medium text-gray-700 dark:text-gray-200">Assigned to {activeAssignment.rider_profile?.name ?? 'rider'}</p>
                                ) : canAssignLeg ? (
                                  <div className="flex flex-col gap-2 sm:flex-row">
                                    <select
                                      value={selectedRiders[leg.id] ?? ''}
                                      onChange={(event) => setSelectedRiders({ ...selectedRiders, [leg.id]: event.target.value })}
                                      className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                      aria-label={`Choose rider for ${leg.leg_type} leg`}
                                    >
                                      <option value="">Choose available rider</option>
                                      {assignableRiders.map((rider) => <option key={rider.id} value={rider.id}>{rider.name}{rider.phone ? ` (${rider.phone})` : ''}</option>)}
                                    </select>
                                    <button
                                      type="button"
                                      disabled={!selectedRiders[leg.id] || assigningLegId === leg.id}
                                      onClick={() => assignRider(leg.id)}
                                      className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                      {assigningLegId === leg.id ? 'Assigning...' : 'Assign'}
                                    </button>
                                  </div>
                                ) : (
                                  <p className="text-sm text-gray-500">No assignment needed.</p>
                                ))}
                              </div>
                            );
                    })}
                    {canAssign && assignableRiders.length === 0 && (shipment.legs ?? []).some((leg) => !leg.assignments?.some((assignment) => ['assigned', 'accepted'].includes(assignment.status)) && !['delivered', 'cancelled'].includes(leg.status)) && <p className="text-sm text-amber-700">No active available riders. Create or make a logistics rider available first.</p>}
                    {assignmentError && <p className="text-sm text-red-600">{assignmentError}</p>}
                    {actionError && <p className="text-sm text-red-600">{actionError}</p>}
                  </div>
                </div>
                </div>
                {selectedProofUrl && (
                  <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Delivery proof image"
                    onKeyDown={trapDialogFocus}
                    className="relative flex h-[min(88dvh,56rem)] items-center justify-center overflow-hidden rounded-3xl bg-gray-950 p-4 sm:p-8"
                  >
                    <button
                      ref={proofCloseButtonRef}
                      type="button"
                      aria-label="Close delivery proof image"
                      onClick={closeProof}
                      className="absolute left-4 top-4 z-10 inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-full bg-black/65 text-white backdrop-blur-sm transition-colors hover:bg-black/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                    >
                      <X aria-hidden="true" size={22} />
                    </button>
                    <img src={selectedProofUrl} alt="Enlarged delivery proof" className="h-full w-full object-contain" />
                  </div>
                )}
                </>
              </Modal>
            </article>;
          })}
        </div>

        {shipments.total > 0 && (
            <div className="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-800">
              <div className="text-sm text-gray-700 dark:text-gray-300">
                Showing <span className="font-medium">{shipments.from}</span> to <span className="font-medium">{shipments.to}</span> of{' '}
                <span className="font-medium">{shipments.total}</span>
              </div>
              <div className="flex items-center gap-2">
                {shipments.links.map((link, index) => (
                  link.url ? (
                    <Link
                      key={`${link.label}-${index}`}
                      href={link.url}
                      preserveScroll
                      preserveState
                      className={`min-w-[40px] rounded-lg px-3 py-2 text-center text-sm font-medium transition-colors ${
                        link.active
                          ? 'bg-blue-600 text-white'
                          : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800'
                      }`}
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ) : (
                    <span
                      key={`${link.label}-${index}`}
                      className="min-w-[40px] rounded-lg border border-gray-200 px-3 py-2 text-center text-sm text-gray-400 dark:border-gray-700"
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  )
                ))}
              </div>
            </div>
        )}
      </div>
    </AppLayoutERP>
  );
}
