import React, { useEffect, useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { erpUrl } from '@/utils/erpCapabilities';

type AuditState = Record<string, unknown>;

interface AuditActor {
  id: number;
  name: string;
  email?: string | null;
  role?: string | null;
}

interface AuditTarget {
  type: string | null;
  id: number | null;
  type_label?: string;
  label: string;
}

interface AuditLog {
  id: string;
  source?: string;
  source_id?: number;
  action: string;
  action_label?: string;
  event?: string;
  description: string;
  display_description?: string;
  actor: AuditActor | null;
  target: AuditTarget;
  created_at: string;
  previous_state: AuditState;
  new_state: AuditState;
  reason: string | null;
  reference_id: string | null;
  correlation_id: string | null;
  severity?: string;
}

interface Pagination {
  current_page: number;
  per_page: number;
  from?: number | null;
  to?: number | null;
  total: number;
  last_page: number;
}

interface AuditStats {
  total_logs: number;
  logs_last_24h: number;
  action_counts: Record<string, number>;
}

interface AuditResponse {
  data?: AuditLog[];
  meta?: Pagination;
  stats?: AuditStats;
  filters?: {
    actions?: string[];
  };
  last_updated_at?: string;
  logs?: {
    data?: LegacyActivityLog[];
    current_page?: number;
    per_page?: number;
    total?: number;
    last_page?: number;
  };
}

interface LegacyActivityLog {
  id: number;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  subject_label?: string;
  subject_type_label?: string;
  event_label?: string;
  display_description?: string;
  event: string;
  properties?: Record<string, unknown>;
  created_at: string;
  causer?: AuditActor | null;
}

interface AuditFilters {
  search: string;
  action: string;
  target: string;
  severity: string;
  date_from: string;
  date_to: string;
}

const initialFilters: AuditFilters = {
  search: '',
  action: '',
  target: '',
  severity: '',
  date_from: '',
  date_to: '',
};

function asState(value: unknown): AuditState {
  return value && typeof value === 'object' && !Array.isArray(value)
    ? value as AuditState
    : {};
}

function normalizeLegacyLog(log: LegacyActivityLog): AuditLog {
  const properties = log.properties ?? {};
  const previousState = asState(properties.old_status !== undefined
    ? { status: properties.old_status }
    : properties.old);
  const newState = asState(properties.new_status !== undefined
    ? { status: properties.new_status }
    : properties.attributes);

  return {
    id: `activity:${log.id}`,
    source: 'activity',
    source_id: log.id,
    action: log.event,
    action_label: log.event_label,
    event: log.event,
    description: log.description,
    display_description: log.display_description ?? log.description,
    actor: log.causer ?? null,
    target: {
      type: log.subject_type,
      id: log.subject_id,
      type_label: log.subject_type_label,
      label: log.subject_label && log.subject_label !== 'Record'
        ? log.subject_label
        : log.subject_type_label ?? 'Record',
    },
    created_at: log.created_at,
    previous_state: previousState,
    new_state: newState,
    reason: null,
    reference_id: null,
    correlation_id: null,
    severity: 'info',
  };
}

function normalizeResponse(payload: AuditResponse): {
  logs: AuditLog[];
  pagination: Pagination;
  stats: AuditStats;
  actionOptions: string[];
  lastUpdated: string | null;
} {
  if (Array.isArray(payload.data) && payload.meta) {
    const stats = payload.stats ?? { total_logs: payload.meta.total, logs_last_24h: 0, action_counts: {} };
    return {
      logs: payload.data,
      pagination: payload.meta,
      stats,
      actionOptions: payload.filters?.actions ?? Object.keys(stats.action_counts),
      lastUpdated: payload.last_updated_at ?? null,
    };
  }

  const legacy = payload.logs;
  const total = legacy?.total ?? 0;

  return {
    logs: (legacy?.data ?? []).map(normalizeLegacyLog),
    pagination: {
      current_page: legacy?.current_page ?? 1,
      per_page: legacy?.per_page ?? 10,
      total,
      last_page: legacy?.last_page ?? 1,
    },
    stats: { total_logs: total, logs_last_24h: 0, action_counts: {} },
    actionOptions: Array.from(new Set((legacy?.data ?? []).map((log) => log.event).filter(Boolean))),
    lastUpdated: payload.last_updated_at ?? null,
  };
}

function formatAction(action: string): string {
  return action.replace(/[_.-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function isTechnicalStateKey(key: string): boolean {
  const normalized = key.trim().toLowerCase();
  return normalized === 'id' || normalized.endsWith('_id');
}

function displayStateValue(value: unknown, fieldName = ''): unknown {
  if (Array.isArray(value)) return value.map((item) => displayStateValue(item, fieldName));
  if (!value || typeof value !== 'object') {
    if (typeof value === 'string' && /status|state/i.test(fieldName)) {
      return formatAction(value);
    }

    if (typeof value === 'string' && value.includes('App\\Models\\')) {
      return 'Record';
    }

    return value;
  }

  return Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !isTechnicalStateKey(key))
      .map(([key, nestedValue]) => [key, displayStateValue(nestedValue, key)]),
  );
}

function formatState(state: AuditState): string {
  const entries = Object.entries(state)
    .filter(([key]) => !isTechnicalStateKey(key))
    .map(([key, value]) => [key, displayStateValue(value, key)] as const)
    .filter(([, value]) => !(value && typeof value === 'object' && Object.keys(value).length === 0));
  if (entries.length === 0) return '—';

  return entries
    .map(([key, value]) => `${formatAction(key)}: ${typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—')}`)
    .join(' · ');
}

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unknown time' : date.toLocaleString();
}

function badgeClass(value: string): string {
  if (value.includes('reject') || value.includes('reassign')) {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300';
  }
  if (value.includes('approve') || value.includes('claim')) {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300';
  }
  return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="mt-1 text-sm text-slate-800 dark:text-slate-200">{value}</dd>
    </div>
  );
}

export default function ManagerAuditLogs() {
  const { auth, erpCapabilities } = usePage().props as {
    auth?: { erpActor?: { ownerMode?: boolean } };
    erpCapabilities?: Record<string, string>;
  };
  const ownerMode = auth?.erpActor?.ownerMode === true;
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [pagination, setPagination] = useState<Pagination | null>(null);
  const [stats, setStats] = useState<AuditStats>({ total_logs: 0, logs_last_24h: 0, action_counts: {} });
  const [actionOptions, setActionOptions] = useState<string[]>([]);
  const [filters, setFilters] = useState<AuditFilters>(initialFilters);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);
  const [refreshToken, setRefreshToken] = useState(0);

  const logsUrl = ownerMode
    ? erpUrl(erpCapabilities, 'GET:shop_owner.audit.index')
    : '/api/manager/audit-logs';

  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      if (!logsUrl) {
        setError('Audit log route is not available for this workspace.');
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);
      const params = new URLSearchParams({ page: String(page), per_page: '10' });
      Object.entries(filters).forEach(([key, value]) => {
        if (value.trim() !== '') params.set(key, value.trim());
      });

      try {
        const response = await fetch(`${logsUrl}?${params.toString()}`, {
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Unable to load audit logs.');

        const normalized = normalizeResponse(await response.json() as AuditResponse);
        if (cancelled) return;
        setLogs(normalized.logs);
        setPagination(normalized.pagination);
        setStats(normalized.stats);
        setActionOptions((current) => current.length > 0 ? current : normalized.actionOptions);
        setLastUpdated(normalized.lastUpdated);
      } catch {
        if (cancelled) return;
        setLogs([]);
        setPagination(null);
        setError('Unable to load audit logs. Please retry.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();
    return () => {
      cancelled = true;
    };
  }, [filters, logsUrl, page, refreshToken]);

  const isStale = useMemo(() => {
    if (!lastUpdated) return false;
    const parsed = Date.parse(lastUpdated);
    return !Number.isNaN(parsed) && Date.now() - parsed > 5 * 60 * 1000;
  }, [lastUpdated]);

  const updateFilter = (key: keyof AuditFilters, value: string) => {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  };

  const clearFilters = () => {
    setFilters(initialFilters);
    setPage(1);
  };

  return (
    <AppLayoutERP>
      <Head title="Manager - Audit Logs" />

      <main className="space-y-6 p-4 sm:p-6">
        <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">Review</p>
            <h1 className="mt-1 text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">Audit Logs</h1>
            <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
              Read-only operational history for your authorized shop, including assignments, decisions, and approval changes.
            </p>
          </div>
          <button
            type="button"
            onClick={() => setRefreshToken((value) => value + 1)}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          >
            Refresh
          </button>
        </header>

        {isStale && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200" role="status">
            This snapshot may be stale. Refresh to load the latest audit history.
          </div>
        )}

        <section className="grid gap-4 sm:grid-cols-3" aria-label="Audit summary">
          <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm text-slate-500 dark:text-slate-400">Matching events</p>
            <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{stats.total_logs.toLocaleString()}</p>
          </div>
          <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm text-slate-500 dark:text-slate-400">Last 24 hours</p>
            <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{stats.logs_last_24h.toLocaleString()}</p>
          </div>
          <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm text-slate-500 dark:text-slate-400">Reassignment events</p>
            <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">
              {((stats.action_counts.order_reassigned ?? 0) + (stats.action_counts.repair_reassigned ?? 0)).toLocaleString()}
            </p>
          </div>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900" aria-label="Audit filters">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label className="text-sm font-medium text-slate-700 dark:text-slate-300 sm:col-span-2 lg:col-span-2">
              Search
              <input
                value={filters.search}
                onChange={(event) => updateFilter('search', event.target.value)}
                placeholder="Activity, description, order, or repair reference"
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              />
            </label>
            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
              Activity
              <select
                value={filters.action}
                onChange={(event) => updateFilter('action', event.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              >
                <option value="">All activities</option>
                {actionOptions.map((action) => (
                  <option key={action} value={action}>{formatAction(action)}</option>
                ))}
              </select>
            </label>
            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
              Record / reference
              <input
                value={filters.target}
                onChange={(event) => updateFilter('target', event.target.value)}
                placeholder="Order or repair reference"
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              />
            </label>
            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
              Severity
              <select
                value={filters.severity}
                onChange={(event) => updateFilter('severity', event.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              >
                <option value="">All severities</option>
                <option value="info">Info</option>
                <option value="warning">Warning</option>
                <option value="critical">Critical</option>
              </select>
            </label>
            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
              From
              <input
                type="date"
                value={filters.date_from}
                onChange={(event) => updateFilter('date_from', event.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              />
            </label>
            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
              To
              <input
                type="date"
                value={filters.date_to}
                onChange={(event) => updateFilter('date_to', event.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              />
            </label>
            <div className="flex items-end">
              <button
                type="button"
                onClick={clearFilters}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
              >
                Clear filters
              </button>
            </div>
          </div>
        </section>

        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900" aria-label="Audit event history">
          {loading ? (
            <div className="p-12 text-center text-sm text-slate-500 dark:text-slate-400" role="status">Loading audit history…</div>
          ) : error ? (
            <div className="p-12 text-center">
              <p className="text-sm font-semibold text-red-700 dark:text-red-300">{error}</p>
              <button
                type="button"
                onClick={() => setRefreshToken((value) => value + 1)}
                className="mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                Retry
              </button>
            </div>
          ) : logs.length === 0 ? (
            <div className="p-12 text-center text-sm text-slate-500 dark:text-slate-400">
              No audit events match the selected filters.
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-[920px] w-full text-left">
                  <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                    <tr>
                      <th className="px-4 py-3">When</th>
                      <th className="px-4 py-3">Action</th>
                      <th className="px-4 py-3">Actor</th>
                      <th className="px-4 py-3">Target</th>
                      <th className="px-4 py-3">State change</th>
                      <th className="px-4 py-3">Reason / reference</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                    {logs.map((log) => (
                      <tr key={log.id} className="align-top hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td className="whitespace-nowrap px-4 py-4 text-sm text-slate-600 dark:text-slate-300">{formatDate(log.created_at)}</td>
                        <td className="px-4 py-4">
                          <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClass(log.action)}`}>{log.action_label ?? formatAction(log.action)}</span>
                          <p className="mt-2 max-w-[220px] text-xs text-slate-500 dark:text-slate-400">{log.display_description ?? log.description}</p>
                        </td>
                        <td className="px-4 py-4 text-sm">
                          <p className="font-semibold text-slate-800 dark:text-slate-200">{log.actor?.name ?? 'System / unavailable'}</p>
                          <p className="text-xs text-slate-500 dark:text-slate-400">{log.actor?.role ?? '—'}</p>
                        </td>
                        <td className="px-4 py-4 text-sm">
                          <p className="font-semibold text-slate-800 dark:text-slate-200">{log.target.label}</p>
                          {log.target.type_label && log.target.type_label !== log.target.label && (
                            <p className="text-xs text-slate-500 dark:text-slate-400">{log.target.type_label}</p>
                          )}
                        </td>
                        <td className="px-4 py-4 text-xs text-slate-600 dark:text-slate-300">
                          <p><span className="font-semibold text-slate-500 dark:text-slate-400">Before:</span> {formatState(log.previous_state)}</p>
                          <p className="mt-1"><span className="font-semibold text-slate-500 dark:text-slate-400">After:</span> {formatState(log.new_state)}</p>
                        </td>
                        <td className="max-w-[260px] px-4 py-4 text-xs text-slate-600 dark:text-slate-300">
                          <p>{log.reason ?? 'No reason recorded'}</p>
                          {log.reference_id && <p className="mt-2 break-all"><span className="font-semibold">Ref:</span> {log.reference_id}</p>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {pagination && (
                <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300 sm:flex-row sm:items-center sm:justify-between">
                  <p>
                    Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total.toLocaleString()}
                  </p>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      disabled={page <= 1}
                      onClick={() => setPage((value) => Math.max(1, value - 1))}
                      className="rounded-lg border border-slate-300 px-3 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700"
                    >
                      Previous
                    </button>
                    <span aria-current="page">Page {pagination.current_page} of {pagination.last_page}</span>
                    <button
                      type="button"
                      disabled={page >= pagination.last_page}
                      onClick={() => setPage((value) => value + 1)}
                      className="rounded-lg border border-slate-300 px-3 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700"
                    >
                      Next
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </section>

        <dl className="grid gap-4 text-sm sm:grid-cols-2">
          <Field label="Snapshot" value={lastUpdated ? formatDate(lastUpdated) : 'Not available'} />
          <Field label="Scope" value="Authorized shop / tenant only" />
        </dl>
      </main>
    </AppLayoutERP>
  );
}
