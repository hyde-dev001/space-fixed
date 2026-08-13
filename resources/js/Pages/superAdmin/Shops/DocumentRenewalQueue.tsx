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

type QueuePagination = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

type DocumentRenewalQueueProps = {
  renewals: DocumentRenewal[];
  pagination: QueuePagination;
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

const DocumentRenewalQueue: React.FC<DocumentRenewalQueueProps> = ({ renewals, pagination }) => {
  const [processingId, setProcessingId] = useState<number | null>(null);
  const [rejectingId, setRejectingId] = useState<number | null>(null);
  const [rejectionReason, setRejectionReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const reloadQueue = () => {
    router.reload({ only: ['renewals', 'pagination'], preserveScroll: true });
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
    router.get('/admin/document-renewals', { page, per_page: pagination.per_page }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  return (
    <AppLayout>
      <Head title="Document Renewals" />
      <div className="space-y-6">
        <div>
          <p className="text-sm font-medium text-slate-500">Account Management</p>
          <h1 className="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">Document renewals</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">
            Review pending business-document versions while keeping the current approved evidence unchanged until a decision is committed.
          </p>
        </div>

        {error ? <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800" role="alert">{error}</p> : null}

        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/70 dark:text-slate-300">
                <tr>
                  <th className="px-4 py-3">Shop owner</th>
                  <th className="px-4 py-3">Renewal</th>
                  <th className="px-4 py-3">Current evidence</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                {renewals.length === 0 ? (
                  <tr><td colSpan={4} className="px-4 py-10 text-center text-slate-500">No pending document renewals.</td></tr>
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
                        {formatType(renewal.logical_slot)} v{renewal.version_number ?? '—'}
                      </a>
                      <p className="mt-2 text-xs text-slate-500">Type: {formatType(renewal.document_type)}</p>
                      <p className="mt-1 text-xs text-slate-500">Issued: {formatDate(renewal.issued_on)} · Expires: {formatDate(renewal.expires_on)}</p>
                    </td>
                    <td className="px-4 py-4">
                      {renewal.predecessor ? (
                        <a href={renewal.predecessor.url} className="font-medium text-slate-700 underline underline-offset-2 dark:text-slate-200">
                          {formatType(renewal.predecessor.document_type)} v{renewal.predecessor.version_number ?? '—'}
                        </a>
                      ) : <span className="text-slate-500">Unavailable</span>}
                      <p className="mt-1 text-xs text-slate-500">Status: {formatType(renewal.predecessor?.status ?? 'unavailable')}</p>
                    </td>
                    <td className="px-4 py-4">
                      {rejectingId === renewal.id ? (
                        <div className="space-y-2">
                          <label className="sr-only" htmlFor={`rejection-reason-${renewal.id}`}>Rejection reason</label>
                          <textarea
                            id={`rejection-reason-${renewal.id}`}
                            value={rejectionReason}
                            onChange={(event) => setRejectionReason(event.target.value)}
                            maxLength={500}
                            placeholder="Explain what must be corrected"
                            className="min-h-20 w-56 rounded-lg border border-slate-300 p-2 text-xs dark:border-slate-700 dark:bg-slate-800"
                          />
                          <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setRejectingId(null)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold dark:border-slate-700">Cancel</button>
                            <button type="button" onClick={() => reject(renewal)} disabled={processingId !== null} className="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Reject</button>
                          </div>
                        </div>
                      ) : (
                        <div className="flex justify-end gap-2">
                          <button type="button" onClick={() => approve(renewal)} disabled={!canReviewRenewal(renewal) || processingId !== null} className="rounded-lg bg-green-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Approve</button>
                          <button type="button" onClick={() => openReject(renewal)} disabled={!canReviewRenewal(renewal) || processingId !== null} className="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-50">Reject</button>
                        </div>
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
