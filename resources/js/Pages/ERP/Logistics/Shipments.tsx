import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { LogisticsShipment, PaginatedResponse } from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';

type ShipmentFilters = {
  status: string;
  purpose: string;
  window?: string;
};

const statusOptions = [
  ['all', 'All'],
  ['incomplete', 'Incomplete'],
  ['requested', 'Requested'],
  ['active', 'Active'],
  ['completed', 'Completed'],
  ['cancelled', 'Cancelled'],
];

const purposeOptions = [
  ['all', 'All Types'],
  ['retail_delivery', 'Retail Delivery'],
  ['repair_pickup', 'Repair Pickup'],
  ['repair_return', 'Repair Return'],
  ['refund_return', 'Refund Return'],
];

const riderStatusOptions = [
  ['all', 'All statuses'],
  ['assigned', 'Assigned'],
  ['picked_up', 'Picked up'],
  ['in_transit', 'In transit'],
  ['delivery_attempted', 'Needs attention'],
  ['awaiting_proof_approval', 'For proof approval'],
  ['delivered', 'Completed'],
  ['cancelled', 'Cancelled'],
];

const issueReasons: Record<string, string> = {
  recipient_unavailable: 'Recipient unavailable',
  wrong_or_incomplete_address: 'Wrong or incomplete address',
  recipient_refused: 'Recipient refused delivery',
  vehicle_or_delivery_problem: 'Vehicle or delivery problem',
  other: 'Other',
};

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function statusClass(status: string) {
  if (status === 'completed') return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
  if (status === 'cancelled') return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
  if (status === 'active') return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
  return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
}

export default function Shipments() {
  const { shipments, filters, assignableRiders, canAssign, canUpdateStatus, canRecordProof, canApproveProof, riderMode } = usePage<{
    shipments: PaginatedResponse<LogisticsShipment>;
    filters: ShipmentFilters;
    assignableRiders: Array<{ id: number; name: string; phone?: string | null }>;
    canAssign: boolean;
    canUpdateStatus: boolean;
    canRecordProof: boolean;
    canApproveProof: boolean;
    riderMode: boolean;
  }>().props;
  const [expandedShipmentId, setExpandedShipmentId] = useState<number | null>(null);
  const [selectedRiders, setSelectedRiders] = useState<Record<number, string>>({});
  const [assigningLegId, setAssigningLegId] = useState<number | null>(null);
  const [assignmentError, setAssignmentError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [proofFiles, setProofFiles] = useState<Record<number, File | null>>({});

  const updateFilter = (key: keyof ShipmentFilters, value: string) => {
    router.get(riderMode ? '/erp/logistics/deliveries' : '/erp/logistics/shipments', { ...filters, [key]: value, page: 1 }, {
      preserveScroll: true,
      preserveState: true,
    });
  };

  const act = async (url: string, body?: FormData | Record<string, string>, successMessage = 'Delivery updated.') => {
    setActionError(null);
    try {
      await axios.post(url, body, body instanceof FormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : undefined);
      router.reload({ only: ['shipments'] });
      void workflowFeedback.success({ title: successMessage, toast: true, position: 'top-end', showConfirmButton: false, timer: 2500 });
    } catch (error: any) {
      const message = error.response?.data?.message ?? 'Unable to update this delivery.';
      setActionError(message);
      void workflowFeedback.error(message);
    }
  };

  const submitProof = (legId: number) => {
    const file = proofFiles[legId];
    if (!file) return setActionError('Select a proof image first.');
    const form = new FormData();
    form.append('handoff_type', 'delivery');
    form.append('proof_type', 'photo');
    form.append('proof_file', file);
    void act(`/api/logistics/legs/${legId}/proof`, form, 'Proof submitted.');
  };

  const assignRider = async (legId: number) => {
    const riderProfileId = Number(selectedRiders[legId]);
    if (!riderProfileId) return;

    const confirmation = await workflowFeedback.confirm({ title: 'Assign this rider?', text: 'The rider will receive this delivery assignment.', confirmButtonText: 'Assign rider' });
    if (!confirmation.isConfirmed) return;

    setAssigningLegId(legId);
    setAssignmentError(null);

    try {
      await axios.post(`/api/logistics/legs/${legId}/assign`, {
        assignment_type: 'internal_rider',
        rider_profile_id: riderProfileId,
      });
      router.reload({ only: ['shipments', 'assignableRiders'] });
      void workflowFeedback.success({ title: 'Rider assigned.', toast: true, position: 'top-end', showConfirmButton: false, timer: 2500 });
    } catch (error: any) {
      const message = error.response?.data?.message ?? 'Unable to assign this rider. Refresh the page and try again.';
      setAssignmentError(message);
      void workflowFeedback.error(message);
    } finally {
      setAssigningLegId(null);
    }
  };

  const reportIssue = async (legId: number) => {
    const reason = await workflowFeedback.alert({
      title: 'Report delivery issue',
      input: 'select',
      inputOptions: issueReasons,
      inputPlaceholder: 'Choose a reason',
      showCancelButton: true,
      confirmButtonText: 'Continue',
      inputValidator: (value) => !value ? 'Choose a reason.' : undefined,
    });
    if (!reason.isConfirmed || !reason.value) return;
    const notes = await workflowFeedback.alert({ title: 'Add a note (optional)', input: 'textarea', showCancelButton: true, confirmButtonText: 'Submit report' });
    if (!notes.isConfirmed) return;

    await act(`/api/logistics/legs/${legId}/report-issue`, { reason_code: String(reason.value), notes: String(notes.value ?? '') }, 'Issue reported to the dispatcher.');
  };

  const cancelDelivery = async (legId: number) => {
    const confirmation = await workflowFeedback.confirm({ title: 'Cancel this delivery?', text: 'The customer will see the reported cancellation reason.', confirmButtonText: 'Cancel delivery', confirmButtonColor: '#dc2626' });
    if (confirmation.isConfirmed) await act(`/api/logistics/legs/${legId}/cancel`, undefined, 'Delivery cancelled.');
  };

  const confirmDelivery = async (proofId: number) => {
    const confirmation = await workflowFeedback.confirm({ title: 'Confirm delivery?', text: 'This will mark the delivery as completed.', confirmButtonText: 'Confirm delivery' });
    if (confirmation.isConfirmed) await act(`/api/logistics/proofs/${proofId}/approve`, undefined, 'Delivery confirmed.');
  };

  return (
    <AppLayoutERP>
      <Head title={riderMode ? "My Deliveries" : "ERP Logistics Shipments"} />
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-950 dark:text-white">{riderMode ? 'My Deliveries' : 'Shipments'}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">{riderMode ? 'Process your assigned deliveries.' : 'Assign riders and approve delivery proof.'}</p>
        </div>

        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-3">
            <select
              value={filters.status}
              onChange={(event) => updateFilter('status', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label={riderMode ? 'Filter deliveries by status' : 'Filter shipments by status'}
            >
              {(riderMode ? riderStatusOptions : statusOptions).map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
            {riderMode ? <select
              value={filters.window ?? 'all'}
              onChange={(event) => updateFilter('window', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter deliveries by assignment date"
            >
              <option value="all">Any time</option>
              <option value="today">Today</option>
              <option value="week">This week</option>
            </select> : <select
              value={filters.purpose}
              onChange={(event) => updateFilter('purpose', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by type"
            >
              {purposeOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
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
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Status</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Source</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Legs</TableCell>
                  {(canAssign || canUpdateStatus || canRecordProof || canApproveProof) && <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Action</TableCell>}
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
                  </TableRow>
                ) : shipments.data.map((shipment) => (
                  <React.Fragment key={shipment.id}>
                  <TableRow className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <TableCell className="px-6 py-4 font-semibold text-gray-900 dark:text-white">#{shipment.id}</TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{label(shipment.purpose)}</TableCell>
                    <TableCell className="px-6 py-4">
                      <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${statusClass(shipment.status)}`}>
                        {label(shipment.status)}
                      </span>
                    </TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{shipment.source_type} #{shipment.source_id}</TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{shipment.legs?.length ?? 0}</TableCell>
                    {(canAssign || canUpdateStatus || canRecordProof || canApproveProof) && (
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
                  {(canAssign || canUpdateStatus || canRecordProof || canApproveProof) && expandedShipmentId === shipment.id && (
                    <TableRow>
                      <TableCell colSpan={6} className="bg-gray-50 px-6 py-5 dark:bg-gray-900/40">
                        <div className="space-y-3">
                          {(shipment.legs ?? []).map((leg) => {
                            const activeAssignment = leg.assignments?.find((assignment) => ['assigned', 'accepted'].includes(assignment.status));
                            const canAssignLeg = !['delivered', 'cancelled'].includes(leg.status) && !activeAssignment;
                            const latestAttempt = leg.attempts?.[0];

                            return (
                              <div key={leg.id} className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700 dark:bg-gray-800">
                                <div>
                                  <p className="text-sm font-semibold text-gray-900 dark:text-white">{label(leg.leg_type)} leg</p>
                                  <p className="text-xs text-gray-500 dark:text-gray-400">{label(leg.status)}</p>
                                  {!riderMode && leg.status === 'delivery_attempted' && <p className="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Needs attention</p>}
                                  {!riderMode && latestAttempt && <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{issueReasons[latestAttempt.reason_code ?? ''] ?? label(latestAttempt.reason_code ?? 'other')}{latestAttempt.notes ? ` — ${latestAttempt.notes}` : ''}</p>}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                  {canUpdateStatus && leg.status === 'assigned' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/picked-up`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Picked up</button>}
                                  {canUpdateStatus && leg.status === 'picked_up' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/in-transit`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">In transit</button>}
                                  {canRecordProof && leg.status === 'in_transit' && <><input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setProofFiles({ ...proofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="text-sm" /><button type="button" onClick={() => submitProof(leg.id)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Submit proof</button></>}
                                  {canApproveProof && leg.proofs?.filter((proof) => ['delivery', 'receive'].includes(proof.handoff_type)).map((proof) => (
                                    <div key={proof.id} className="flex items-center gap-2">
                                      {proof.file_path && <a href={`/storage/${proof.file_path}`} target="_blank" rel="noreferrer" aria-label="Open uploaded delivery proof"><img src={`/storage/${proof.file_path}`} alt="Uploaded delivery proof" className="h-12 w-12 rounded border border-gray-200 object-cover" /></a>}
                                      {leg.status === 'awaiting_proof_approval' && proof.review_status === 'pending' && <button type="button" onClick={() => void confirmDelivery(proof.id)} className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">Confirm delivery</button>}
                                    </div>
                                  ))}
                                  {riderMode && ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'].includes(leg.status) && <button type="button" onClick={() => void reportIssue(leg.id)} className="rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">Report issue</button>}
                                  {!riderMode && leg.status === 'delivery_attempted' && <button type="button" onClick={() => void cancelDelivery(leg.id)} className="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Cancel delivery</button>}
                                </div>
                                {activeAssignment ? (
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
                                )}
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
