import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { LogisticsRider, PaginatedResponse } from '@/types/logistics';
import type { ErpCapabilities } from '@/types/erp';
import { erpUrl } from '@/utils/erpCapabilities';

type RiderFilters = {
  availability: string;
  type: string;
};

const availabilityOptions = [
  ['all', 'All'],
  ['available', 'Available'],
  ['busy', 'Busy'],
  ['inactive', 'Inactive'],
];

const typeOptions = [
  ['all', 'All Types'],
  ['employee', 'Employee'],
  ['contractor', 'Contractor'],
];

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function availabilityClass(status: string) {
  if (status === 'available') return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
  if (status === 'busy') return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
  return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
}

function activeClass(active: boolean) {
  return active
    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
    : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
}

function RiderCard({ rider }: { rider: LogisticsRider }) {
  const capacity = rider.daily_capacity == null
    ? 'Not set'
    : `${rider.daily_capacity} ${rider.daily_capacity === 1 ? 'stop' : 'stops'}`;

  return (
    <article data-testid={`rider-card-${rider.id}`} className="mx-auto w-full max-w-2xl rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-5">
      <div className="flex items-start gap-3">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-purple-600 font-semibold text-white">
          {rider.name.charAt(0).toUpperCase()}
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="break-words text-base font-semibold leading-6 text-gray-950 dark:text-white">{rider.name}</h2>
          <p className="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{label(rider.rider_type)}</p>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
        <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ${availabilityClass(rider.availability_status)}`}>
          {label(rider.availability_status)}
        </span>
        <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ${activeClass(rider.active)}`}>
          {rider.active ? 'Active' : 'Inactive'}
        </span>
      </div>

      <dl className="mt-4 grid gap-3 border-t border-gray-100 pt-4 dark:border-gray-700 sm:grid-cols-2 sm:gap-x-5">
        <div className="min-w-0">
          <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Phone</dt>
          <dd className="mt-1 break-words text-sm leading-5 text-gray-700 dark:text-gray-300">{rider.phone || 'No phone number'}</dd>
        </div>
        <div>
          <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Daily capacity</dt>
          <dd className="mt-1 text-sm leading-5 text-gray-700 dark:text-gray-300">{capacity}</dd>
        </div>
      </dl>
    </article>
  );
}

export default function Riders() {
  const { riders, filters, auth, erpCapabilities } = usePage<{
    riders: PaginatedResponse<LogisticsRider>;
    filters: RiderFilters;
    auth?: { erpActor?: { ownerMode?: boolean } };
    erpCapabilities?: ErpCapabilities;
  }>().props;
  const ownerMode = auth?.erpActor?.ownerMode === true;

  const updateFilter = (key: keyof RiderFilters, value: string) => {
    const ridersUrl = erpUrl(erpCapabilities, 'GET:erp.logistics.riders')
      ?? (ownerMode ? null : '/erp/logistics/riders');

    if (!ridersUrl) return;

    router.get(ridersUrl, { ...filters, [key]: value, page: 1 }, {
      preserveScroll: true,
      preserveState: true,
    });
  };

  return (
    <AppLayoutERP>
      <Head title="ERP Logistics Riders" />
      <div className="space-y-5 sm:space-y-6">
        <div data-testid="riders-page-intro" className="text-center xl:text-left">
          <h1 className="text-2xl font-bold text-gray-950 dark:text-white">Riders</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">View rider availability and delivery capacity.</p>
        </div>

        <div className="flex flex-col gap-3 sm:gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div data-testid="riders-filter-bar" className="mx-auto grid w-full max-w-md grid-cols-1 gap-3 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:grid-cols-2 sm:p-4 xl:mx-0 xl:w-auto xl:max-w-none xl:flex xl:flex-wrap xl:items-center xl:gap-3 xl:rounded-none xl:border-0 xl:bg-transparent xl:p-0 xl:shadow-none">
            <select
              value={filters.availability}
              onChange={(event) => updateFilter('availability', event.target.value)}
              className="min-h-11 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:w-auto xl:min-h-0 xl:rounded-lg"
              aria-label="Filter riders by availability"
            >
              {availabilityOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
            <select
              value={filters.type}
              onChange={(event) => updateFilter('type', event.target.value)}
              className="min-h-11 w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:w-auto xl:min-h-0 xl:rounded-lg"
              aria-label="Filter riders by type"
            >
              {typeOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
          </div>
        </div>

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 xl:rounded-xl">
          <div data-testid="riders-desktop-table" className="hidden overflow-x-auto xl:block">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Name</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Type</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Phone</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Availability</TableCell>
                  <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Status</TableCell>
                </TableRow>
              </TableHeader>
              <TableBody>
                {riders.data.length === 0 ? (
                  <TableRow>
                    <TableCell className="px-6 py-6 text-sm text-gray-500 dark:text-gray-400">No riders found.</TableCell>
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                    <TableCell className="px-6 py-6" />
                  </TableRow>
                ) : riders.data.map((rider) => (
                  <TableRow key={rider.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <TableCell className="px-6 py-4">
                      <div className="flex items-center space-x-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-purple-600 font-semibold text-white">
                          {rider.name.charAt(0).toUpperCase()}
                        </div>
                        <div className="font-medium text-gray-900 dark:text-white">{rider.name}</div>
                      </div>
                    </TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{label(rider.rider_type)}</TableCell>
                    <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{rider.phone || '-'}</TableCell>
                    <TableCell className="px-6 py-4">
                      <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${availabilityClass(rider.availability_status)}`}>
                        {label(rider.availability_status)}
                      </span>
                    </TableCell>
                    <TableCell className="px-6 py-4">
                      <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${activeClass(rider.active)}`}>
                        {rider.active ? 'Active' : 'Inactive'}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          <div data-testid="riders-mobile-list" className="space-y-3 bg-gray-50 p-3 dark:bg-gray-900/40 sm:space-y-4 sm:p-4 xl:hidden">
            {riders.data.length === 0 ? (
              <div className="mx-auto w-full max-w-2xl rounded-2xl border border-dashed border-gray-300 bg-white px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                No riders found.
              </div>
            ) : riders.data.map((rider) => <RiderCard key={rider.id} rider={rider} />)}
          </div>

          {riders.total > 0 && (
            <div data-testid="riders-pagination" className="flex flex-col items-center gap-3 border-t border-gray-200 px-4 py-4 dark:border-gray-700 sm:px-5 xl:flex-row xl:justify-between xl:gap-0 xl:px-6">
              <div className="text-center text-sm text-gray-700 dark:text-gray-300 xl:text-left">
                Showing <span className="font-medium">{riders.from}</span> to <span className="font-medium">{riders.to}</span> of{' '}
                <span className="font-medium">{riders.total}</span>
              </div>
              <div data-testid="riders-pagination-links" className="flex w-full flex-wrap items-center justify-center gap-2 xl:w-auto xl:justify-end xl:flex-nowrap">
                {riders.links.map((link, index) => (
                  link.url ? (
                    <Link
                      key={`${link.label}-${index}`}
                      href={link.url}
                      preserveScroll
                      preserveState
                      className={`min-h-11 min-w-[44px] rounded-lg px-3 py-2 text-center text-sm font-medium transition-colors xl:min-h-0 ${
                        link.active
                          ? 'bg-blue-600 text-white'
                          : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800'
                      }`}
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ) : (
                    <span
                      key={`${link.label}-${index}`}
                      className="min-h-11 min-w-[44px] rounded-lg border border-gray-200 px-3 py-2 text-center text-sm text-gray-400 dark:border-gray-700 xl:min-h-0"
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
