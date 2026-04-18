import React, { useState, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';
import Swal from 'sweetalert2';
import axios from 'axios';
import { UserIcon } from '../../../icons';
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableCell,
} from '../../../components/ui/table';

// Types
interface ReviewSnapshot {
  type: string;
  rating: number;
  comment: string;
  images?: string[];
  customerName: string;
  createdAt: string;
}

interface FlaggedAccount {
  id: string;
  username: string;
  email: string;
  flaggedReason: string;
  flaggedDate: string;
  status: string;
  // Extended fields from review reports
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

const FlaggedAccounts: React.FC = () => {
  const { flaggedAccounts: initialAccounts } = usePage<PageProps>().props;

  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [flaggedAccounts, setFlaggedAccounts] = useState<FlaggedAccount[]>(initialAccounts ?? []);
  const [detailAccount, setDetailAccount] = useState<FlaggedAccount | null>(null);

  // Memoized filter options with counts
  const filterOptions = useMemo(() => {
    const baseOptions = [
      { label: 'All', value: 'all' },
      { label: 'Pending Review', value: 'pending_review' },
      { label: 'Under Investigation', value: 'under_investigation' },
      { label: 'Dismissed', value: 'dismissed' },
      { label: 'Banned', value: 'banned' },
    ];

    return baseOptions.map(option => ({
      ...option,
      count: option.value === 'all'
        ? flaggedAccounts.length
        : flaggedAccounts.filter(a => a.status === option.value).length,
    }));
  }, [flaggedAccounts]);

  // Memoized filtered and searched accounts
  const filteredAccounts = useMemo(() => {
    let filtered = flaggedAccounts;

    if (filterStatus !== 'all') {
      filtered = filtered.filter(a => a.status === filterStatus);
    }

    if (searchTerm.trim()) {
      const term = searchTerm.toLowerCase();
      filtered = filtered.filter(a =>
        a.username.toLowerCase().includes(term) ||
        a.email.toLowerCase().includes(term) ||
        a.flaggedReason.toLowerCase().includes(term) ||
        (a.reportedBy ?? '').toLowerCase().includes(term),
      );
    }

    return filtered;
  }, [flaggedAccounts, filterStatus, searchTerm]);

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pending_review':      return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
      case 'under_investigation': return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
      case 'dismissed':           return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
      case 'banned':              return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
      default:                    return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
  };

  const patchStatus = (id: string, newStatus: string) =>
    setFlaggedAccounts(prev =>
      prev.map(a => (a.id === id ? { ...a, status: newStatus } : a)),
    );

  const handleMarkReviewed = async (account: FlaggedAccount) => {
    try {
      await axios.post(`/superAdmin/flagged-accounts/${account.id}/mark-reviewed`);
      patchStatus(account.id, 'under_investigation');
      setDetailAccount(a => a ? { ...a, status: 'under_investigation' } : null);
      Swal.fire({ title: 'Marked as Under Investigation', icon: 'success', confirmButtonColor: '#10B981', timer: 1800, showConfirmButton: false });
    } catch {
      Swal.fire({ title: 'Error', text: 'Could not update status.', icon: 'error' });
    }
  };

  const handleDismiss = (account: FlaggedAccount) => {
    Swal.fire({
      title: 'Dismiss Report',
      text: 'Mark this report as dismissed? The review will remain visible.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#10B981',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Dismiss',
    }).then(async result => {
      if (!result.isConfirmed) return;
      try {
        await axios.post(`/superAdmin/flagged-accounts/${account.id}/dismiss`);
        patchStatus(account.id, 'dismissed');
        setDetailAccount(a => a ? { ...a, status: 'dismissed' } : null);
        Swal.fire({ title: 'Report Dismissed', icon: 'success', confirmButtonColor: '#10B981', timer: 1800, showConfirmButton: false });
      } catch {
        Swal.fire({ title: 'Error', text: 'Could not dismiss report.', icon: 'error' });
      }
    });
  };

  const handleBan = (account: FlaggedAccount) => {
    Swal.fire({
      title: 'Ban Customer Account',
      text: `Ban "${account.username}"? This will flag their account for suspension.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#DC2626',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Ban Account',
    }).then(async result => {
      if (!result.isConfirmed) return;
      try {
        await axios.post(`/superAdmin/flagged-accounts/${account.id}/ban`);
        patchStatus(account.id, 'banned');
        setDetailAccount(a => a ? { ...a, status: 'banned' } : null);
        Swal.fire({ title: 'Account Banned', icon: 'success', confirmButtonColor: '#10B981', timer: 1800, showConfirmButton: false });
      } catch {
        Swal.fire({ title: 'Error', text: 'Could not ban account.', icon: 'error' });
      }
    });
  };

  const renderStars = (rating: number) => (
    <div className="flex items-center gap-1">
      {[1, 2, 3, 4, 5].map(v => (
        <svg key={v} className={`size-4 ${v <= rating ? 'text-yellow-500' : 'text-gray-300'}`} viewBox="0 0 20 20" fill={v <= rating ? 'currentColor' : 'none'} stroke="currentColor">
          <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.783.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.363-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81H7.03a1 1 0 00.951-.69l1.07-3.292z" />
        </svg>
      ))}
    </div>
  );

  return (
    <AppLayout>
      <Head title="Flagged Accounts" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 p-6">
        <div className="max-w-7xl mx-auto">
          {/* Header */}
          <div className="mb-8">
            <div className="flex items-center justify-between">
              <div>
                <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Flagged Accounts</h1>
                <p className="text-gray-600 dark:text-gray-400">Review customer accounts reported for malicious reviews</p>
              </div>
              <div className="text-right">
                <div className="text-2xl font-bold text-gray-900 dark:text-white">{filteredAccounts.length}</div>
                <div className="text-sm text-gray-500 dark:text-gray-400">Total Reports</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/3">
            <div className="flex items-center gap-3 mb-6">
              <div className="flex items-center justify-center w-10 h-10 bg-red-100 rounded-xl dark:bg-red-900/30">
                <UserIcon className="text-red-600 size-5 dark:text-red-400" />
              </div>
              <div className="flex-1">
                <h3 className="text-lg font-bold text-gray-900 dark:text-white">Review Report Management</h3>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  Investigate reports filed by shop owners against malicious customer reviews
                </p>
              </div>
            </div>

            {/* Search and Filters */}
            <div className="mb-6 space-y-4">
              <div className="relative">
                <input
                  type="text"
                  placeholder="Search by username, email, reason, or shop…"
                  value={searchTerm}
                  onChange={e => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-800 dark:border-gray-600 dark:text-white dark:placeholder-gray-400"
                />
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Filter by Status</label>
                <div className="flex flex-wrap gap-2">
                  {filterOptions.map(option => (
                    <button
                      key={option.value}
                      onClick={() => setFilterStatus(option.value)}
                      className={`px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 flex items-center gap-2 ${
                        filterStatus === option.value
                          ? 'bg-red-600 text-white shadow-md'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'
                      }`}
                    >
                      {option.label}
                      <span className={`px-2 py-0.5 rounded-full text-xs ${filterStatus === option.value ? 'bg-white/20 text-white' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'}`}>
                        {option.count}
                      </span>
                    </button>
                  ))}
                </div>
              </div>
            </div>

            <div className="max-w-full overflow-x-auto">
              <Table>
                <TableHeader className="border-b border-gray-100 dark:border-white/5">
                  <TableRow>
                    <TableCell isHeader className="px-5 py-4 text-start">Customer</TableCell>
                    <TableCell isHeader className="px-4 py-3 text-start">Email</TableCell>
                    <TableCell isHeader className="px-4 py-3 text-start">Reason</TableCell>
                    <TableCell isHeader className="px-4 py-3 text-start">Reported By</TableCell>
                    <TableCell isHeader className="px-4 py-3 text-start">Date</TableCell>
                    <TableCell isHeader className="px-4 py-3 text-start">Status</TableCell>
                    <TableCell isHeader className="px-4 py-3 text-start">Actions</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-gray-100 dark:divide-white/5">
                  {filteredAccounts.length === 0 && (
                    <TableRow>
                      <TableCell className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        No flagged accounts found.
                      </TableCell>
                    </TableRow>
                  )}
                  {filteredAccounts.map(account => (
                    <TableRow key={account.id}>
                      <TableCell className="px-5 py-4 text-start">
                        <div className="flex items-center gap-3">
                          <div className="flex items-center justify-center w-10 h-10 bg-red-100 rounded-xl dark:bg-red-900/30">
                            <UserIcon className="text-red-600 size-5 dark:text-red-400" />
                          </div>
                          <h4 className="font-semibold text-gray-900 dark:text-white">{account.username}</h4>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                        {account.email}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                        {account.flaggedReason}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                        {account.reportedBy ?? '—'}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                        {new Date(account.flaggedDate).toLocaleDateString()}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-start">
                        <span className={`px-3 py-1 rounded-full text-xs font-semibold ${getStatusColor(account.status)}`}>
                          {account.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-start">
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => setDetailAccount(account)}
                            className="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-800/50 text-sm"
                          >
                            Review
                          </button>
                          {(account.status === 'pending_review' || account.status === 'under_investigation') && (
                            <>
                              <button
                                onClick={() => handleDismiss(account)}
                                className="px-3 py-1 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-800/50 text-sm"
                              >
                                Dismiss
                              </button>
                              <button
                                onClick={() => handleBan(account)}
                                className="px-3 py-1 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-800/50 text-sm"
                              >
                                Ban
                              </button>
                            </>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </div>
        </div>
      </div>

      {/* ── Review Detail Modal ──────────────────────────────────────────── */}
      {detailAccount && (
        <>
          <div className="fixed inset-0 z-100000 bg-black/50" onClick={() => setDetailAccount(null)} />
          <div className="fixed inset-0 z-100001 flex items-center justify-center p-4">
            <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-900">
              {/* Modal header */}
              <div className="mb-5 flex items-center justify-between border-b border-gray-200 pb-4 dark:border-gray-800">
                <div>
                  <h3 className="text-xl font-semibold text-gray-900 dark:text-white">Review Report Details</h3>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Customer: <span className="font-medium text-gray-700 dark:text-gray-300">{detailAccount.username}</span>
                    {' · '}
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${getStatusColor(detailAccount.status)}`}>
                      {detailAccount.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                    </span>
                  </p>
                </div>
                <button
                  onClick={() => setDetailAccount(null)}
                  className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                  Close
                </button>
              </div>

              <div className="space-y-4">
                {/* Report info */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Reported By (Shop)</p>
                    <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white">{detailAccount.reportedBy ?? '—'}</p>
                  </div>
                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Reason</p>
                    <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white">{detailAccount.flaggedReason}</p>
                  </div>
                </div>

                {detailAccount.reportNotes && (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Shop's Notes</p>
                    <p className="mt-1 text-sm text-amber-800 dark:text-amber-300">{detailAccount.reportNotes}</p>
                  </div>
                )}

                {/* Review snapshot */}
                {detailAccount.reviewSnapshot && (
                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                      Original Review ({detailAccount.reviewSnapshot.type} · {detailAccount.reviewSnapshot.createdAt})
                    </p>
                    <div className="mb-2">{renderStars(detailAccount.reviewSnapshot.rating)}</div>
                    <p className="text-sm text-gray-700 dark:text-gray-300">{detailAccount.reviewSnapshot.comment || <em className="text-gray-400">No comment</em>}</p>
                    {detailAccount.reviewSnapshot.images && detailAccount.reviewSnapshot.images.length > 0 && (
                      <div className="mt-3 flex flex-wrap gap-2">
                        {detailAccount.reviewSnapshot.images.map((img, i) => (
                          <img key={i} src={img} alt={`Review image ${i + 1}`} className="h-20 w-20 rounded-lg object-cover border border-gray-200 dark:border-gray-700" />
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* Action buttons */}
              {(detailAccount.status === 'pending_review' || detailAccount.status === 'under_investigation') && (
                <div className="mt-6 flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                  {detailAccount.status === 'pending_review' && (
                    <button
                      onClick={() => handleMarkReviewed(detailAccount)}
                      className="rounded-lg border border-orange-300 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-50 dark:border-orange-700 dark:text-orange-400 dark:hover:bg-orange-900/20"
                    >
                      Mark Under Investigation
                    </button>
                  )}
                  <button
                    onClick={() => handleDismiss(detailAccount)}
                    className="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                  >
                    Dismiss Report
                  </button>
                  <button
                    onClick={() => handleBan(detailAccount)}
                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
                  >
                    Ban Customer
                  </button>
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </AppLayout>
  );
};

export default FlaggedAccounts;
