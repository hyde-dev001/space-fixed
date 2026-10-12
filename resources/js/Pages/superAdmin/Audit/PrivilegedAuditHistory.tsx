import React, { ChangeEvent, useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';
import Select from '../../../components/form/Select';
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
  audit_reference: string;
  event: string;
  event_label: string;
  actor: {
    id: number | null;
    type?: string;
    label: string;
    role: string;
  };
  target: {
    id: number | null;
    internal_type?: string;
    type: string;
    label: string;
  };
  outcome: string | null;
  result: { key: string; label: string };
  source: string;
  source_label: string;
  ip_address: string | null;
  correlation_id: string | null;
  metadata: Record<string, string | number | boolean | null>;
  occurred_at: string | null;
};

type AuditFilters = {
  search: string;
  event: string;
  actor_search: string;
  target_search: string;
  actor_id: string | number;
  target_type: string;
  target_id: string | number;
  correlation_id: string;
  result: string;
  source: string;
  ip_address: string;
  sort: string;
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
  result_options: AuditOption[];
  source_options: AuditOption[];
};

const emptyFilters = (): AuditFilters => ({
  search: '',
  event: '',
  actor_search: '',
  target_search: '',
  actor_id: '',
  target_type: '',
  target_id: '',
  correlation_id: '',
  result: '',
  source: '',
  ip_address: '',
  sort: 'newest',
  date_from: '',
  date_to: '',
  per_page: 25,
});

const initialFilters = (filters: Partial<AuditFilters>): AuditFilters => ({
  search: String(filters.search ?? ''),
  event: String(filters.event ?? ''),
  actor_search: String(filters.actor_search ?? ''),
  target_search: String(filters.target_search ?? ''),
  actor_id: String(filters.actor_id ?? ''),
  target_type: String(filters.target_type ?? ''),
  target_id: String(filters.target_id ?? ''),
  correlation_id: String(filters.correlation_id ?? ''),
  result: String(filters.result ?? ''),
  source: String(filters.source ?? ''),
  ip_address: String(filters.ip_address ?? ''),
  sort: String(filters.sort ?? 'newest'),
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

const queryParams = (filters: AuditFilters, page?: number): Record<string, string> => {
  const params: Record<string, string> = {};
  const filterKeys: Array<keyof Omit<AuditFilters, 'per_page'>> = [
    'search',
    'event',
    'actor_search',
    'target_search',
    'actor_id',
    'target_type',
    'target_id',
    'correlation_id',
    'result',
    'source',
    'ip_address',
    'sort',
    'date_from',
    'date_to',
  ];

  filterKeys.forEach((key) => {
    const value = String(filters[key] ?? '').trim();
    if (value !== '' && !(key === 'sort' && value === 'newest')) params[key] = value;
  });

  if (filters.per_page !== 25) params.per_page = String(filters.per_page);

  if (page && page > 1) params.page = String(page);

  return params;
};

const SafeMetadataList = ({ metadata }: { metadata: AuditEntry['metadata'] }) => {
  const values = Object.entries(metadata).filter(([, value]) => value !== null && value !== '');
  if (values.length === 0) return <p className="text-sm text-slate-500">No additional safe metadata was recorded.</p>;

  return (
    <dl className="grid gap-3 sm:grid-cols-2">
      {values.map(([key, value]) => (
        <div key={key} className="min-w-0 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70">
          <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{formatMetadataKey(key)}</dt>
          <dd className="mt-1 break-words text-sm text-slate-800 dark:text-slate-100">{displayValue(value)}</dd>
        </div>
      ))}
    </dl>
  );
};

export default function PrivilegedAuditHistory() {
  const {
    entries,
    filters,
    pagination,
    event_options: eventOptions,
    target_type_options: targetTypeOptions,
    result_options: resultOptions,
    source_options: sourceOptions,
  } = usePage<PageProps>().props;
  const [filterForm, setFilterForm] = useState(() => initialFilters(filters));
  const [selectedEntry, setSelectedEntry] = useState<AuditEntry | null>(null);
  const detailDialogRef = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    setFilterForm(initialFilters(filters));
  }, [filters]);

  useEffect(() => {
    const dialog = detailDialogRef.current;
    if (!selectedEntry || !dialog) return;

    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }, [selectedEntry]);

  const closeDetails = () => {
    const dialog = detailDialogRef.current;
    if (dialog?.open && typeof dialog.close === 'function') dialog.close();
    setSelectedEntry(null);
  };
  const filterTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (filterTimer.current) clearTimeout(filterTimer.current);
  }, []);

  const visitFilters = (next: AuditFilters, immediate = false) => {
    setFilterForm(next);
    if (filterTimer.current) clearTimeout(filterTimer.current);

    const visit = () => router.get('/admin/audit', queryParams(next), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
    if (immediate) {
      visit();
      return;
    }

    filterTimer.current = setTimeout(visit, 250);
  };

  const updateFilter = (key: keyof AuditFilters, immediate = false) => (
    event: ChangeEvent<HTMLInputElement | HTMLSelectElement>,
  ) => {
    visitFilters({ ...filterForm, [key]: event.target.value }, immediate);
  };

  const clearFilters = () => {
    const cleared = emptyFilters();
    if (filterTimer.current) clearTimeout(filterTimer.current);
    setFilterForm(cleared);
    router.get('/admin/audit', {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const goToPage = (page: number) => {
    router.get('/admin/audit', queryParams(filterForm, page), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  return (
    <AppLayout>
      <Head title="Audit Logs" />
      <div className="min-h-screen bg-slate-50 p-4 dark:bg-slate-950 sm:p-6">
        <div className="mx-auto max-w-7xl space-y-6">
          <header>
            <h1 className="text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">Audit Logs</h1>
            <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
              Review important administrator and system activity. Detailed technical references are available without exposing raw audit data.
            </p>
          </header>

          <section aria-labelledby="audit-search-heading" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
            <div className="mb-5">
              <h2 id="audit-search-heading" className="text-lg font-semibold text-slate-900 dark:text-white">Find an activity</h2>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Filter by what happened, who performed it, what was affected, and how it was accessed.</p>
            </div>
            <form onSubmit={(event) => { event.preventDefault(); visitFilters(filterForm, true); }} className="space-y-5">
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div className="sm:col-span-2">
                  <label htmlFor="search" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Search activity</label>
                  <input id="search" type="search" value={filterForm.search} onChange={updateFilter('search')} placeholder="Activity, person, or affected item" className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
                </div>
                <div>
                  <label htmlFor="audit_activity" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Activity</label>
                  <Select id="audit_activity" aria-label="Activity" options={eventOptions} placeholder="All activities" value={filterForm.event} onChange={(value) => setFilterForm((previous) => ({ ...previous, event: value }))} />
                </div>
                <div>
                  <label htmlFor="actor_search" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Performer</label>
                  <input id="actor_search" type="search" value={filterForm.actor_search} onChange={updateFilter('actor_search')} placeholder="Name or email" className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
                </div>
                <div>
                  <label htmlFor="target_search" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Target item</label>
                  <input id="target_search" type="search" value={filterForm.target_search} onChange={updateFilter('target_search')} placeholder="Shop, plan, or record" className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
                </div>
                <div>
                  <label htmlFor="audit_target_type" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Affected area</label>
                  <Select id="audit_target_type" aria-label="Affected area" options={targetTypeOptions} placeholder="All areas" value={filterForm.target_type} onChange={(value) => setFilterForm((previous) => ({ ...previous, target_type: value }))} />
                </div>
                <div>
                  <label htmlFor="result" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Result</label>
                  <Select id="result" aria-label="Result" options={resultOptions} placeholder="All results" value={filterForm.result} onChange={(value) => setFilterForm((previous) => ({ ...previous, result: value }))} />
                </div>
                <div>
                  <label htmlFor="source" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Access method</label>
                  <Select id="source" aria-label="Access method" options={sourceOptions} placeholder="All methods" value={filterForm.source} onChange={(value) => setFilterForm((previous) => ({ ...previous, source: value }))} />
                </div>
                <div>
                  <label htmlFor="sort" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Sort</label>
                  <Select id="sort" aria-label="Sort" options={[{ value: 'newest', label: 'Newest first' }, { value: 'oldest', label: 'Oldest first' }]} value={filterForm.sort} onChange={(value) => setFilterForm((previous) => ({ ...previous, sort: value }))} />
                </div>
                <div>
                  <label htmlFor="audit_date_from" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">From date</label>
                  <input id="audit_date_from" type="date" value={filterForm.date_from} onChange={updateFilter('date_from')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
                </div>
                <div>
                  <label htmlFor="audit_date_to" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">To date</label>
                  <input id="audit_date_to" type="date" value={filterForm.date_to} onChange={updateFilter('date_to')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" />
                </div>
              </div>
              <details className="rounded-lg border border-slate-200 px-4 py-3 dark:border-slate-700">
                <summary className="min-h-11 cursor-pointer py-2 text-sm font-medium text-slate-700 dark:text-slate-200">Advanced technical filters</summary>
                <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                  <div><label htmlFor="audit_actor_id" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Performer ID</label><input id="audit_actor_id" type="number" min="1" value={filterForm.actor_id} onChange={updateFilter('actor_id')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" /></div>
                  <div><label htmlFor="audit_target_id" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Target ID</label><input id="audit_target_id" type="number" min="1" value={filterForm.target_id} onChange={updateFilter('target_id')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" /></div>
                  <div><label htmlFor="audit_correlation_id" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Correlation ID</label><input id="audit_correlation_id" type="text" value={filterForm.correlation_id} onChange={updateFilter('correlation_id')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" /></div>
                  <div><label htmlFor="audit_ip_address" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">IP address</label><input id="audit_ip_address" type="text" inputMode="numeric" value={filterForm.ip_address} onChange={updateFilter('ip_address')} className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white" /></div>
                </div>
              </details>
              <div className="flex flex-wrap items-center gap-3">
                <button type="submit" className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200" aria-label="Apply filters">Apply filters</button>
                <button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Reset filters</button>
              </div>
            </form>
          </section>
          <section aria-labelledby="audit-table-heading" className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-2 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between sm:p-6">
              <div>
                <h2 id="audit-table-heading" className="text-lg font-semibold text-slate-900 dark:text-white">Recorded activity</h2>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{pagination.total.toLocaleString()} visible entr{pagination.total === 1 ? 'y' : 'ies'}</p>
              </div>
              <span className="text-sm text-slate-500 dark:text-slate-400">{filterForm.sort === 'oldest' ? 'Oldest activity first' : 'Newest activity first'}</span>
            </div>

            <div className="max-w-full overflow-x-auto">
              <Table>
                <TableHeader className="border-b border-slate-200 dark:border-slate-800">
                  <TableRow>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Activity</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Performed by</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Affected area</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Affected item</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Result</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Access info</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Date &amp; time</TableCell>
                    <TableCell isHeader className="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {entries.length === 0 ? (
                    <TableRow>
                      <TableCell className="px-4 py-12 text-center text-sm text-slate-500" colSpan={8}>No privileged audit activity found.</TableCell>
                    </TableRow>
                  ) : entries.map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell className="min-w-56 px-4 py-4 align-top text-sm text-slate-900 dark:text-white">
                        <span className="font-semibold">{entry.event_label}</span>
                      </TableCell>
                      <TableCell className="min-w-40 px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <div className="font-medium">{entry.actor.label}</div>
                        <div className="mt-1 text-xs text-slate-500">{formatLabel(entry.actor.role)}</div>
                      </TableCell>
                      <TableCell className="px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        {entry.target.type}
                      </TableCell>
                      <TableCell className="min-w-40 px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <div className="font-medium">{entry.target.label}</div>
                      </TableCell>
                      <TableCell className="px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <span className="inline-flex min-h-7 items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{entry.result.label}</span>
                      </TableCell>
                      <TableCell className="px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">
                        <div>{entry.source_label}</div>
                      </TableCell>
                      <TableCell className="min-w-40 whitespace-nowrap px-4 py-4 align-top text-sm text-slate-700 dark:text-slate-300">{formatDate(entry.occurred_at)}</TableCell>
                      <TableCell className="px-4 py-4 text-right align-top">
                        <button type="button" onClick={() => setSelectedEntry(entry)} className="min-h-11 whitespace-nowrap rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800" aria-label={`View details for ${entry.event_label}`}>View details</button>
                      </TableCell>
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
          {selectedEntry && (
            <dialog
              ref={detailDialogRef}
              aria-labelledby="audit-details-heading"
              onCancel={(event) => { event.preventDefault(); closeDetails(); }}
              className="m-auto max-h-[calc(100%-2rem)] w-[min(52rem,calc(100%-2rem))] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/50 dark:border-slate-700 dark:bg-slate-900 dark:text-white"
            >
              <div className="p-5 sm:p-7">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 pb-5 dark:border-slate-700">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Technical record</p>
                    <h2 id="audit-details-heading" className="mt-1 text-xl font-semibold">Audit details</h2>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{selectedEntry.event_label}</p>
                  </div>
                  <button type="button" onClick={closeDetails} className="min-h-11 min-w-11 rounded-lg border border-slate-300 text-xl text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800" aria-label="Close details">×</button>
                </div>

                <dl className="mt-5 grid gap-3 sm:grid-cols-2">
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Reference</dt><dd className="mt-1 font-mono text-sm">{selectedEntry.audit_reference}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Result</dt><dd className="mt-1 text-sm">{selectedEntry.result.label}{selectedEntry.outcome ? ` · ${selectedEntry.outcome}` : ''}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Performer</dt><dd className="mt-1 text-sm">{selectedEntry.actor.label}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Actor type / role</dt><dd className="mt-1 text-sm">{formatLabel(selectedEntry.actor.type)} · {formatLabel(selectedEntry.actor.role)}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Affected area</dt><dd className="mt-1 text-sm">{selectedEntry.target.type} · {selectedEntry.target.label}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Access method</dt><dd className="mt-1 text-sm">{selectedEntry.source_label} <span className="font-mono text-xs text-slate-500">({selectedEntry.source})</span></dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Occurred at (exact)</dt><dd className="mt-1 break-all font-mono text-sm">{displayValue(selectedEntry.occurred_at)}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">IP address</dt><dd className="mt-1 break-all font-mono text-sm">{selectedEntry.ip_address ?? 'Restricted or not recorded'}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Actor ID</dt><dd className="mt-1 font-mono text-sm">{displayValue(selectedEntry.actor.id)}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Target type / ID</dt><dd className="mt-1 break-all font-mono text-sm">{selectedEntry.target.internal_type ?? formatLabel(selectedEntry.target.type)} · {displayValue(selectedEntry.target.id)}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70 sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Correlation ID</dt><dd className="mt-1 break-all font-mono text-sm">{displayValue(selectedEntry.correlation_id)}</dd></div>
                  <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/70 sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Internal event</dt><dd className="mt-1 break-all font-mono text-sm">{selectedEntry.event}</dd></div>
                </dl>

                <section aria-labelledby="audit-metadata-heading" className="mt-6 space-y-3">
                  <h3 id="audit-metadata-heading" className="text-sm font-semibold">Safe metadata</h3>
                  <SafeMetadataList metadata={selectedEntry.metadata} />
                </section>
                <p className="mt-5 text-xs text-slate-500 dark:text-slate-400">Sensitive request data and raw audit properties are intentionally excluded.</p>
                <div className="mt-6 flex justify-end">
                  <button type="button" onClick={closeDetails} className="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Close</button>
                </div>
              </div>
            </dialog>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
