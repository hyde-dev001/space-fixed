import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import Swal from 'sweetalert2';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { LogisticsShipment, PaginatedResponse } from '@/types/logistics';

type ShipmentFilters = {
  status: string;
  purpose?: string;
  window: string;
};

const statusOptions = [
  ['all', 'All'],
  ['incomplete', 'Incomplete'],
  ['requested', 'Requested'],
  ['active', 'Active'],
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

const purposeOptions = [
  ['all', 'All Types'],
  ['retail_delivery', 'Retail Delivery'],
  ['repair_pickup', 'Repair Pickup'],
  ['repair_return', 'Repair Return'],
  ['refund_return', 'Refund Return'],
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

const toast = (icon: 'success' | 'error', title: string) => Swal.fire({
  toast: true,
  position: 'top-end',
  icon,
  title,
  showConfirmButton: false,
  timer: 2500,
  timerProgressBar: true,
});

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
  const [issueForms, setIssueForms] = useState<Record<number, { reason_code: string; notes: string }>>({});
  const hasActionColumn = riderMode || canAssign || canUpdateStatus || canRecordProof || canApproveProof;

  const updateFilter = (key: keyof ShipmentFilters, value: string) => {
    router.get(riderMode ? '/erp/logistics/deliveries' : '/erp/logistics/shipments', { ...filters, [key]: value, page: 1 }, {
      preserveScroll: true,
      preserveState: true,
    });
  };

  const act = async (url: string, body?: FormData | Record<string, string>) => {
    setActionError(null);
    try {
      await axios.post(url, body, body instanceof FormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : undefined);
      toast('success', 'Delivery updated.');
      router.reload({ only: ['shipments'] });
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

  const submitProof = (legId: number) => {
    const file = proofFiles[legId];
    if (!file) return setActionError('Select a proof image first.');
    const form = new FormData();
    form.append('handoff_type', 'delivery');
    form.append('proof_type', 'photo');
    form.append('proof_file', file);
    void act(`/api/logistics/legs/${legId}/proof`, form);
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

  const reportIssue = (legId: number) => {
    const form = issueForms[legId] ?? { reason_code: '', notes: '' };
    if (!form.reason_code) return setActionError('Choose an issue reason first.');
    void act(`/api/logistics/legs/${legId}/report-issue`, form);
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
              {purposeOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
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
                      <TableCell colSpan={hasActionColumn ? 6 : 5} className="bg-gray-50 px-6 py-5 dark:bg-gray-900/40">
                        <div className="space-y-3">
                          {(shipment.legs ?? []).map((leg) => {
                            const activeAssignment = leg.assignments?.find((assignment) => ['assigned', 'accepted'].includes(assignment.status));
                            const canAssignLeg = !['delivered', 'cancelled'].includes(leg.status) && !activeAssignment;
                            const latestAttempt = leg.attempts?.[0];
                            const canReportIssue = riderMode && ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'].includes(leg.status);
                            const issueForm = issueForms[leg.id] ?? { reason_code: '', notes: '' };

                            return (
                              <div key={leg.id} className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700 dark:bg-gray-800">
                                <div>
                                  <p className="text-sm font-semibold text-gray-900 dark:text-white">{label(leg.leg_type)} leg</p>
                                  <p className="text-xs text-gray-500 dark:text-gray-400">{label(leg.status)}</p>
                                  {!riderMode && leg.status === 'delivery_attempted' && <div className="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300"><span className="inline-flex rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Needs attention</span>{latestAttempt?.reason_code && <p>{label(latestAttempt.reason_code)}</p>}{latestAttempt?.notes && <p>Internal note: {latestAttempt.notes}</p>}</div>}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                  {canUpdateStatus && leg.status === 'assigned' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/picked-up`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Picked up</button>}
                                  {canUpdateStatus && leg.status === 'picked_up' && <button type="button" onClick={() => void act(`/api/logistics/legs/${leg.id}/in-transit`)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">In transit</button>}
                                  {canRecordProof && leg.status === 'in_transit' && <><input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setProofFiles({ ...proofFiles, [leg.id]: event.target.files?.[0] ?? null })} className="text-sm" /><button type="button" onClick={() => submitProof(leg.id)} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Submit proof</button></>}
                                  {canApproveProof && leg.proofs?.filter((proof) => ['delivery', 'receive'].includes(proof.handoff_type)).map((proof) => (
                                    <div key={proof.id} className="flex items-center gap-2">
                                      {proof.file_path && <a href={`/storage/${proof.file_path}`} target="_blank" rel="noreferrer" aria-label="Open uploaded delivery proof"><img src={`/storage/${proof.file_path}`} alt="Uploaded delivery proof" className="h-12 w-12 rounded border border-gray-200 object-cover" /></a>}
                                      {leg.status === 'awaiting_proof_approval' && proof.review_status === 'pending' && <button type="button" onClick={() => void confirmAct(`/api/logistics/proofs/${proof.id}/approve`, 'Confirm delivery?', 'This will complete the delivery.')} className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white">Confirm delivery</button>}
                                    </div>
                                  ))}
                                  {canReportIssue && <div className="flex flex-wrap items-center gap-2"><select value={issueForm.reason_code} onChange={(event) => setIssueForms({ ...issueForms, [leg.id]: { ...issueForm, reason_code: event.target.value } })} className="w-44 rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white" aria-label="Issue reason"><option value="">Issue reason</option><option value="recipient_unavailable">Recipient unavailable</option><option value="wrong_or_incomplete_address">Wrong or incomplete address</option><option value="recipient_refused">Recipient refused</option><option value="vehicle_or_delivery_problem">Vehicle or delivery problem</option><option value="other">Other</option></select><textarea value={issueForm.notes} onChange={(event) => setIssueForms({ ...issueForms, [leg.id]: { ...issueForm, notes: event.target.value } })} placeholder="Optional note" className="w-32 rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white" /><button type="button" onClick={() => reportIssue(leg.id)} className="rounded border border-amber-500 px-2 py-1 text-xs font-semibold text-amber-700">{leg.status === 'delivery_attempted' ? 'Request cancellation' : 'Report issue'}</button></div>}
                                  {!riderMode && leg.status === 'delivery_attempted' && <button type="button" onClick={() => void confirmAct(`/api/logistics/legs/${leg.id}/cancel`, 'Cancel delivery?', 'This is the final cancellation action.')} className="rounded-lg border border-red-600 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Cancel delivery</button>}
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
