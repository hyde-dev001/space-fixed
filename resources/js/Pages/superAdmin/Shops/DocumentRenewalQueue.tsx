import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';

export type ComplianceValidity =
  | 'valid'
  | 'valid_no_expiration'
  | 'expiring_soon'
  | 'expired'
  | 'metadata_unverified';

export type DocumentSummary = {
  id: number;
  document_type: string;
  logical_slot: string;
  version_number: number | null;
  status: string;
  issued_on: string | null;
  expiration_mode: string | null;
  expires_on: string | null;
  reviewed_at?: string | null;
  rejection_reason?: string | null;
  validity: ComplianceValidity;
  url: string;
};

export type DocumentRenewal = DocumentSummary & {
  created_at: string | null;
  owner: {
    id: number;
    business_name: string;
    name: string;
    email: string;
    status: string;
  };
  predecessor: DocumentSummary | null;
};

type RenewalStatus = 'all' | 'pending' | 'approved' | 'rejected';

type RenewalStats = {
  total: number;
  pending: number;
  approved: number;
  rejected: number;
};

type RenewalFilters = {
  document_id?: number | null;
  search?: string | null;
  status?: RenewalStatus | null;
};

type QueuePagination = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

type DocumentRenewalQueueProps = {
  renewals: DocumentRenewal[];
  pagination: QueuePagination;
  stats?: RenewalStats;
  filters?: RenewalFilters;
};

export const canReviewRenewal = (renewal: DocumentRenewal): boolean => (
  renewal.status === 'pending'
  && renewal.owner.status === 'approved'
  && renewal.predecessor !== null
);

export const buildRenewalApprovalPayload = (renewal: DocumentRenewal) => ({
  document_type: renewal.document_type,
  logical_slot: renewal.logical_slot,
  version_number: renewal.version_number,
  issued_on: renewal.issued_on,
  expiration_mode: renewal.expiration_mode,
  expires_on: renewal.expires_on,
  viewed: true,
});

const formatDate = (value: string | null): string => {
  if (!value) return 'Not supplied';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Not supplied' : date.toLocaleDateString();
};

const formatType = (value: string): string => value
  .replace(/[_-]+/g, ' ')
  .replace(/\b\w/g, (character) => character.toUpperCase());

const errorMessage = (errors: Record<string, string | string[]>): string => {
  const first = Object.values(errors).flatMap((value) => Array.isArray(value) ? value : [value])[0];

  return first ?? 'The server did not apply the review. Please try again.';
};

const DocumentRenewalQueue: React.FC<DocumentRenewalQueueProps> = ({
  renewals,
  pagination,
  stats = { total: 0, pending: 0, approved: 0, rejected: 0 },
  filters = {},
}) => {
  const [searchTerm, setSearchTerm] = useState(filters.search ?? '');
  const [filterStatus, setFilterStatus] = useState<RenewalStatus>(filters.status ?? 'pending');
  const [processingId, setProcessingId] = useState<number | null>(null);
  const [rejectingId, setRejectingId] = useState<number | null>(null);
  const [rejectionReason, setRejectionReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const queueParams = (page: number): Record<string, string | number> => {
    const params: Record<string, string | number> = {
      page,
      per_page: pagination.per_page,
      status: filterStatus,
    };
    const search = searchTerm.trim();

    if (search) params.search = search;
    if (filters.document_id) params.document_id = filters.document_id;

    return params;
  };

  const applyFilters = () => {
    router.get('/admin/document-renewals', queueParams(1), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const resetFilters = () => {
    setSearchTerm('');
    setFilterStatus('pending');
    router.get('/admin/document-renewals', {
      page: 1,
      per_page: pagination.per_page,
      status: 'pending',
      ...(filters.document_id ? { document_id: filters.document_id } : {}),
    }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const reloadQueue = () => {
    router.reload({ only: ['renewals', 'pagination', 'stats', 'filters'], preserveScroll: true });
  };

  const approve = (renewal: DocumentRenewal) => {
    if (!canReviewRenewal(renewal) || processingId !== null) return;
    if (typeof window !== 'undefined' && !window.confirm(`Approve renewal ${renewal.id}?`)) return;

    setError(null);
    setProcessingId(renewal.id);
    router.post(`/admin/document-renewals/${renewal.id}/approve`, buildRenewalApprovalPayload(renewal), {
      preserveScroll: true,
      onSuccess: reloadQueue,
      onError: (errors) => setError(errorMessage(errors)),
      onFinish: () => setProcessingId(null),
    });
  };

  const openReject = (renewal: DocumentRenewal) => {
    if (!canReviewRenewal(renewal) || processingId !== null) return;
    setError(null);
    setRejectingId(renewal.id);
    setRejectionReason('');
  };

  const reject = (renewal: DocumentRenewal) => {
    const reason = rejectionReason.trim();
    if (!canReviewRenewal(renewal) || processingId !== null) return;
    if (reason.length < 3) {
      setError('A rejection reason is required.');
      return;
    }

    setError(null);
    setProcessingId(renewal.id);
    router.post(`/admin/document-renewals/${renewal.id}/reject`, { rejection_reason: reason }, {
      preserveScroll: true,
      onSuccess: reloadQueue,
      onError: (errors) => setError(errorMessage(errors)),
      onFinish: () => {
        setProcessingId(null);
        setRejectingId(null);
        setRejectionReason('');
      },
    });
  };

  const goToPage = (page: number) => {
    router.get('/admin/document-renewals', queueParams(page), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const statusHeading: Record<RenewalStatus, string> = {
    all: 'All renewal submissions',
    pending: 'Pending renewals',
    approved: 'Approved renewals',
    rejected: 'Rejected renewals',
  };
  const statusBadge: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800',
    approved: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-800',
  };
  const start = pagination.total === 0 ? 0 : (pagination.current_page - 1) * pagination.per_page + 1;
  const end = Math.min(pagination.current_page * pagination.per_page, pagination.total);

  return (
    <AppLayout>
      <Head title="Document Renewals" />
      <div className="space-y-6">
        <div>
          <p className="text-sm font-medium text-slate-500">Account Management</p>
          <h1 className="mt-1 text-3xl font-semibold text-slate-950 dark:text-white">Document renewals</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">
            Review business-document renewals without changing the current approved evidence until a decision is committed.
          </p>
        </div>

        {error ? <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800" role="alert">{error}</p> : null}

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[
            ['Total renewals', stats.total, 'All submitted renewals'],
            ['Pending reviews', stats.pending, 'Awaiting admin decision'],
            ['Approved', stats.approved, 'Successfully approved'],
            ['Rejected renewals', stats.rejected, 'Available for correction'],
          ].map(([label, value, description]) => (
            <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
              <p className="mt-3 text-3xl font-semibold text-slate-950 dark:text-white">{value}</p>
              <p className="mt-2 text-sm text-slate-500">{description}</p>
            </div>
          ))}
        </div>

        <form
          onSubmit={(event) => {
            event.preventDefault();
            applyFilters();
          }}
          className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
        >
          <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_220px_auto_auto] md:items-end">
            <div>
              <label htmlFor="renewal-search" className="text-xs font-semibold uppercase tracking-wide text-slate-500">Search renewals</label>
              <input
                id="renewal-search"
                aria-label="Search renewals"
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="Business name, owner, email, or document"
                className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              />
            </div>
            <div>
              <label htmlFor="renewal-status" className="text-xs font-semibold uppercase tracking-wide text-slate-500">Filter by Status</label>
              <select
                id="renewal-status"
                aria-label="Filter by Status"
                value={filterStatus}
                onChange={(event) => setFilterStatus(event.target.value as RenewalStatus)}
                className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none focus:border-slate-900 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
              >
                <option value="all">All statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
            <button type="submit" className="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Apply filters</button>
            <button type="button" onClick={resetFilters} className="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Reset filters</button>
          </div>
        </form>

        <div>
          <h2 className="text-xl font-semibold text-slate-950 dark:text-white">{statusHeading[filterStatus]}</h2>
          <p className="mt-1 text-sm text-slate-500">Showing {start}-{end} of {pagination.total} renewal submissions</p>
        </div>

        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/70 dark:text-slate-300">
                <tr>
                  <th className="px-4 py-3">Shop owner</th>
                  <th className="px-4 py-3">Renewal</th>
                  <th className="px-4 py-3">Previous evidence</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                {renewals.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-12 text-center text-slate-500">No {filterStatus === 'all' ? '' : filterStatus} renewals found.</td></tr>
                ) : renewals.map((renewal) => (
                  <tr key={renewal.id} className="align-top">
                    <td className="px-4 py-4">
                      <p className="font-semibold text-slate-900 dark:text-white">{renewal.owner.business_name}</p>
                      <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">{renewal.owner.name}</p>
                      <p className="text-xs text-slate-500">{renewal.owner.email}</p>
                      <p className="mt-2 text-xs text-slate-500">Submitted {formatDate(renewal.created_at)}</p>
                    </td>
                    <td className="px-4 py-4">
                      <a href={renewal.url} className="font-semibold text-slate-900 underline underline-offset-2 dark:text-white">
                        {formatType(renewal.logical_slot)} v{renewal.version_number ?? '-'}
                      </a>
                      <p className="mt-2 text-xs text-slate-500">Type: {formatType(renewal.document_type)}</p>
                      <p className="mt-1 text-xs text-slate-500">Issued: {formatDate(renewal.issued_on)} - Expires: {formatDate(renewal.expires_on)}</p>
                    </td>
                    <td className="px-4 py-4">
                      {renewal.predecessor ? (
                        <a href={renewal.predecessor.url} className="font-medium text-slate-700 underline underline-offset-2 dark:text-slate-200">
                          {formatType(renewal.predecessor.document_type)} v{renewal.predecessor.version_number ?? '-'}
                        </a>
                      ) : <span className="text-slate-500">Unavailable</span>}
                      <p className="mt-1 text-xs text-slate-500">Status: {formatType(renewal.predecessor?.status ?? 'unavailable')}</p>
                    </td>
                    <td className="px-4 py-4">
                      <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${statusBadge[renewal.status] ?? 'bg-slate-100 text-slate-700'}`}>{renewal.status}</span>
                      {renewal.status === 'approved' ? <p className="mt-2 text-xs text-emerald-700">Reviewed {formatDate(renewal.reviewed_at ?? null)}</p> : null}
                      {renewal.status === 'rejected' ? <p className="mt-2 max-w-xs text-xs text-red-700">Reason: {renewal.rejection_reason || 'No reason supplied.'}</p> : null}
                    </td>
                    <td className="px-4 py-4">
                      {rejectingId === renewal.id ? (
                        <div className="space-y-2">
                          <label className="sr-only" htmlFor={`rejection-reason-${renewal.id}`}>Rejection reason</label>
                          <textarea id={`rejection-reason-${renewal.id}`} value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} maxLength={500} placeholder="Explain what must be corrected" className="min-h-20 w-56 rounded-lg border border-slate-300 p-2 text-xs dark:border-slate-700 dark:bg-slate-800" />
                          <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setRejectingId(null)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold dark:border-slate-700">Cancel</button>
                            <button type="button" onClick={() => reject(renewal)} disabled={processingId !== null} className="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Reject</button>
                          </div>
                        </div>
                      ) : canReviewRenewal(renewal) ? (
                        <div className="flex justify-end gap-2">
                          <button type="button" onClick={() => approve(renewal)} disabled={processingId !== null} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Approve</button>
                          <button type="button" onClick={() => openReject(renewal)} disabled={processingId !== null} className="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-50">Reject</button>
                        </div>
                      ) : (
                        <a href={renewal.url} className="inline-flex rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">View record</a>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {pagination.last_page > 1 ? (
          <nav aria-label="Document renewal pages" className="flex items-center justify-between">
            <p className="text-sm text-slate-500">Page {pagination.current_page} of {pagination.last_page}</p>
            <div className="flex gap-2">
              <button type="button" onClick={() => goToPage(pagination.current_page - 1)} disabled={pagination.current_page <= 1} className="rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700">Previous</button>
              <button type="button" onClick={() => goToPage(pagination.current_page + 1)} disabled={pagination.current_page >= pagination.last_page} className="rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-slate-700">Next</button>
            </div>
          </nav>
        ) : null}
      </div>
    </AppLayout>
  );
};

export default DocumentRenewalQueue;
