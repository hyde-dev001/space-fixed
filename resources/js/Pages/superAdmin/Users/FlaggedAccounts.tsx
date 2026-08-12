import React, { useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';
import { UserIcon } from '../../../icons';
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from '../../../components/ui/table';

interface ReviewSnapshot {
  type?: string;
  rating?: number;
  comment?: string;
  images?: string[];
  createdAt?: string;
}

interface FlaggedAccount {
  id: string;
  username: string;
  email: string;
  flaggedReason: string;
  flaggedDate: string;
  status: string;
  reviewType?: string;
  reviewSnapshot?: ReviewSnapshot;
  reportNotes?: string;
  reportedBy?: string;
  adminNotes?: string;
}

interface PageProps {
  flaggedAccounts: FlaggedAccount[];
  [key: string]: unknown;
}

const STATUS_LABELS: Record<string, string> = {
  pending_review: 'Pending review',
  under_investigation: 'Under investigation',
  dismissed: 'Dismissed',
  account_suspended: 'Account suspended',
};

const FILTER_OPTIONS = [
  { label: 'All', value: 'all' },
  { label: 'Pending review', value: 'pending_review' },
  { label: 'Under investigation', value: 'under_investigation' },
  { label: 'Dismissed', value: 'dismissed' },
  { label: 'Account suspended', value: 'account_suspended' },
];

const statusLabel = (status: string): string => STATUS_LABELS[status] ?? status;

const statusColor = (status: string): string => {
  switch (status) {
    case 'pending_review':
      return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
    case 'under_investigation':
      return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
    case 'dismissed':
      return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
    case 'account_suspended':
      return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
    default:
      return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
  }
};

const errorMessage = (error: unknown): string => {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const response = (error as { response?: { data?: { message?: unknown } } }).response;
    if (typeof response?.data?.message === 'string' && response.data.message.trim() !== '') {
      return response.data.message;
    }
  }

  return 'The decision could not be completed. Refresh the page and try again.';
};

const FlaggedAccounts: React.FC = () => {
  const { flaggedAccounts: initialAccounts } = usePage<PageProps>().props;
  const [accounts, setAccounts] = useState<FlaggedAccount[]>(initialAccounts ?? []);
  const [filterStatus, setFilterStatus] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [detailAccount, setDetailAccount] = useState<FlaggedAccount | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const filterOptions = useMemo(() => FILTER_OPTIONS.map((option) => ({
    ...option,
    count: option.value === 'all'
      ? accounts.length
      : accounts.filter((account) => account.status === option.value).length,
  })), [accounts]);

  const filteredAccounts = useMemo(() => {
    const term = searchTerm.trim().toLowerCase();

    return accounts.filter((account) => {
      const matchesStatus = filterStatus === 'all' || account.status === filterStatus;
      const matchesSearch = term === '' || [
        account.username,
        account.email,
        account.flaggedReason,
        account.reportedBy ?? '',
      ].some((value) => value.toLowerCase().includes(term));

      return matchesStatus && matchesSearch;
    });
  }, [accounts, filterStatus, searchTerm]);

  const patchStatus = (id: string, status: string) => {
    setAccounts((current) => current.map((account) => (
      account.id === id ? { ...account, status } : account
    )));
    setDetailAccount((current) => current && current.id === id
      ? { ...current, status }
      : current);
  };

  const submitDecision = async (
    account: FlaggedAccount,
    action: 'mark-reviewed' | 'dismiss' | 'ban',
    adminNotes: string | undefined,
  ) => {
    setBusyId(account.id);

    try {
      const response = await axios.post(
        `/superAdmin/flagged-accounts/${account.id}/${action}`,
        adminNotes === undefined ? {} : { admin_notes: adminNotes },
      );
      patchStatus(account.id, response.data?.status ?? account.status);
      setDetailAccount(null);
      await Swal.fire({
        title: 'Decision saved',
        icon: 'success',
        timer: 1600,
        showConfirmButton: false,
      });
    } catch (error: unknown) {
      await Swal.fire({
        title: 'Decision not saved',
        text: errorMessage(error),
        icon: 'error',
      });
    } finally {
      setBusyId(null);
    }
  };

  const handleMarkReviewed = (account: FlaggedAccount) => {
    void submitDecision(account, 'mark-reviewed', undefined);
  };

  const handleDismiss = async (account: FlaggedAccount) => {
    const result = await Swal.fire({
      title: 'Dismiss report?',
      text: 'The review remains live and this report will be closed.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Dismiss',
      cancelButtonText: 'Cancel',
    });

    if (result.isConfirmed) {
      await submitDecision(account, 'dismiss', undefined);
    }
  };

  const handleSuspend = async (account: FlaggedAccount) => {
    const result = await Swal.fire({
      title: 'Suspend customer account?',
      text: 'This creates a reversible account suspension and an appeal record.',
      input: 'textarea',
      inputLabel: 'Suspension reason',
      inputPlaceholder: 'Explain the policy decision…',
      inputAttributes: { 'aria-label': 'Suspension reason' },
      inputValidator: (value: string) => value.trim() === '' ? 'A suspension reason is required.' : undefined,
      showCancelButton: true,
      confirmButtonText: 'Suspend account',
      cancelButtonText: 'Cancel',
    });

    const reason = typeof result.value === 'string' ? result.value.trim() : '';
    if (result.isConfirmed && reason !== '') {
      await submitDecision(account, 'ban', reason);
    }
  };

  const renderStars = (rating = 0) => (
    <div className="flex items-center gap-1" aria-label={`${rating} out of 5 stars`}>
      {[1, 2, 3, 4, 5].map((value) => (
        <svg
          key={value}
          className={`size-4 ${value <= rating ? 'text-yellow-500' : 'text-gray-300'}`}
          viewBox="0 0 20 20"
          fill={value <= rating ? 'currentColor' : 'none'}
          stroke="currentColor"
          aria-hidden="true"
        >
          <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.2 1 5.9-5.2-2.8-5.2 2.8 1-5.9L1.5 7.7l5.9-.9z" />
        </svg>
      ))}
    </div>
  );

  return (
    <AppLayout>
      <Head title="Flagged Accounts" />
      <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
        <div className="mx-auto max-w-7xl">
          <div className="mb-8 flex items-center justify-between">
            <div>
              <h1 className="mb-2 text-3xl font-bold text-gray-900 dark:text-white">Flagged Accounts</h1>
              <p className="text-gray-600 dark:text-gray-400">Review customer reports using the account state workflow.</p>
            </div>
            <div className="text-right">
              <div className="text-2xl font-bold text-gray-900 dark:text-white">{filteredAccounts.length}</div>
              <div className="text-sm text-gray-500 dark:text-gray-400">Visible reports</div>
            </div>
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/5">
            <div className="mb-6 flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-xl bg-red-100 dark:bg-red-900/30">
                <UserIcon className="size-5 text-red-600 dark:text-red-400" />
              </div>
              <div>
                <h2 className="text-lg font-bold text-gray-900 dark:text-white">Review report management</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400">Investigate reports filed against customer reviews.</p>
              </div>
            </div>

            <div className="mb-6 space-y-4">
              <input
                type="search"
                placeholder="Search by username, email, reason, or shop…"
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              />
              <div className="flex flex-wrap gap-2" aria-label="Filter by status">
                {filterOptions.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => setFilterStatus(option.value)}
                    className={`rounded-lg px-4 py-2 text-sm font-medium ${filterStatus === option.value ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'}`}
                  >
                    {option.label} <span className="ml-1 rounded-full bg-black/10 px-2 py-0.5 text-xs">{option.count}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="max-w-full overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableCell isHeader>Customer</TableCell>
                    <TableCell isHeader>Email</TableCell>
                    <TableCell isHeader>Reason</TableCell>
                    <TableCell isHeader>Reported by</TableCell>
                    <TableCell isHeader>Date</TableCell>
                    <TableCell isHeader>Status</TableCell>
                    <TableCell isHeader>Actions</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredAccounts.length === 0 && (
                    <TableRow>
                      <TableCell>No flagged accounts found.</TableCell>
                    </TableRow>
                  )}
                  {filteredAccounts.map((account) => (
                    <TableRow key={account.id}>
                      <TableCell>
                        <div className="flex items-center gap-3">
                          <div className="flex size-10 items-center justify-center rounded-xl bg-red-100 dark:bg-red-900/30">
                            <UserIcon className="size-5 text-red-600 dark:text-red-400" />
                          </div>
                          <span className="font-semibold text-gray-900 dark:text-white">{account.username}</span>
                        </div>
                      </TableCell>
                      <TableCell>{account.email}</TableCell>
                      <TableCell>{account.flaggedReason}</TableCell>
                      <TableCell>{account.reportedBy ?? '—'}</TableCell>
                      <TableCell>{new Date(account.flaggedDate).toLocaleDateString()}</TableCell>
                      <TableCell>
                        <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusColor(account.status)}`}>
                          {statusLabel(account.status)}
                        </span>
                      </TableCell>
                      <TableCell>
                        <button
                          type="button"
                          onClick={() => setDetailAccount(account)}
                          className="rounded-lg bg-blue-100 px-3 py-1 text-sm text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                        >
                          Review
                        </button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </div>
        </div>
      </div>

      {detailAccount && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
            <div className="mb-5 flex items-center justify-between border-b border-gray-200 pb-4 dark:border-gray-800">
              <div>
                <h2 className="text-xl font-semibold text-gray-900 dark:text-white">Review report details</h2>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                  {detailAccount.username} · <span className={statusColor(detailAccount.status)}>{statusLabel(detailAccount.status)}</span>
                </p>
              </div>
              <button type="button" onClick={() => setDetailAccount(null)} className="rounded-lg border px-3 py-1.5 text-sm">Close</button>
            </div>

            <div className="space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="rounded-xl border p-4 dark:border-gray-700">
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Reported by</p>
                  <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white">{detailAccount.reportedBy ?? '—'}</p>
                </div>
                <div className="rounded-xl border p-4 dark:border-gray-700">
                  <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Reason</p>
                  <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white">{detailAccount.flaggedReason}</p>
                </div>
              </div>

              {detailAccount.reportNotes && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                  <p className="text-xs font-semibold uppercase tracking-wide text-amber-700">Report notes</p>
                  <p className="mt-1 text-sm text-amber-800 dark:text-amber-300">{detailAccount.reportNotes}</p>
                </div>
              )}

              {detailAccount.reviewSnapshot && (
                <div className="rounded-xl border p-4 dark:border-gray-700">
                  <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Original review</p>
                  {renderStars(detailAccount.reviewSnapshot.rating)}
                  <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">{detailAccount.reviewSnapshot.comment || 'No comment'}</p>
                </div>
              )}
            </div>

            {detailAccount.status === 'pending_review' && (
              <div className="mt-6 flex justify-end border-t border-gray-200 pt-4 dark:border-gray-700">
                <button
                  type="button"
                  disabled={busyId === detailAccount.id}
                  onClick={() => handleMarkReviewed(detailAccount)}
                  className="rounded-lg border border-orange-300 px-4 py-2 text-sm font-medium text-orange-700 disabled:opacity-50"
                >
                  Mark under investigation
                </button>
              </div>
            )}

            {detailAccount.status === 'under_investigation' && (
              <div className="mt-6 flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <button
                  type="button"
                  disabled={busyId === detailAccount.id}
                  onClick={() => void handleDismiss(detailAccount)}
                  className="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                  Dismiss report
                </button>
                <button
                  type="button"
                  disabled={busyId === detailAccount.id}
                  onClick={() => void handleSuspend(detailAccount)}
                  className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                  Suspend account
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </AppLayout>
  );
};

export default FlaggedAccounts;
