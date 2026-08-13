import React, { ChangeEvent, FormEvent, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from '../../../components/ui/table';

type AuditOption = {
  value: string;
  label: string;
};

type AuditEntry = {
  id: number;
  event: string;
  event_label: string;
  actor: {
    id: number | null;
    label: string;
    role: string;
  };
  target: {
    id: number | null;
    type: string;
    label: string;
  };
  outcome: string | null;
  source: string;
  ip_address: string | null;
  correlation_id: string | null;
  metadata: Record<string, string | number | boolean | null>;
  occurred_at: string | null;
};

type AuditFilters = {
  event: string;
  actor_id: string | number;
  target_type: string;
  target_id: string | number;
  correlation_id: string;
  date_from: string;
  date_to: string;
  per_page: number;
};

type AuditPagination = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

type PageProps = {
  entries: AuditEntry[];
  filters: Partial<AuditFilters>;
  pagination: AuditPagination;
  event_options: AuditOption[];
  target_type_options: AuditOption[];
};

const emptyFilters = (): AuditFilters => ({
  event: '',
  actor_id: '',
  target_type: '',
  target_id: '',
  correlation_id: '',
  date_from: '',
  date_to: '',
  per_page: 25,
});

const initialFilters = (filters: Partial<AuditFilters>): AuditFilters => ({
  event: String(filters.event ?? ''),
  actor_id: String(filters.actor_id ?? ''),
  target_type: String(filters.target_type ?? ''),
  target_id: String(filters.target_id ?? ''),
  correlation_id: String(filters.correlation_id ?? ''),
  date_from: String(filters.date_from ?? ''),
  date_to: String(filters.date_to ?? ''),
  per_page: Number(filters.per_page ?? 25),
});

const displayValue = (value: string | number | boolean | null | undefined): string => {
  if (value === null || value === undefined || value === '') return 'Unknown';
  return String(value);
};

const formatLabel = (value: string | null | undefined): string => {
  const normalized = String(value ?? '').replace(/[_-]+/g, ' ').trim();
  if (!normalized) return 'Unknown';

  return normalized
    .split(' ')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(' ');
};

const formatDate = (value: string | null): string => {
  if (!value) return 'Unknown';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unknown' : date.toLocaleString();
};

const formatMetadataKey = (key: string): string => formatLabel(key);

const queryParams = (filters: AuditFilters): Record<string, string> => {
  const params: Record<string, string> = {};
  const filterKeys: Array<keyof Omit<AuditFilters, 'per_page'>> = [
    'event',
    'actor_id',
    'target_type',
    'target_id',
    'correlation_id',
    'date_from',
    'date_to',
  ];

  filterKeys.forEach((key) => {
    const value = String(filters[key] ?? '').trim();
    if (value !== '') params[key] = value;
  });

  return params;
};

const MetadataSummary = ({ metadata }: { metadata: AuditEntry['metadata'] }) => {
  const values = Object.entries(metadata).filter(([, value]) => value !== null && value !== '');
  if (values.length === 0) return null;

  return (
    <div className="mt-2 space-y-1 text-xs text-slate-500 dark:text-slate-400">
      {values.map(([key, value]) => (
        <div key={key}>
          <span className="font-medium text-slate-600 dark:text-slate-300">{formatMetadataKey(key)}:</span>{' '}
          {displayValue(value)}
        </div>
      ))}
    </div>
  );
};

export default function PrivilegedAuditHistory() {
  const { entries, filters, pagination, event_options: eventOptions, target_type_options: targetTypeOptions } = usePage<PageProps>().props;
  const [filterForm, setFilterForm] = useState(() => initialFilters(filters));

  const updateFilter = (key: keyof AuditFilters) => (
    event: ChangeEvent<HTMLInputElement | HTMLSelectElement>,
  ) => {
    setFilterForm((previous) => ({ ...previous, [key]: event.target.value }));
  };

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    router.get('/admin/audit', queryParams(filterForm), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const clearFilters = () => {
    const cleared = emptyFilters();
    setFilterForm(cleared);
    router.get('/admin/audit', {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const goToPage = (page: number) => {
    router.get('/admin/audit', { ...queryParams(filterForm), page: String(page) }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  return (
    <AppLayout>
      <Head title="Privileged Audit History" />
      <div className="min-h-screen bg-slate-50 p-4 dark:bg-slate-950 sm:p-6">
        <div className="mx-auto max-w-7xl space-y-6">
          <header>
            <h1 className="text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">Privileged Audit History</h1>
            <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
              Review normalized privileged actions within your authorized scope. Sensitive request data and raw audit properties are not displayed.
            </p>
          </header>

          <section aria-labelledby="audit-filters-heading" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
            <div className="mb-4">
              <h2 id="audit-filters-heading" className="text-lg font-semibold text-slate-900 dark:text-white">Filter history</h2>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Filters are validated and applied inside the server-side visibility boundary.</p>
            </div>
            <form onSubmit={applyFilters} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <label htmlFor="event" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Event</label>
                <select id="event" value={filterForm.event} onChange={updateFilter('event')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                  <option value="">All events</option>
                  {eventOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
              </div>
              <div>
                <label htmlFor="actor_id" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Actor ID</label>
                <input id="actor_id" type="number" min="1" value={filterForm.actor_id} onChange={updateFilter('actor_id')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
              </div>
              <div>
                <label htmlFor="target_type" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Target type</label>
                <select id="target_type" value={filterForm.target_type} onChange={updateFilter('target_type')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                  <option value="">All target types</option>
                  {targetTypeOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
              </div>
              <div>
                <label htmlFor="target_id" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Target ID</label>
                <input id="target_id" type="number" min="1" value={filterForm.target_id} onChange={updateFilter('target_id')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
              </div>
              <div className="sm:col-span-2">
                <label htmlFor="correlation_id" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Correlation ID</label>
                <input id="correlation_id" type="text" inputMode="text" value={filterForm.correlation_id} onChange={updateFilter('correlation_id')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
              </div>
              <div>
                <label htmlFor="date_from" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Date from</label>
                <input id="date_from" type="date" value={filterForm.date_from} onChange={updateFilter('date_from')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
              </div>
              <div>
                <label htmlFor="date_to" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Date to</label>
                <input id="date_to" type="date" value={filterForm.date_to} onChange={updateFilter('date_to')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
              </div>
              <div className="flex items-end gap-3 sm:col-span-2 lg:col-span-4">
                <button type="submit" className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200" aria-label="Apply filters">Apply filters</button>
                <button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Clear filters</button>
              </div>
            </form>
          </section>

          <section aria-labelledby="audit-table-heading" className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-2 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between sm:p-6">
              <div>
                <h2 id="audit-table-heading" className="text-lg font-semibold text-slate-900 dark:text-white">Recorded activity</h2>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{pagination.total.toLocaleString()} visible entr{pagination.total === 1 ? 'y' : 'ies'}</p>
              </div>
              <span className="text-sm text-slate-500 dark:text-slate-400">Newest activity first</span>
            </div>

            <div className="max-w-full overflow-x-auto">
              <Table>
                <TableHeader className="border-b border-slate-200 dark:border-slate-800">
                  <TableRow>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Event</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actor</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Target</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Outcome</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Source</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Correlation</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Occurred</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {entries.length === 0 ? (
                    <TableRow>
                      <TableCell className="px-4 py-12 text-center text-sm text-slate-500">No privileged audit activity found.</TableCell>
                    </TableRow>
                  ) : entries.map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell className="min-w-56 px-4 py-4 align-top text-sm text-slate-900 dark:text-white">
                        <span className="font-semibold">{entry.event_label}</span>
                        <MetadataSummary metadata={entry.metadata} />
                      </TableCell>
                      <TableCell className="min-w-40 px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <div className="font-medium">{entry.actor.label}</div>
                        <div className="mt-1 text-xs text-slate-500">{formatLabel(entry.actor.role)}{entry.actor.id ? ` #${entry.actor.id}` : ''}</div>
                      </TableCell>
                      <TableCell className="min-w-40 px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <div className="font-medium">{entry.target.label}</div>
                        <div className="mt-1 text-xs text-slate-500">{entry.target.type}{entry.target.id ? ` #${entry.target.id}` : ''}</div>
                      </TableCell>
                      <TableCell className="px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">{displayValue(entry.outcome)}</TableCell>
                      <TableCell className="px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <div>{formatLabel(entry.source)}</div>
                        {entry.ip_address && <div className="mt-1 text-xs text-slate-500">{entry.ip_address}</div>}
                      </TableCell>
                      <TableCell className="max-w-56 break-all px-4 py-4 align-top text-xs text-slate-500 dark:text-slate-400">{displayValue(entry.correlation_id)}</TableCell>
                      <TableCell className="min-w-40 whitespace-nowrap px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">{formatDate(entry.occurred_at)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            <div className="flex flex-col gap-3 border-t border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between sm:p-6">
              <span className="text-sm text-slate-500 dark:text-slate-400">Page {pagination.current_page} of {pagination.last_page}</span>
              <div className="flex gap-2">
                <button type="button" disabled={pagination.current_page <= 1} onClick={() => goToPage(pagination.current_page - 1)} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200" aria-label="Previous page">Previous</button>
                <button type="button" disabled={pagination.current_page >= pagination.last_page} onClick={() => goToPage(pagination.current_page + 1)} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-200" aria-label="Next page">Next</button>
              </div>
            </div>
          </section>
        </div>
      </div>
    </AppLayout>
  );
}
