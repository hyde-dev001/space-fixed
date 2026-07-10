import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { LogisticsShipment, PaginatedResponse } from '@/types/logistics';

type ShipmentFilters = {
  status: string;
  purpose: string;
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
  const { shipments, filters, assignableRiders, canAssign } = usePage<{
    shipments: PaginatedResponse<LogisticsShipment>;
    filters: ShipmentFilters;
    assignableRiders: Array<{ id: number; name: string; phone?: string | null }>;
    canAssign: boolean;
  }>().props;
  const [expandedShipmentId, setExpandedShipmentId] = useState<number | null>(null);
  const [selectedRiders, setSelectedRiders] = useState<Record<number, string>>({});
  const [assigningLegId, setAssigningLegId] = useState<number | null>(null);
  const [assignmentError, setAssignmentError] = useState<string | null>(null);

  const updateFilter = (key: keyof ShipmentFilters, value: string) => {
    router.get('/erp/logistics/shipments', { ...filters, [key]: value, page: 1 }, {
      preserveScroll: true,
      preserveState: true,
    });
  };

  const assignRider = async (legId: number) => {
    const riderProfileId = Number(selectedRiders[legId]);
    if (!riderProfileId) return;

    setAssigningLegId(legId);
    setAssignmentError(null);

    try {
      await axios.post(`/api/logistics/legs/${legId}/assign`, {
        assignment_type: 'internal_rider',
        rider_profile_id: riderProfileId,
      });
      router.reload({ only: ['shipments', 'assignableRiders'] });
    } catch {
      setAssignmentError('Unable to assign this rider. Refresh the page and try again.');
    } finally {
      setAssigningLegId(null);
    }
  };

  return (
    <AppLayoutERP>
      <Head title="ERP Logistics Shipments" />
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-950 dark:text-white">Shipments</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">Track delivery legs and fulfillment status.</p>
        </div>

        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-3">
            <select
              value={filters.status}
              onChange={(event) => updateFilter('status', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by status"
            >
              {statusOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
            <select
              value={filters.purpose}
              onChange={(event) => updateFilter('purpose', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter shipments by type"
            >
              {purposeOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
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
                  {canAssign && <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Action</TableCell>}
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
                    {canAssign && (
                      <TableCell className="px-6 py-4">
                        <button
                          type="button"
                          onClick={() => setExpandedShipmentId(expandedShipmentId === shipment.id ? null : shipment.id)}
                          className="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100"
                        >
                          {expandedShipmentId === shipment.id ? 'Close' : 'Assign rider'}
                        </button>
                      </TableCell>
                    )}
                  </TableRow>
                  {canAssign && expandedShipmentId === shipment.id && (
                    <TableRow>
                      <TableCell colSpan={6} className="bg-gray-50 px-6 py-5 dark:bg-gray-900/40">
                        <div className="space-y-3">
                          {(shipment.legs ?? []).map((leg) => {
                            const activeAssignment = leg.assignments?.find((assignment) => ['assigned', 'accepted'].includes(assignment.status));
                            const canAssignLeg = !['delivered', 'cancelled'].includes(leg.status) && !activeAssignment;

                            return (
                              <div key={leg.id} className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700 dark:bg-gray-800">
                                <div>
                                  <p className="text-sm font-semibold text-gray-900 dark:text-white">{label(leg.leg_type)} leg</p>
                                  <p className="text-xs text-gray-500 dark:text-gray-400">{label(leg.status)}</p>
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
                          {assignableRiders.length === 0 && <p className="text-sm text-amber-700">No active available riders. Create or make a logistics rider available first.</p>}
                          {assignmentError && <p className="text-sm text-red-600">{assignmentError}</p>}
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
