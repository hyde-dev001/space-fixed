import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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

interface AdminManagementProps {
  admins?: Administrator[];
  stats?: Record<string, number>;
}

type ActionState = { key: string; error?: string } | null;

function statusLabel(status: string): string {
  return status.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

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

export default function AdminManagement({ admins = [], stats = {} }: AdminManagementProps) {
  const [filter, setFilter] = useState('all');
  const [search, setSearch] = useState('');
  const [action, setAction] = useState<ActionState>(null);
  const [actionError, setActionError] = useState<string>();

  const visibleAdmins = useMemo(() => {
    const normalizedSearch = search.trim().toLowerCase();
    return admins.filter((admin) => {
      const matchesSearch = !normalizedSearch
        || `${admin.firstName} ${admin.lastName}`.toLowerCase().includes(normalizedSearch)
        || admin.email.toLowerCase().includes(normalizedSearch);
      const matchesFilter = filter === 'all' || admin.status === filter;
      return matchesSearch && matchesFilter;
    });
  }, [admins, filter, search]);

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
    router.patch(`/admin/admins/${admin.id}/role`, { role }, {
      preserveScroll: true,
      onError: (errors) => {
        setActionError(errorMessage(errors));
        setAction({ key, error: errorMessage(errors) });
      },
      onFinish: () => setAction(null),
    });
  };

  const confirmAction = (message: string, callback: () => void) => {
    if (window.confirm(message)) callback();
  };

  return (
    <>
      <Head title="Administrator management" />
      <main className="min-h-screen bg-gray-50 p-6 text-gray-900 dark:bg-gray-900 dark:text-white md:p-8">
        <div className="mx-auto max-w-7xl space-y-8">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-gray-500">Privileged identity lifecycle</p>
              <h1 className="mt-2 text-3xl font-bold">Admin Management</h1>
              <p className="mt-2 text-gray-600 dark:text-gray-400">Manage administrator status, role, setup, and MFA state.</p>
            </div>
            <Link href="/admin/create-admin" className="inline-flex items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
              Invite Administrator
            </Link>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              ['Total administrators', stats.total ?? admins.length],
              ['Active', stats.active ?? admins.filter((admin) => admin.status === 'active').length],
              ['Suspended', stats.suspended ?? admins.filter((admin) => admin.status === 'suspended').length],
              ['Inactive', stats.inactive ?? admins.filter((admin) => admin.status === 'inactive').length],
            ].map(([label, value]) => (
              <div key={label as string} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-800">
                <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
                <p className="mt-2 text-3xl font-bold">{value}</p>
              </div>
            ))}
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-800">
            <div className="flex flex-col gap-4 border-b border-gray-200 p-5 dark:border-gray-700 lg:flex-row lg:items-center lg:justify-between">
              <label className="sr-only" htmlFor="admin-search">Search administrators</label>
              <input id="admin-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search by name or email" className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-gray-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 lg:max-w-sm" />
              <div className="flex flex-wrap gap-2" aria-label="Filter administrators">
                {['all', 'active', 'pending_setup', 'suspended', 'inactive'].map((value) => (
                  <button key={value} type="button" onClick={() => setFilter(value)} className={`rounded-lg px-3 py-2 text-sm font-semibold ${filter === value ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200'}`}>
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
                              <button type="button" disabled={actionBusy} onClick={() => runPostAction(admin, 'resend', `/admin/admins/${admin.id}/setup/resend`)} className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-50 disabled:opacity-50">
                                {action?.key === `${admin.id}:resend` ? 'Sending…' : 'Resend setup'}
                              </button>
                            )}
                            {admin.status === 'active' && (
                              <>
                                <button type="button" title="Suspend Admin" disabled={actionBusy} onClick={() => confirmAction(`Suspend ${admin.firstName} ${admin.lastName}?`, () => runPostAction(admin, 'suspend', `/admin/admins/${admin.id}/suspend`))} className="rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50 disabled:opacity-50">Suspend</button>
                                <button type="button" disabled={actionBusy} onClick={() => confirmAction(`Deactivate ${admin.firstName} ${admin.lastName}?`, () => runPostAction(admin, 'deactivate', `/admin/admins/${admin.id}/deactivate`))} className="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-800 hover:bg-red-50 disabled:opacity-50">Deactivate</button>
                              </>
                            )}
                            {(admin.status === 'suspended' || admin.status === 'inactive') && (
                              <button type="button" disabled={actionBusy} onClick={() => runPostAction(admin, 'activate', `/admin/admins/${admin.id}/activate`)} className="rounded-lg border border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-50">
                                {action?.key === `${admin.id}:activate` ? 'Activating…' : admin.status === 'inactive' ? 'Return to setup' : 'Activate'}
                              </button>
                            )}
                            {admin.status !== 'pending_setup' && (
                              <button type="button" disabled={actionBusy} onClick={() => confirmAction(`Reset MFA for ${admin.firstName} ${admin.lastName}? They will need to enroll again.`, () => runPostAction(admin, 'mfa-reset', `/admin/admins/${admin.id}/mfa/reset`))} className="rounded-lg border border-blue-300 px-3 py-2 text-xs font-semibold text-blue-800 hover:bg-blue-50 disabled:opacity-50">
                                Reset MFA
                              </button>
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
          </div>

          <p className="text-sm text-gray-500 dark:text-gray-400">
            High-risk changes may ask for fresh reauthentication. Finish that step, then deliberately retry the action; no lifecycle mutation is replayed automatically.
          </p>
        </div>
      </main>
    </>
  );
}
