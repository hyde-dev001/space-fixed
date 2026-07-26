import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import Swal from 'sweetalert2';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import RetailOrderSummary from './components/RetailOrderSummary';
import {
  logisticsModuleForSourceType,
  logisticsModuleLabel,
  logisticsSourceLabel,
  type LogisticsModule,
  type LogisticsShipment,
  type PaginatedResponse,
  type TrackingShipmentLeg,
} from '@/types/logistics';

type ShipmentFilters = {
  status: string;
  purpose?: string;
  window: string;
  module?: 'all' | LogisticsModule;
};

const statusOptions = [
  ['all', 'All'],
  ['incomplete', 'Incomplete'],
  ['requested', 'Requested'],
  ['active', 'Active'],
  ['awaiting_proof_approval', 'Awaiting Proof Approval'],
  ['failed_attempts', 'Failed attempts'],
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
  const { shipments, filters, assignableRiders, canAssign, canUpdateStatus, canRecordProof, canApproveProof, riderMode, maxDeliveryAttempts = 2, availableModules = [], showModuleFilter = false } = usePage<{
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
  }>().props;
  const [expandedShipmentId, setExpandedShipmentId] = useState<number | null>(null);
  const [selectedRiders, setSelectedRiders] = useState<Record<number, string>>({});
  const [deliverySchedules, setDeliverySchedules] = useState<Record<number, { date: string; window: string }>>({});
  const [assigningLegId, setAssigningLegId] = useState<number | null>(null);
  const [assignmentError, setAssignmentError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [proofFiles, setProofFiles] = useState<Record<number, File | null>>({});
  const [issueProofFiles, setIssueProofFiles] = useState<Record<number, File | null>>({});
  const [issueForms, setIssueForms] = useState<Record<number, { reason_code: string; notes: string }>>({});
  const [deliveryOutcomes, setDeliveryOutcomes] = useState<Record<number, 'proof' | 'issue'>>({});
  const hasActionColumn = riderMode || canAssign || canUpdateStatus || canRecordProof || canApproveProof;

  const updateFilter = (key: keyof ShipmentFilters, value: string) => {
    const next = { ...filters, [key]: value, page: 1 };
    if (key === 'module') {
      const purpose = purposeOptions.find(([option]) => option === filters.purpose);
      if (purpose && purpose[2] !== 'all' && purpose[2] !== value) next.purpose = 'all';
    }
    router.get(riderMode ? '/erp/logistics/deliveries' : '/erp/logistics/shipments', next, {
      preserveScroll: true,
      preserveState: true,
    });
  };
  const selectedModule = filters.module ?? (availableModules.length === 1 ? availableModules[0] : 'all');
  const visiblePurposeOptions = purposeOptions.filter(([, , module]) => selectedModule === 'all' || module === 'all' || module === selectedModule);

  const act = async (url: string, body?: FormData | Record<string, string>, issue = false) => {
    setActionError(null);
    try {
      await axios.post(url, body, body instanceof FormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : undefined);
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
    if (!file) return setActionError('Upload a photo showing the delivery attempt.');
    const data = new FormData();
    if (!assignmentId) return setActionError('The active rider assignment could not be verified. Refresh and try again.');
    data.append('delivery_assignment_id', String(assignmentId));
    data.append('reason_code', form.reason_code);
    if (form.notes) data.append('notes', form.notes);
    data.append('proof_file', file);
    void act(`/api/logistics/legs/${legId}/report-issue`, data, true);
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

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">ID</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Purpose</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Receiver</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Address</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Status</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Source</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Legs</TableCell>
                  {hasActionColumn && <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Action</TableCell>}
                </TableRow>
              </TableHeader>
              <TableBody>
                {shipments.data.length === 0 ? (
                  <TableRow>
                    <TableCell className="px-6 py-6 text-sm text-gray-500 dark:text-gray-400">No shipments found.</TableCell>
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                  </TableRow>
                ) : shipments.data.map((shipment) => (
                  <React.Fragment key={shipment.id}>
                  <TableRow className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <TableCell className="px-6 py-4 font-semibold text-gray-900 dark:text-white">#{shipment.id}</TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{label(shipment.purpose)}</TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{contact(shipment.legs?.[0]).name || '—'}</TableCell>
                    <TableCell className="max-w-xs px-6 py-4 text-gray-600 dark:text-gray-300">{contact(shipment.legs?.[0]).address || '—'}</TableCell>
                    <TableCell className="px-6 py-4">
                      <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${statusClass(shipment.status)}`}>
                        {label(shipment.status)}
                      </span>
                    </TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">
                      <span className="mr-2 inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                        {logisticsModuleLabel(logisticsModuleForSourceType(shipment.source_type))}
                      </span>
                      <span>{logisticsSourceLabel(shipment)}</span>
                      {shipment.source_summary && <span className="mt-1 block text-xs text-gray-500">
                        {shipment.source_summary.customer_name} · {shipment.source_summary.shoe_summary}
                      </span>}
                      <RetailOrderSummary summary={shipment.order_summary} />
                    </TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{shipment.legs?.length ?? 0}</TableCell>
                    {hasActionColumn && (
                      <TableCell className="px-6 py-4">
                        <button
                          type="button"
                          onClick={() => setExpandedShipmentId(expandedShipmentId === shipment.id ? null : shipment.id)}
                          className="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100"
                        >
                          {expandedShipmentId === shipment.id ? 'Close' : 'Open delivery'}
                        </button>
                      </TableCell>
                    )}
                  </TableRow>
                  {hasActionColumn && expandedShipmentId === shipment.id && (
                    <TableRow>
                      <TableCell colSpan={hasActionColumn ? 8 : 7} className="bg-gray-50 px-6 py-5 dark:bg-gray-900/40">
                        <div className="space-y-3">
                          <RetailOrderSummary summary={shipment.order_summary} expanded />
                          {(shipment.legs ?? []).map((leg) => {
                            const recipient = contact(leg);
                            const activeAssignment = leg.assignments?.find((assignment) => ['assigned', 'accepted'].includes(assignment.status));
                            const latestAttempt = leg.attempts?.[0];
                            const failedAttemptCount = leg.failed_attempt_count ?? latestAttempt?.attempt_number ?? 0;
                            const attemptsMaxed = failedAttemptCount >= maxDeliveryAttempts;
                            const canAssignLeg = leg.status === 'pending' && !activeAssignment && !attemptsMaxed;
                            const isReturnToShop = leg.leg_type === 'return_to_shop';
                            const returnProof = leg.proofs?.find((proof) => proof.handoff_type === 'receive');
                            const canReportIssue = riderMode && !isReturnToShop && ['in_transit', 'delivery_attempted'].includes(leg.status);
                            const canScheduleLeg = canAssign && !riderMode && !leg.scheduled_delivery_date && leg.delivery_batch_id == null && ['pending', 'assigned'].includes(leg.status);
                            const schedule = deliverySchedules[leg.id] ?? { date: '', window: 'morning' };
                            const issueForm = issueForms[leg.id] ?? { reason_code: '', notes: '' };
                            const canSubmitProof = canRecordProof && !isReturnToShop && leg.status === 'in_transit';
                            const canSubmitReturnHandoff = riderMode && canRecordProof && isReturnToShop && leg.status === 'in_transit' && !returnProof;
                            const showOutcomeChoice = canSubmitProof && canReportIssue;
                            const deliveryOutcome = deliveryOutcomes[leg.id];

                            return (
                              <div key={leg.id} className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700 dark:bg-gray-800">
                                <div>
                                  <p className="text-sm font-semibold text-gray-900 dark:text-white">{label(leg.leg_type)} leg</p>
                                  <p className="text-xs text-gray-500 dark:text-gray-400">{label(leg.status)}</p>
                                  <div className="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                    <p><strong>Receiver:</strong> {recipient.name || 'Not provided'}</p>
                                    <p><strong>Phone:</strong> {recipient.phone || 'Not provided'}</p>
                                    <p><strong>Address:</strong> {recipient.address || 'Not provided'}</p>
                                    {recipient.instructions && <p><strong>Instructions:</strong> {recipient.instructions}</p>}
                                    <p><strong>Schedule:</strong> {leg.scheduled_delivery_date || 'Not scheduled'}{leg.delivery_window ? ` · ${label(leg.delivery_window)}` : ''}</p>
                                    {leg.stop_sequence && <p><strong>Stop:</strong> {leg.stop_sequence}</p>}
                                  </div>
                                  {!riderMode && latestAttempt?.status === 'failed' && <div className="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300"><span className="inline-flex rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Failed attempt - {failedAttemptCount}/{maxDeliveryAttempts}</span>{attemptsMaxed && <p className="font-semibold text-red-600">Subject for refund</p>}{latestAttempt.reason_code && <p>{label(latestAttempt.reason_code)}</p>}{latestAttempt.notes && <p>Internal note: {latestAttempt.notes}</p>}{latestAttempt.file_path && <a href={`/storage/${latestAttempt.file_path}`} target="_blank" rel="noreferrer" className="inline-block font-semibold text-blue-600 hover:underline">View failed-attempt photo</a>}</div>}
                                </div>
                                <div className="flex min-w-0 flex-col gap-3 sm:min-w-[22rem]">
                                  {canUpdateStatus && leg.status === 'assigned' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/picked-up`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Picked up</button>}
                                  {canUpdateStatus && leg.status === 'picked_up' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/in-transit`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">In transit</button>}
                                  {showOutcomeChoice && <div role="group" aria-label="Choose delivery outcome" className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900/40"><p className="mb-2 text-sm font-semibold text-gray-900 dark:text-white">What happened with this delivery?</p><div className="grid gap-2 sm:grid-cols-2"><button type="button" onClick={() => chooseDeliveryOutcome(leg.id, 'proof')} className={`rounded-lg border px-3 py-2 text-sm font-semibold transition-colors ${deliveryOutcome === 'proof' ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-blue-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'}`}>Delivered successfully</button><button type="button" onClick={() => chooseDeliveryOutcome(leg.id, 'issue')} className={`rounded-lg border px-3 py-2 text-sm font-semibold transition-colors ${deliveryOutcome === 'issue' ? 'border-amber-600 bg-amber-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-amber-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'}`}>Couldn't deliver</button></div></div>}
                                  {canSubmitProof && (!showOutcomeChoice || deliveryOutcome === 'proof') && <div className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30"><div><p className="text-sm font-semibold text-blue-950 dark:text-blue-100">Delivery proof</p><p className="text-xs text-blue-700 dark:text-blue-300">Upload a clear photo showing the successful handoff.</p></div><input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Delivery proof photo" onChange={(event) => setProofFiles({ ...proofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-700 dark:text-gray-200 dark:file:bg-gray-800 dark:file:text-blue-300" /><div className="flex flex-wrap gap-2"><button type="button" onClick={() => submitProof(leg.id)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Submit proof</button>{proofFiles[leg.id] && <button type="button" onClick={(event) => { setProofFiles({ ...proofFiles, [leg.id]: null }); const input = event.currentTarget.closest('div.space-y-3')?.querySelector<HTMLInputElement>('input[type="file"]'); if (input) input.value = ''; }} className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">Clear photo</button>}</div></div>}
                                  {canSubmitReturnHandoff && <div className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30"><div><p className="text-sm font-semibold text-blue-950 dark:text-blue-100">Return handoff</p><p className="text-xs text-blue-700 dark:text-blue-300">At the shop, upload a clear photo of the parcel handoff. Staff will confirm physical receipt.</p></div><input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Return handoff photo" onChange={(event) => setProofFiles({ ...proofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-700 dark:text-gray-200 dark:file:bg-gray-800 dark:file:text-blue-300" /><button type="button" disabled={!proofFiles[leg.id]} onClick={() => void submitReturnHandoff(leg.id)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">Confirm return handoff</button></div>}
                                  {riderMode && isReturnToShop && returnProof?.review_status === 'pending' && <button type="button" onClick={() => void confirmAct(`/api/logistics/legs/${leg.id}/return-proofs/${returnProof.id}/handoff`, 'Confirm return handoff?', 'Confirm that the parcel was handed to shop staff.')} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Confirm return handoff</button>}
                                  {riderMode && isReturnToShop && returnProof?.review_status === 'rider_confirmed' && <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">Awaiting shop receipt confirmation</p>}
                                  {canApproveProof && leg.proofs?.filter((proof) => ['delivery', 'receive'].includes(proof.handoff_type)).map((proof) => (
                                    <div key={proof.id} className="flex items-center gap-2">
                                      {proof.file_path && <a href={`/storage/${proof.file_path}`} target="_blank" rel="noreferrer" aria-label="Open uploaded delivery proof"><img src={`/storage/${proof.file_path}`} alt="Uploaded delivery proof" className="h-12 w-12 rounded border border-gray-200 object-cover" /></a>}
                                      {leg.status === 'awaiting_proof_approval' && proof.review_status === 'pending' && <><button type="button" onClick={() => void confirmAct(`/api/logistics/proofs/${proof.id}/approve`, 'Confirm delivery?', 'This will complete the delivery.')} className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">Confirm delivery</button><button type="button" onClick={() => void rejectProof(proof.id)} className="rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Reject proof</button></>}
                                      {isReturnToShop && proof.handoff_type === 'receive' && proof.review_status === 'rider_confirmed' && <button type="button" onClick={() => void confirmAct(`/api/logistics/legs/${leg.id}/return-proofs/${proof.id}/receipt`, 'Confirm return received?', 'Confirm the physical parcel handoff. Item inspection continues in the refund workflow.')} className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">Confirm return received</button>}
                                    </div>
                                  ))}
                                  {canReportIssue && (!showOutcomeChoice || deliveryOutcome === 'issue') && <div className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30"><div><p className="text-sm font-semibold text-amber-950 dark:text-amber-100">Failed delivery attempt</p><p className="text-xs text-amber-700 dark:text-amber-300">Choose a reason and upload a photo showing you reached the delivery location.</p></div><label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">Reason<select value={issueForm.reason_code} onChange={(event) => setIssueForms({ ...issueForms, [leg.id]: { ...issueForm, reason_code: event.target.value } })} className="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" aria-label="Issue reason"><option value="">Choose a reason</option><option value="recipient_unavailable">Recipient unavailable</option><option value="wrong_or_incomplete_address">Wrong or incomplete address</option><option value="recipient_refused">Recipient refused</option><option value="vehicle_or_delivery_problem">Vehicle or delivery problem</option><option value="other">Other</option></select></label><label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">Attempt photo <span className="text-red-600">(required)</span><input type="file" required accept="image/jpeg,image/png,image/webp" aria-label="Issue photo" onChange={(event) => setIssueProofFiles({ ...issueProofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-amber-700 dark:text-gray-200 dark:file:bg-gray-800 dark:file:text-amber-300" /></label><label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">Note <span className="font-normal text-gray-500">(optional)</span><textarea value={issueForm.notes} onChange={(event) => setIssueForms({ ...issueForms, [leg.id]: { ...issueForm, notes: event.target.value } })} placeholder="Optional note" rows={3} className="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white" /></label><button type="button" disabled={!issueForm.reason_code || !issueProofFiles[leg.id]} onClick={() => reportIssue(leg.id, activeAssignment?.id)} className="w-full rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50">{leg.status === 'delivery_attempted' ? 'Request cancellation' : 'Report issue'}</button></div>}
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
                      </TableCell>
                    </TableRow>
                  )}
                  </React.Fragment>
                ))}
              </TableBody>
            </Table>
          </div>

          {shipments.total > 0 && (
            <div className="flex items-center justify-between border-t border-gray-200 px-6 py-4 dark:border-gray-700">
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
      </div>
    </AppLayoutERP>
  );
}
