import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';

interface Administrator {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
  role: string;
  status: string;
  mfa_complete?: boolean;
  recovery_code_count?: number;
  createdAt?: string;
  lastLogin?: string | null;
}

interface PaginationMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

interface AdministratorPage {
  data: Administrator[];
  meta?: Partial<PaginationMeta>;
  links?: Record<string, string | null>;
}

interface AdminManagementProps {
  admins?: Administrator[] | AdministratorPage;
  stats?: Record<string, number>;
  filters?: { search?: string | null; role?: string | null; status?: string | null };
}

type ActionState = { key: string; error?: string } | null;

function statusLabel(status: string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

const MetricIcon = ({ kind }: { kind: 'users' | 'active' | 'suspended' | 'inactive' }) => {
  const paths = {
    users: 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m7-10a4 4 0 100-8 4 4 0 000 8zm9 10v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75',
    active: 'M5 13l4 4L19 7',
    suspended: 'M12 9v4m0 4h.01M10.3 3.2L2.6 17a2 2 0 001.75 3h15.3a2 2 0 001.75-3L13.7 3.2a2 2 0 00-3.5 0z',
    inactive: 'M6 6l12 12M6 18L18 6',
  } as const;

  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-6 w-6" aria-hidden="true"><path d={paths[kind]} /></svg>;
};

const ActionIcon = ({ kind }: { kind: 'send' | 'suspend' | 'deactivate' | 'activate' | 'reset' }) => {
  const paths = {
    send: 'M22 2L11 13m0 0l-4 9 4-9 9-4m-9 4L2 7l20-5-5 20-6-9z',
    suspend: 'M12 9v4m0 4h.01M10.3 3.2L2.6 17a2 2 0 001.75 3h15.3a2 2 0 001.75-3L13.7 3.2a2 2 0 00-3.5 0z',
    deactivate: 'M6 6l12 12M6 18L18 6',
    activate: 'M5 13l4 4L19 7',
    reset: 'M3 12a9 9 0 109-9c-2.4 0-4.6.95-6.2 2.5L3 8m0 0V3m0 5h5',
  } as const;

  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden="true"><path d={paths[kind]} /></svg>;
};

const actionIconClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-800 transition hover:bg-gray-100 hover:text-black focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white';

function roleLabel(role: string): string {
  return role === 'super_admin' ? 'Super Admin' : 'Admin';
}

function errorMessage(errors: unknown): string {
  if (!errors || typeof errors !== 'object') return 'The administrator action could not be completed.';
  const values = Object.values(errors as Record<string, unknown>);
  const first = values.find((value) => typeof value === 'string' || (Array.isArray(value) && typeof value[0] === 'string'));
  if (Array.isArray(first)) return first[0] as string;
  return typeof first === 'string' ? first : 'The administrator action could not be completed.';
}

const emptyPage: AdministratorPage = {
  data: [],
  meta: { current_page: 1, from: null, last_page: 1, per_page: 25, to: null, total: 0 },
  links: {},
};

export default function AdminManagement({ admins = [], stats = {}, filters = {} }: AdminManagementProps) {
  const [filter, setFilter] = useState(filters.status || 'all');
  const [roleFilter, setRoleFilter] = useState(filters.role || 'all');
  const [search, setSearch] = useState(filters.search || '');
  const [action, setAction] = useState<ActionState>(null);
  const [actionError, setActionError] = useState<string>();
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const isServerPaginated = !Array.isArray(admins);
  const adminPage: AdministratorPage = isServerPaginated
    ? (admins as AdministratorPage)
    : { ...emptyPage, data: admins as Administrator[] };
  const pageMeta = {
    ...emptyPage.meta,
    ...(adminPage.meta ?? {}),
  } as PaginationMeta;
  const visibleAdmins = isServerPaginated
    ? adminPage.data
    : (admins as Administrator[]).filter((admin) => {
      const normalizedSearch = search.trim().toLowerCase();
      return (!normalizedSearch
        || `${admin.firstName} ${admin.lastName}`.toLowerCase().includes(normalizedSearch)
        || admin.email.toLowerCase().includes(normalizedSearch))
        && (filter === 'all' || admin.status === filter);
    });

  const visitAdminPage = (nextSearch = search, nextFilter = filter, page = 1, nextRole = roleFilter) => {
    const params: Record<string, string | number> = { page };
    if (nextSearch.trim()) params.search = nextSearch.trim();
    if (nextFilter !== 'all') params.status = nextFilter;
    if (nextRole !== 'all') params.role = nextRole;

    router.get('/admin/administrators', params, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const runPostAction = (admin: Administrator, actionName: string, path: string) => {
    const key = `${admin.id}:${actionName}`;
    if (action?.key === key) return;
    setActionError(undefined);
    setAction({ key });
    router.post(path, {}, {
      preserveScroll: true,
      onError: (errors) => {
        setActionError(errorMessage(errors));
        setAction({ key, error: errorMessage(errors) });
      },
      onFinish: () => setAction(null),
    });
  };

  const runPatchAction = (admin: Administrator, role: string) => {
    const key = `${admin.id}:role`;
    setActionError(undefined);
    setAction({ key });
    router.patch(`/admin/administrators/${admin.id}/role`, { role }, {
      preserveScroll: true,
      onError: (errors) => {
        setActionError(errorMessage(errors));
        setAction({ key, error: errorMessage(errors) });
      },
      onFinish: () => setAction(null),
    });
  };

  const confirmAction = async (message: string, callback: () => void) => {
    const result = await Swal.fire({
      title: 'Confirm action', text: message, icon: 'warning', showCancelButton: true,
      confirmButtonColor: '#111827', cancelButtonColor: '#6b7280',
      confirmButtonText: 'Continue', cancelButtonText: 'Cancel',
    });
    if (result.isConfirmed) callback();
  };

  return (
    <AppLayout>
      <Head title="Administrator management" />
      <main className="min-h-screen bg-white p-6 text-gray-900 dark:bg-gray-900 dark:text-white md:p-8">
        <div className="mx-auto max-w-7xl space-y-8">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-gray-500">Privileged identity lifecycle</p>
              <h1 className="mt-2 text-3xl font-bold">Admin Management</h1>
              <p className="mt-2 text-gray-600 dark:text-gray-400">Manage administrator status, role, setup, and MFA state.</p>
            </div>
            <Link href="/admin/administrators/create" className="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
              Invite Administrator
            </Link>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              ['Total administrators', stats.total ?? pageMeta.total, 'users'],
              ['Active', stats.active ?? visibleAdmins.filter((admin) => admin.status === 'active').length, 'active'],
              ['Suspended', stats.suspended ?? visibleAdmins.filter((admin) => admin.status === 'suspended').length, 'suspended'],
              ['Inactive', stats.inactive ?? visibleAdmins.filter((admin) => admin.status === 'inactive').length, 'inactive'],
            ].map(([label, value, kind]) => (
              <div key={label as string} className="metrics-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300 hover:shadow-md dark:border-gray-800 dark:bg-gray-800 dark:hover:border-gray-700">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-gray-200 bg-gray-100 text-gray-900 dark:border-gray-700 dark:bg-gray-700 dark:text-gray-100">
                    <MetricIcon kind={kind as 'users' | 'active' | 'suspended' | 'inactive'} />
                  </div>
                  <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">Snapshot</span>
                </div>
                <p className="mt-5 text-sm font-medium text-gray-500 dark:text-gray-400">{label}</p>
                <p className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{value}</p>
              </div>
            ))}
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-800">
            <div className="flex flex-col gap-4 border-b border-gray-200 p-5 dark:border-gray-700 lg:flex-row lg:items-center lg:justify-between">
              <label className="sr-only" htmlFor="admin-search">Search administrators</label>
              <input id="admin-search" type="search" value={search} onChange={(event) => {
                const value = event.target.value;
                setSearch(value);
                if (searchTimer.current) clearTimeout(searchTimer.current);
                searchTimer.current = setTimeout(() => visitAdminPage(value, filter, 1, roleFilter), 250);
              }} placeholder="Search by name or email" className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-gray-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 lg:max-w-sm" />
              <label className="sr-only" htmlFor="admin-role-filter">Filter administrators by role</label>
              <select id="admin-role-filter" value={roleFilter} onChange={(event) => {
                setRoleFilter(event.target.value);
                visitAdminPage(search, filter, 1, event.target.value);
              }} className="rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                <option value="all">All roles</option>
                <option value="admin">Admin</option>
                <option value="super_admin">Super Admin</option>
              </select>
              <div className="flex flex-wrap gap-2" aria-label="Filter administrators">
                {['all', 'active', 'pending_setup', 'suspended', 'inactive'].map((value) => (
                  <button key={value} type="button" onClick={() => {
                    setFilter(value);
                    visitAdminPage(search, value, 1, roleFilter);
                  }} className={`rounded-lg px-3 py-2 text-sm font-semibold ${filter === value ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200'}`}>
                    {value === 'all' ? 'All' : statusLabel(value)}
                  </button>
                ))}
              </div>
            </div>

            {actionError && <div className="m-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{actionError}</div>}

            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-left text-sm">
                <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900/50 dark:text-gray-400">
                  <tr>
                    <th className="px-5 py-4">Administrator</th>
                    <th className="px-5 py-4">Status</th>
                    <th className="px-5 py-4">Role</th>
                    <th className="px-5 py-4">MFA and recovery</th>
                    <th className="px-5 py-4">Lifecycle actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                  {visibleAdmins.map((admin) => {
                    const actionBusy = action?.key.startsWith(`${admin.id}:`);
                    const mfaComplete = admin.mfa_complete === true;
                    const recoveryCount = Number.isFinite(admin.recovery_code_count) ? admin.recovery_code_count : 0;

                    return (
                      <tr key={admin.id}>
                        <td className="px-5 py-5 align-top">
                          <p className="font-semibold">{admin.firstName} {admin.lastName}</p>
                          <p className="mt-1 text-gray-500 dark:text-gray-400">{admin.email}</p>
                        </td>
                        <td className="px-5 py-5 align-top">
                          <span className="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">{statusLabel(admin.status)}</span>
                        </td>
                        <td className="px-5 py-5 align-top">
                          <label className="sr-only" htmlFor={`admin-role-${admin.id}`}>Role for {admin.email}</label>
                          <select id={`admin-role-${admin.id}`} value={admin.role} onChange={(event) => runPatchAction(admin, event.target.value)} disabled={actionBusy} className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                          </select>
                          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">Current: {roleLabel(admin.role)}</p>
                        </td>
                        <td className="px-5 py-5 align-top">
                          <p className={mfaComplete ? 'font-semibold text-emerald-700' : 'font-semibold text-amber-700'}>{mfaComplete ? 'MFA enabled' : 'MFA setup required'}</p>
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{recoveryCount} recovery codes remaining</p>
                        </td>
                        <td className="px-5 py-5 align-top">
                          <div className="flex max-w-xs flex-wrap gap-2">
                            {admin.status === 'pending_setup' && (
                              <button type="button" aria-label={`Resend setup for ${admin.firstName} ${admin.lastName}`} title="Resend setup" disabled={actionBusy} onClick={() => runPostAction(admin, 'resend', `/admin/administrators/${admin.id}/setup/resend`)} className={actionIconClass}>
                                <ActionIcon kind="send" />
                              </button>
                            )}
                            {admin.status === 'active' && (
                              <>
                                <button type="button" aria-label={`Suspend ${admin.firstName} ${admin.lastName}`} title="Suspend Admin" disabled={actionBusy} onClick={() => void confirmAction(`Suspend ${admin.firstName} ${admin.lastName}?`, () => runPostAction(admin, 'suspend', `/admin/administrators/${admin.id}/suspend`))} className={actionIconClass}><ActionIcon kind="suspend" /></button>
                                <button type="button" aria-label={`Deactivate ${admin.firstName} ${admin.lastName}`} title="Deactivate administrator" disabled={actionBusy} onClick={() => void confirmAction(`Deactivate ${admin.firstName} ${admin.lastName}?`, () => runPostAction(admin, 'deactivate', `/admin/administrators/${admin.id}/deactivate`))} className={actionIconClass}><ActionIcon kind="deactivate" /></button>
                              </>
                            )}
                            {(admin.status === 'suspended' || admin.status === 'inactive') && (
                              <button type="button" aria-label={`${admin.status === 'inactive' ? 'Return to setup' : 'Activate'} ${admin.firstName} ${admin.lastName}`} title={admin.status === 'inactive' ? 'Return to setup' : 'Activate'} disabled={actionBusy} onClick={() => runPostAction(admin, 'activate', `/admin/administrators/${admin.id}/activate`)} className={actionIconClass}><ActionIcon kind="activate" /></button>
                            )}
                            {admin.status !== 'pending_setup' && (
                              <button type="button" aria-label={`Reset MFA for ${admin.firstName} ${admin.lastName}`} title="Reset MFA" disabled={actionBusy} onClick={() => void confirmAction(`Reset MFA for ${admin.firstName} ${admin.lastName}? They will need to enroll again.`, () => runPostAction(admin, 'mfa-reset', `/admin/administrators/${admin.id}/mfa/reset`))} className={actionIconClass}><ActionIcon kind="reset" /></button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              {visibleAdmins.length === 0 && <p className="p-8 text-center text-sm text-gray-500">No administrators match this filter.</p>}
            </div>
            {pageMeta.total > 0 && (
              <div className="flex items-center justify-between border-t border-gray-200 px-5 py-4 text-sm dark:border-gray-700">
                <span className="text-gray-500 dark:text-gray-400">
                  Showing {pageMeta.from ?? 0} to {pageMeta.to ?? 0} of {pageMeta.total}
                </span>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    aria-label="Previous page"
                    disabled={pageMeta.current_page <= 1}
                    onClick={() => visitAdminPage(search, filter, pageMeta.current_page - 1, roleFilter)}
                    className="rounded-lg border border-gray-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    Previous
                  </button>
                  <span aria-label="Current page">Page {pageMeta.current_page} of {pageMeta.last_page}</span>
                  <button
                    type="button"
                    aria-label="Next page"
                    disabled={pageMeta.current_page >= pageMeta.last_page}
                    onClick={() => visitAdminPage(search, filter, pageMeta.current_page + 1, roleFilter)}
                    className="rounded-lg border border-gray-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </div>

          <p className="text-sm text-gray-500 dark:text-gray-400">
            High-risk changes may ask for fresh reauthentication. Finish that step, then deliberately retry the action; no lifecycle mutation is replayed automatically.
          </p>
        </div>
      </main>
    </AppLayout>
  );
}
