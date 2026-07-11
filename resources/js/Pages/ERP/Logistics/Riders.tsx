import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { LogisticsRider, PaginatedResponse } from '@/types/logistics';

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

export default function Riders() {
  const { riders, filters } = usePage<{
    riders: PaginatedResponse<LogisticsRider>;
    filters: RiderFilters;
  }>().props;

  const updateFilter = (key: keyof RiderFilters, value: string) => {
    router.get('/erp/logistics/riders', { ...filters, [key]: value, page: 1 }, {
      preserveScroll: true,
      preserveState: true,
    });
  };

  return (
    <AppLayoutERP>
      <Head title="ERP Logistics Riders" />
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-950 dark:text-white">Riders</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">View rider availability and delivery capacity.</p>
        </div>

        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-wrap items-center gap-3">
            <select
              value={filters.availability}
              onChange={(event) => updateFilter('availability', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter riders by availability"
            >
              {availabilityOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
            <select
              value={filters.type}
              onChange={(event) => updateFilter('type', event.target.value)}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              aria-label="Filter riders by type"
            >
              {typeOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
            </select>
          </div>
        </div>

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
          <div className="overflow-x-auto">
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
                      <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${
                        rider.active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'
                      }`}>
                        {rider.active ? 'Active' : 'Inactive'}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {riders.total > 0 && (
            <div className="flex items-center justify-between border-t border-gray-200 px-6 py-4 dark:border-gray-700">
              <div className="text-sm text-gray-700 dark:text-gray-300">
                Showing <span className="font-medium">{riders.from}</span> to <span className="font-medium">{riders.to}</span> of{' '}
                <span className="font-medium">{riders.total}</span>
              </div>
              <div className="flex items-center gap-2">
                {riders.links.map((link, index) => (
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
