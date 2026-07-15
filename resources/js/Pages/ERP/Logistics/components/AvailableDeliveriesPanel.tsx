import React from 'react';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import type { TrackingShipmentLeg } from '@/types/logistics';

const sourceLabel = (leg: TrackingShipmentLeg) => leg.shipment?.source_type === 'order'
  ? `Order #${leg.shipment.source_id}`
  : `Leg #${leg.id}`;

type Props = {
  rows: TrackingShipmentLeg[];
  totalRows: number;
  loading?: boolean;
  selectedIds: number[];
  search: string;
  date: string;
  window: string;
  status: string;
  onSearchChange: (value: string) => void;
  onDateChange: (value: string) => void;
  onWindowChange: (value: string) => void;
  onStatusChange: (value: string) => void;
  onToggle: (id: number, checked: boolean) => void;
  onSelectAll: (checked: boolean) => void;
  onClearFilters: () => void;
};

export default function AvailableDeliveriesPanel({
  rows, totalRows, selectedIds, search, date, window, status, loading = false,
  onSearchChange, onDateChange, onWindowChange, onStatusChange,
  onToggle, onSelectAll, onClearFilters,
}: Props) {
  const allSelected = rows.length > 0 && rows.every((leg) => selectedIds.includes(leg.id));

  return <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div className="border-b border-gray-100 p-4 dark:border-gray-700">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="font-bold text-gray-950 dark:text-white">Available deliveries</h2>
          <p className="mt-1 text-sm text-gray-500">Choose the stops to include in this route.</p>
        </div>
        <SlidersHorizontal className="text-gray-400" size={20} />
      </div>
      <label className="relative mt-4 block">
        <span className="sr-only">Search deliveries</span>
        <Search className="absolute left-3 top-3 text-gray-400" size={18} />
        <input aria-label="Search deliveries" value={search} onChange={(event) => onSearchChange(event.target.value)} placeholder="Order, customer, phone, or address" className="min-h-11 w-full rounded-xl border border-gray-300 py-2 pl-10 pr-3 text-sm" />
      </label>
      <div className="mt-3 grid gap-2 sm:grid-cols-3">
        <input aria-label="Delivery date" type="date" value={date} onChange={(event) => onDateChange(event.target.value)} className="min-h-11 rounded-xl border border-gray-300 px-3 text-sm" />
        <select aria-label="Delivery window" value={window} onChange={(event) => onWindowChange(event.target.value)} className="min-h-11 rounded-xl border border-gray-300 px-3 text-sm">
          <option value="morning">Morning</option>
          <option value="afternoon">Afternoon</option>
        </select>
        <select aria-label="Schedule status" value={status} onChange={(event) => onStatusChange(event.target.value)} className="min-h-11 rounded-xl border border-gray-300 px-3 text-sm">
          <option value="all">All statuses</option>
          <option value="unscheduled">Needs scheduling</option>
          <option value="scheduled">Scheduled</option>
        </select>
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
        <div className="flex flex-wrap items-center gap-3">
          <label className="inline-flex min-h-10 items-center gap-2 font-medium text-gray-700 dark:text-gray-200">
            <input aria-label="Select all matching deliveries" type="checkbox" disabled={!rows.length} checked={allSelected} onChange={(event) => onSelectAll(event.target.checked)} />
            Select all matching
          </label>
          <span className="font-semibold text-blue-700">{selectedIds.length} selected</span>
        </div>
        <button type="button" onClick={onClearFilters} className="inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-gray-600 hover:bg-gray-50"><X size={15} />Clear filters</button>
      </div>
    </div>
    <div className="max-h-[36rem] space-y-2 overflow-y-auto p-3">
      {loading && Array.from({ length: 3 }, (_, index) => <div key={index} data-testid="delivery-skeleton" className="h-20 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-700" />)}
      {!loading && rows.map((leg) => {
        const destination = leg.destination_snapshot;
        const scheduled = Boolean(leg.scheduled_delivery_date);
        return <label key={leg.id} className="flex min-h-20 cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3 hover:border-blue-300 hover:bg-blue-50/40 dark:border-gray-700">
          <input type="checkbox" checked={selectedIds.includes(leg.id)} onChange={(event) => onToggle(leg.id, event.target.checked)} className="mt-1" />
          <span className="min-w-0 flex-1">
            <span className="flex flex-wrap items-center gap-2">
              <strong className="text-sm text-gray-950 dark:text-white">{sourceLabel(leg)}</strong>
              <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${scheduled ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}>{scheduled ? 'Scheduled' : 'Needs scheduling'}</span>
            </span>
            <span className="mt-1 block text-sm text-gray-700 dark:text-gray-200">{destination?.name || 'Customer not provided'}</span>
            <span className="block truncate text-xs text-gray-500">{destination?.phone || 'No phone'} · {destination?.address || 'No address'}</span>
          </span>
        </label>;
      })}
      {!loading && !totalRows && <p className="p-6 text-center text-sm text-gray-500">No deliveries ready for batching.</p>}
      {!loading && totalRows > 0 && !rows.length && <p className="p-6 text-center text-sm text-gray-500">No deliveries match your filters.</p>}
    </div>
  </section>;
}
