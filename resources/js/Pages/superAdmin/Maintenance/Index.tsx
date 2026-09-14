import React, { FormEvent, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';
import Select from '../../../components/form/Select';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../../components/ui/table';

type MaintenanceRecord = {
  id: number;
  title: string;
  public_message: string;
  internal_note: string | null;
  status: 'draft' | 'scheduled' | 'active' | 'ended' | 'cancelled';
  state: string;
  starts_at: string | null;
  ends_at: string | null;
  activated_at: string | null;
  ended_at: string | null;
  cancelled_at: string | null;
  notify_before_minutes: number;
  transaction_freeze_minutes: number | null;
  progress_stage: string | null;
  public_update_message: string | null;
  public_update_updated_at: string | null;
  version: number;
};

type Filters = {
  status: string;
  search: string;
  date_from: string;
  date_to: string;
  per_page: number;
};

type Pagination = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

type PageProps = {
  current: MaintenanceRecord | null;
  upcoming: MaintenanceRecord | null;
  history: MaintenanceRecord[];
  filters: Partial<Filters>;
  pagination: Pagination;
  status_counts: Record<string, number>;
  can_manage: boolean;
};

const statusOptions = [
  { value: 'draft', label: 'Draft' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'active', label: 'Active' },
  { value: 'ended', label: 'Ended' },
  { value: 'cancelled', label: 'Cancelled' },
];

const emptyFilters = (): Filters => ({ status: '', search: '', date_from: '', date_to: '', per_page: 25 });

const initialFilters = (filters: Partial<Filters>): Filters => ({
  status: String(filters.status ?? ''),
  search: String(filters.search ?? ''),
  date_from: String(filters.date_from ?? ''),
  date_to: String(filters.date_to ?? ''),
  per_page: Number(filters.per_page ?? 25),
});

const queryParams = (filters: Filters, page?: number): Record<string, string> => {
  const params: Record<string, string> = {};
  (['status', 'search', 'date_from', 'date_to'] as const).forEach((key) => {
    if (filters[key].trim() !== '') params[key] = filters[key].trim();
  });
  if (filters.per_page !== 25) params.per_page = String(filters.per_page);
  if (page && page > 1) params.page = String(page);
  return params;
};

const formatDate = (value: string | null): string => {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });
};

const label = (value: string | null | undefined): string => {
  if (!value) return 'Operational';
  return value.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
};

const statusClasses = (status: string): string => {
  if (status === 'active') return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
  if (status === 'scheduled') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
  if (status === 'ended') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
  if (status === 'cancelled') return 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
  return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';
};

const manilaInputToUtc = (value: string): string => {
  const date = new Date(`${value}:00+08:00`);
  return date.toISOString();
};

const StatusBadge = ({ value }: { value: string }) => (
  <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusClasses(value)}`}>
    {label(value)}
  </span>
);

export default function MaintenanceIndex() {
  const { current, upcoming, history, filters, pagination, status_counts: statusCounts, can_manage: canManage } = usePage<PageProps>().props;
  const [filterForm, setFilterForm] = useState(() => initialFilters(filters));
  const [startNowForm, setStartNowForm] = useState({ title: '', public_message: '', ends_at: '' });
  const [extension, setExtension] = useState('');
  const [publicUpdate, setPublicUpdate] = useState(current?.public_update_message ?? '');

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    router.get('/admin/maintenance', queryParams(filterForm), { preserveState: true, preserveScroll: true, replace: true });
  };

  const clearFilters = () => {
    const cleared = emptyFilters();
    setFilterForm(cleared);
    router.get('/admin/maintenance', {}, { preserveState: true, preserveScroll: true, replace: true });
  };

  const goToPage = (page: number) => {
    router.get('/admin/maintenance', queryParams(filterForm, page), { preserveState: true, preserveScroll: true, replace: true });
  };

  const filterByStatus = (status: string) => {
    const next = { ...filterForm, status };
    setFilterForm(next);
    router.get('/admin/maintenance', queryParams(next), { preserveState: true, preserveScroll: true, replace: true });
  };

  const startNow = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!startNowForm.ends_at || !window.confirm('Start platform maintenance now?')) return;
    router.post('/admin/maintenance/start-now', {
      ...startNowForm,
      ends_at: manilaInputToUtc(startNowForm.ends_at),
    }, { preserveScroll: true });
  };

  const postCommand = (path: string, payload: Record<string, string | number> = {}) => {
    if (!current) return;
    router.post(path, { ...payload, version: current.version }, { preserveScroll: true });
  };

  return (
    <AppLayout>
      <Head title="System Maintenance" />
      <div className="space-y-8">
        <header className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">System Maintenance</h1>
            <p className="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
              Schedule and monitor the global platform maintenance lifecycle. Times are shown in Asia/Manila.
            </p>
          </div>
          <span className="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            {canManage ? 'Super Admin controls enabled' : 'Read-only access'}
          </span>
        </header>

        <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6" aria-label="Maintenance status summary">
          {[
            ['operational', 'Operational'],
            ['scheduled', 'Scheduled'],
            ['active', 'Active'],
            ['ended', 'Ended'],
            ['cancelled', 'Cancelled'],
          ].map(([value, title]) => (
            <button
              key={value}
              type="button"
              onClick={() => filterByStatus(value === 'operational' ? '' : value)}
              className="rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-800 dark:bg-white/[0.03]"
            >
              <p className="text-sm font-medium text-gray-500 dark:text-gray-400">{title}</p>
              <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{Number(statusCounts?.[value] ?? 0).toLocaleString()}</p>
            </button>
          ))}
        </section>

        <section className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
          <article className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Current platform status</p>
                <h2 className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{current ? current.title : 'Operational'}</h2>
              </div>
              <StatusBadge value={current?.state ?? 'operational'} />
            </div>
            {current ? (
              <div className="mt-5 grid gap-4 text-sm text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                <div><span className="font-semibold text-gray-900 dark:text-white">Effective state:</span> {label(current.state)}</div>
                <div><span className="font-semibold text-gray-900 dark:text-white">Persisted status:</span> {label(current.status)}</div>
                <div><span className="font-semibold text-gray-900 dark:text-white">Starts:</span> {formatDate(current.starts_at)}</div>
                <div><span className="font-semibold text-gray-900 dark:text-white">Expected end:</span> {formatDate(current.ends_at)}</div>
                <div><span className="font-semibold text-gray-900 dark:text-white">Warning:</span> {current.notify_before_minutes} minutes</div>
                <div><span className="font-semibold text-gray-900 dark:text-white">Freeze:</span> {current.transaction_freeze_minutes ? `${current.transaction_freeze_minutes} minutes` : 'Disabled'}</div>
                {current.progress_stage && <div><span className="font-semibold text-gray-900 dark:text-white">Progress:</span> {current.progress_stage}</div>}
                {current.public_update_message && <div className="sm:col-span-2"><span className="font-semibold text-gray-900 dark:text-white">Latest public update:</span> {current.public_update_message}</div>}
                {current.internal_note && <div className="sm:col-span-2"><span className="font-semibold text-gray-900 dark:text-white">Internal note:</span> {current.internal_note}</div>}
              </div>
            ) : (
              <p className="mt-5 text-sm text-gray-600 dark:text-gray-300">No scheduled or active maintenance window is currently selected.</p>
            )}
            <p className="mt-5 text-xs text-gray-500 dark:text-gray-400">The server selects Active first, then the nearest warned Scheduled window, then the nearest future Scheduled window.</p>
          </article>

          <article className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Next scheduled window</p>
            <h2 className="mt-1 text-xl font-bold text-gray-900 dark:text-white">{upcoming?.title ?? 'None scheduled'}</h2>
            {upcoming ? (
              <dl className="mt-5 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                <div className="flex justify-between gap-4"><dt>Starts</dt><dd className="font-medium text-gray-900 dark:text-white">{formatDate(upcoming.starts_at)}</dd></div>
                <div className="flex justify-between gap-4"><dt>Expected end</dt><dd className="font-medium text-gray-900 dark:text-white">{formatDate(upcoming.ends_at)}</dd></div>
                <div className="flex justify-between gap-4"><dt>Warning</dt><dd className="font-medium text-gray-900 dark:text-white">{upcoming.notify_before_minutes} minutes</dd></div>
              </dl>
            ) : <p className="mt-5 text-sm text-gray-600 dark:text-gray-300">Create a draft and schedule a non-overlapping window when needed.</p>}
          </article>
        </section>

        {canManage && (
          <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="maintenance-controls-heading">
            <div className="mb-5">
              <h2 id="maintenance-controls-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Maintenance controls</h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">High-impact actions remain server-authorized and Start Now/End require recent reauthentication.</p>
            </div>
            <form onSubmit={startNow} className="grid gap-4 md:grid-cols-4">
              <div><label htmlFor="start-title" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Emergency title</label><input id="start-title" required value={startNowForm.title} onChange={(event) => setStartNowForm({ ...startNowForm, title: event.target.value })} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></div>
              <div><label htmlFor="start-message" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Public message</label><input id="start-message" required value={startNowForm.public_message} onChange={(event) => setStartNowForm({ ...startNowForm, public_message: event.target.value })} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></div>
              <div><label htmlFor="start-end" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Estimated end (Manila)</label><input id="start-end" type="datetime-local" required value={startNowForm.ends_at} onChange={(event) => setStartNowForm({ ...startNowForm, ends_at: event.target.value })} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></div>
              <div className="flex items-end"><button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">Start maintenance now</button></div>
            </form>
            {current?.state === 'scheduled' && <div className="mt-5 flex flex-wrap gap-3"><button type="button" onClick={() => postCommand(`/admin/maintenance/${current.id}/start`)} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-gray-900">Activate scheduled window</button><button type="button" onClick={() => postCommand(`/admin/maintenance/${current.id}/cancel`)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200">Cancel scheduled window</button></div>}
            {current?.state === 'active' && <div className="mt-5 grid gap-4 md:grid-cols-3"><div><label htmlFor="extend-end" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Extend ETA (Manila)</label><input id="extend-end" type="datetime-local" value={extension} onChange={(event) => setExtension(event.target.value)} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></div><div className="flex items-end"><button type="button" disabled={!extension} onClick={() => postCommand(`/admin/maintenance/${current.id}/extend`, { ends_at: manilaInputToUtc(extension) })} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-200">Extend ETA</button></div><div className="flex items-end"><button type="button" onClick={() => window.confirm('End platform maintenance now?') && postCommand(`/admin/maintenance/${current.id}/end`)} className="min-h-11 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">End maintenance now</button></div></div>}
            {current && (current.state === 'scheduled' || current.state === 'active') && <div className="mt-5 grid gap-4 md:grid-cols-2"><div><label htmlFor="progress" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Progress stage</label><Select id="progress" aria-label="Progress stage" options={[{ value: 'Maintenance Starting', label: 'Maintenance Starting' }, { value: 'Maintenance in Progress', label: 'Maintenance in Progress' }, { value: 'Final Checks', label: 'Final Checks' }]} value={current.progress_stage ?? ''} onChange={(value) => postCommand(`/admin/maintenance/${current.id}/progress`, { progress_stage: value })} placeholder="Choose progress stage" /></div><div><label htmlFor="public-update" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Public update</label><div className="flex gap-2"><input id="public-update" value={publicUpdate} onChange={(event) => setPublicUpdate(event.target.value)} className="min-h-11 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" /><button type="button" onClick={() => postCommand(`/admin/maintenance/${current.id}/public-update`, { public_update_message: publicUpdate })} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200">Publish</button></div></div></div>}
          </section>
        )}

        <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="maintenance-filters-heading">
          <div className="mb-5"><h2 id="maintenance-filters-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Filter history</h2><p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Filters are validated and applied server-side; the current summary remains independent of the table filter.</p></div>
          <form onSubmit={applyFilters} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div><label htmlFor="maintenance-status" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label><Select id="maintenance-status" aria-label="Status" options={statusOptions} value={filterForm.status} onChange={(value) => setFilterForm({ ...filterForm, status: value })} placeholder="All statuses" /></div>
            <div><label htmlFor="maintenance-search" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Text search</label><input id="maintenance-search" value={filterForm.search} onChange={(event) => setFilterForm({ ...filterForm, search: event.target.value })} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white" placeholder="Title or message" /></div>
            <div><label htmlFor="maintenance-date-from" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date from</label><input id="maintenance-date-from" type="date" value={filterForm.date_from} onChange={(event) => setFilterForm({ ...filterForm, date_from: event.target.value })} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></div>
            <div><label htmlFor="maintenance-date-to" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Date to</label><input id="maintenance-date-to" type="date" value={filterForm.date_to} onChange={(event) => setFilterForm({ ...filterForm, date_to: event.target.value })} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white" /></div>
            <div className="flex items-end gap-3 sm:col-span-2 lg:col-span-5"><button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-gray-900">Apply filters</button><button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200">Clear filters</button></div>
          </form>
        </section>

        <section className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="maintenance-history-heading">
          <div className="flex flex-col gap-2 border-b border-gray-200 p-6 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="maintenance-history-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Maintenance history</h2><p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{pagination.total.toLocaleString()} record{pagination.total === 1 ? '' : 's'} · newest first</p></div><span className="text-xs text-gray-500 dark:text-gray-400">Server-filtered results</span></div>
          <div className="max-w-full overflow-x-auto"><Table><TableHeader><TableRow><TableCell isHeader className="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Window</TableCell><TableCell isHeader className="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</TableCell><TableCell isHeader className="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Schedule</TableCell><TableCell isHeader className="whitespace-nowrap px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Public update</TableCell></TableRow></TableHeader><TableBody className="divide-y divide-gray-100 dark:divide-gray-800">{history.length === 0 ? <TableRow><TableCell className="px-6 py-12 text-center text-sm text-gray-500">No maintenance history matches these filters.</TableCell></TableRow> : history.map((windowRecord) => <TableRow key={windowRecord.id}><TableCell className="min-w-64 px-6 py-4 align-top"><div className="font-semibold text-gray-900 dark:text-white">{windowRecord.title}</div><div className="mt-1 text-xs text-gray-500">#{windowRecord.id} · version {windowRecord.version}</div><div className="mt-2 text-sm text-gray-600 dark:text-gray-300">{windowRecord.public_message}</div></TableCell><TableCell className="px-6 py-4 align-top"><StatusBadge value={windowRecord.state} /><div className="mt-2 text-xs text-gray-500">Persisted: {label(windowRecord.status)}</div></TableCell><TableCell className="min-w-56 px-6 py-4 align-top text-sm text-gray-600 dark:text-gray-300"><div><span className="font-medium text-gray-900 dark:text-white">Start:</span> {formatDate(windowRecord.starts_at)}</div><div className="mt-1"><span className="font-medium text-gray-900 dark:text-white">End:</span> {formatDate(windowRecord.ends_at)}</div></TableCell><TableCell className="min-w-56 px-6 py-4 align-top text-sm text-gray-600 dark:text-gray-300">{windowRecord.public_update_message ?? '—'}{windowRecord.public_update_updated_at && <div className="mt-1 text-xs text-gray-500">{formatDate(windowRecord.public_update_updated_at)}</div>}</TableCell></TableRow>)}</TableBody></Table></div>
          {pagination.last_page > 1 && <div className="flex items-center justify-between border-t border-gray-200 p-4 dark:border-gray-800"><button type="button" disabled={pagination.current_page <= 1} onClick={() => goToPage(pagination.current_page - 1)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:opacity-40 dark:border-gray-700 dark:text-gray-200">Previous</button><span className="text-sm text-gray-500 dark:text-gray-400">Page {pagination.current_page} of {pagination.last_page}</span><button type="button" disabled={pagination.current_page >= pagination.last_page} onClick={() => goToPage(pagination.current_page + 1)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:opacity-40 dark:border-gray-700 dark:text-gray-200">Next</button></div>}
        </section>
      </div>
    </AppLayout>
  );
}
