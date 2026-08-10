import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Head, router } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';

type RequestStatus = 'pending' | 'approved' | 'rejected' | 'superseded';

type UpgradeRequestDocument = {
  id: number;
  document_type: string;
  mime_type: string;
  size: number;
  source_status: string;
  download_url: string;
};

export type BusinessUpgradeRequest = {
  id: number;
  status: RequestStatus;
  current_registration_type: string;
  current_business_type: string;
  requested_registration_type: string;
  requested_business_type: string;
  decision_reason: string | null;
  reviewed_at: string | null;
  created_at: string | null;
  shop_owner: {
    id: number;
    business_name: string;
    name: string;
    email: string;
  };
  reviewed_by: {
    id: number;
    name: string;
  } | null;
  documents: UpgradeRequestDocument[];
};

type QueueFilters = {
  status: RequestStatus | '';
  search: string;
  date_from: string;
  date_to: string;
};

type QueuePagination = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

type BusinessUpgradeRequestsProps = {
  requests: BusinessUpgradeRequest[];
  filters: Partial<QueueFilters>;
  pagination: QueuePagination;
};

type ReviewDecision = 'approved' | 'rejected';

const statusLabels: Record<RequestStatus, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  superseded: 'Superseded',
};

const formatState = (value: string | null | undefined): string => {
  const normalized = String(value ?? '').replace(/[_-]+/g, ' ').trim();
  if (!normalized) return 'Unknown';

  return normalized
    .split(' ')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(' ');
};

const formatDate = (value: string | null | undefined): string => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString();
};

const formatDocumentType = (value: string): string => {
  const labels: Record<string, string> = {
    dti_registration: 'Business registration',
    mayors_permit: "Mayor's permit",
    bir_certificate: 'BIR certificate',
    valid_id: 'Valid ID',
  };

  return labels[value] ?? formatState(value);
};

const statusClasses: Record<RequestStatus, string> = {
  pending: 'border-blue-200 bg-blue-50 text-blue-700',
  approved: 'border-green-200 bg-green-50 text-green-700',
  rejected: 'border-red-200 bg-red-50 text-red-700',
  superseded: 'border-slate-200 bg-slate-100 text-slate-600',
};

const initialFilterState = (filters: Partial<QueueFilters>): QueueFilters => ({
  status: filters.status ?? '',
  search: filters.search ?? '',
  date_from: filters.date_from ?? '',
  date_to: filters.date_to ?? '',
});

const requestErrorMessage = (error: unknown): { status: number | null; message: string | null } => {
  const response = typeof error === 'object' && error !== null && 'response' in error
    ? (error as { response?: unknown }).response
    : null;
  const status = typeof response === 'object' && response !== null && 'status' in response
    ? (response as { status?: unknown }).status
    : null;
  const data = typeof response === 'object' && response !== null && 'data' in response
    ? (response as { data?: unknown }).data
    : null;
  const message = typeof data === 'object' && data !== null && 'message' in data
    ? (data as { message?: unknown }).message
    : null;

  return {
    status: typeof status === 'number' ? status : null,
    message: typeof message === 'string' ? message : null,
  };
};

const BusinessUpgradeRequests: React.FC<BusinessUpgradeRequestsProps> = ({
  requests,
  filters: initialFilters,
  pagination,
}) => {
  const [rows, setRows] = useState(requests);
  const [filterForm, setFilterForm] = useState(() => initialFilterState(initialFilters));
  const [reviewId, setReviewId] = useState<number | null>(null);
  const [reviewDecision, setReviewDecision] = useState<ReviewDecision | null>(null);
  const [decisionReason, setDecisionReason] = useState('');
  const [reviewError, setReviewError] = useState<string | null>(null);
  const [reviewingId, setReviewingId] = useState<number | null>(null);

  useEffect(() => {
    setRows(requests);
  }, [requests]);

  const selectedRequest = useMemo(
    () => rows.find((request) => request.id === reviewId) ?? null,
    [reviewId, rows],
  );

  const queryParams = (): Record<string, string> => {
    const params: Record<string, string> = {};
    Object.entries(filterForm).forEach(([key, value]) => {
      if (value) params[key] = value;
    });
    return params;
  };

  const applyFilters = (event?: React.FormEvent<HTMLFormElement>) => {
    event?.preventDefault();
    router.get('/admin/business-upgrade-requests', queryParams(), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const goToPage = (page: number) => {
    router.get('/admin/business-upgrade-requests', { ...queryParams(), page: String(page) }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const beginReview = (request: BusinessUpgradeRequest, decision: ReviewDecision) => {
    setReviewError(null);
    if (decision === 'approved') {
      if (typeof window !== 'undefined' && !window.confirm('Approve upgrade request ' + request.id + '?')) return;
      void submitReview(request, decision, null);
      return;
    }

    setReviewId(request.id);
    setReviewDecision(decision);
    setDecisionReason('');
  };

  const submitReview = async (
    request: BusinessUpgradeRequest,
    decision: ReviewDecision,
    reason: string | null,
  ) => {
    if (reviewingId !== null) return;

    if (decision === 'rejected' && !reason?.trim()) {
      setReviewError('A rejection reason is required.');
      return;
    }

    setReviewingId(request.id);
    setReviewError(null);

    try {
      const response = await axios.patch('/admin/business-upgrade-requests/' + request.id, {
        decision,
        decision_reason: reason,
      });
      const reviewedRequest = response?.data?.request as BusinessUpgradeRequest | undefined;
      if (reviewedRequest) {
        setRows((previous) => previous.map((row) => row.id === request.id ? reviewedRequest : row));
      }
      setReviewId(null);
      setReviewDecision(null);
      setDecisionReason('');
    } catch (error: unknown) {
      const failure = requestErrorMessage(error);
      const message = failure.message
        ?? (failure.status === 409
          ? 'Another reviewer already decided this request. The queue was refreshed.'
          : 'The request could not be reviewed. Please try again.');
      setReviewError(message);
      if (failure.status === 409) {
        router.reload({ only: ['requests', 'pagination'], preserveScroll: true });
      }
    } finally {
      setReviewingId(null);
    }
  };

  const closeReview = () => {
    if (reviewingId !== null) return;
    setReviewId(null);
    setReviewDecision(null);
    setDecisionReason('');
    setReviewError(null);
  };

  return (
    <AppLayout>
      <Head title="Business Upgrade Requests" />
      <div className="space-y-6">
        <div>
          <p className="text-sm font-medium text-slate-500">Account Management</p>
          <h1 className="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">Business upgrade requests</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">
            Review captured account and capability changes without exposing private evidence storage details.
          </p>
        </div>

        <form onSubmit={applyFilters} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5 dark:border-slate-800 dark:bg-slate-900">
          <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
            Status
            <select
              aria-label="Filter status"
              value={filterForm.status}
              onChange={(event) => setFilterForm((previous) => ({ ...previous, status: event.target.value as QueueFilters['status'] }))}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
            >
              <option value="">All statuses</option>
              {(Object.keys(statusLabels) as RequestStatus[]).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
            </select>
          </label>
          <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
            Search
            <input
              aria-label="Search requests"
              value={filterForm.search}
              onChange={(event) => setFilterForm((previous) => ({ ...previous, search: event.target.value }))}
              placeholder="Shop, owner, email, or ID"
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
            />
          </label>
          <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
            From
            <input
              aria-label="Date from"
              type="date"
              value={filterForm.date_from}
              onChange={(event) => setFilterForm((previous) => ({ ...previous, date_from: event.target.value }))}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
            />
          </label>
          <label className="text-sm font-medium text-slate-700 dark:text-slate-200">
            To
            <input
              aria-label="Date to"
              type="date"
              value={filterForm.date_to}
              onChange={(event) => setFilterForm((previous) => ({ ...previous, date_to: event.target.value }))}
              className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
            />
          </label>
          <button type="submit" className="min-h-11 self-end rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 dark:bg-white dark:text-slate-900">
            Apply filters
          </button>
        </form>

        {reviewError ? <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800" role="alert">{reviewError}</p> : null}

        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/70 dark:text-slate-300">
                <tr>
                  <th className="px-4 py-3">Shop owner</th>
                  <th className="px-4 py-3">Transition</th>
                  <th className="px-4 py-3">Evidence</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                {rows.length === 0 ? (
                  <tr><td colSpan={5} className="px-4 py-10 text-center text-slate-500">No upgrade requests match these filters.</td></tr>
                ) : rows.map((request) => (
                  <tr key={request.id} className="align-top">
                    <td className="px-4 py-4">
                      <p className="font-semibold text-slate-900 dark:text-white">{request.shop_owner.business_name}</p>
                      <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">{request.shop_owner.name}</p>
                      <p className="text-xs text-slate-500">{request.shop_owner.email}</p>
                      <p className="mt-2 text-xs text-slate-500">Submitted {formatDate(request.created_at)}</p>
                    </td>
                    <td className="whitespace-nowrap px-4 py-4">
                      <p className="font-medium text-slate-900 dark:text-white">
                        {formatState(request.current_registration_type)} {formatState(request.current_business_type)}
                      </p>
                      <p className="mt-1 text-xs text-slate-500">to</p>
                      <p className="font-medium text-slate-700 dark:text-slate-200">
                        {formatState(request.requested_registration_type)} {formatState(request.requested_business_type)}
                      </p>
                      {request.decision_reason ? <p className="mt-2 max-w-xs text-xs text-red-700">{request.decision_reason}</p> : null}
                    </td>
                    <td className="px-4 py-4">
                      <div className="space-y-1">
                        {request.documents.map((document) => (
                          <a key={document.id} href={document.download_url} target="_blank" rel="noreferrer" className="block font-medium text-slate-700 underline underline-offset-2 hover:text-slate-950 dark:text-slate-200">
                            {formatDocumentType(document.document_type)}
                          </a>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-4">
                      <span className={'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ' + statusClasses[request.status]}>
                        {statusLabels[request.status]}
                      </span>
                      {request.reviewed_at ? <p className="mt-2 text-xs text-slate-500">Reviewed {formatDate(request.reviewed_at)}</p> : null}
                    </td>
                    <td className="px-4 py-4">
                      {request.status === 'pending' ? (
                        <div className="flex justify-end gap-2">
                          <button
                            type="button"
                            aria-label={'Approve request ' + request.id}
                            disabled={reviewingId !== null}
                            onClick={() => beginReview(request, 'approved')}
                            className="min-h-10 rounded-lg bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                          >
                            Approve
                          </button>
                          <button
                            type="button"
                            aria-label={'Reject request ' + request.id}
                            disabled={reviewingId !== null}
                            onClick={() => beginReview(request, 'rejected')}
                            className="min-h-10 rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50"
                          >
                            Reject
                          </button>
                        </div>
                      ) : <span className="block text-right text-xs text-slate-500">Settled</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-800">
            <span className="text-slate-500">{pagination.total} request{pagination.total === 1 ? '' : 's'}</span>
            <div className="flex items-center gap-2">
              <button type="button" disabled={pagination.current_page <= 1} onClick={() => goToPage(pagination.current_page - 1)} className="min-h-10 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-40" aria-label="Previous page">Previous</button>
              <span className="text-xs text-slate-500">Page {pagination.current_page} of {pagination.last_page}</span>
              <button type="button" disabled={pagination.current_page >= pagination.last_page} onClick={() => goToPage(pagination.current_page + 1)} className="min-h-10 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-40" aria-label="Next page">Next</button>
            </div>
          </div>
        </div>
      </div>

      {selectedRequest && reviewDecision === 'rejected' ? (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="reject-request-heading">
          <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-slate-900">
            <h2 id="reject-request-heading" className="text-lg font-semibold text-slate-900 dark:text-white">Reject request {selectedRequest.id}</h2>
            <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Give the shop owner a clear reason they can act on before resubmitting.</p>
            <label htmlFor="rejection-reason" className="mt-4 block text-sm font-semibold text-slate-700 dark:text-slate-200">Rejection reason</label>
            <textarea
              id="rejection-reason"
              aria-label="Rejection reason"
              value={decisionReason}
              onChange={(event) => setDecisionReason(event.target.value)}
              rows={4}
              className="mt-2 w-full rounded-lg border border-slate-300 p-3 text-sm dark:border-slate-700 dark:bg-slate-800"
            />
            <div className="mt-4 flex justify-end gap-2">
              <button type="button" onClick={closeReview} disabled={reviewingId !== null} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold">Cancel</button>
              <button type="button" onClick={() => void submitReview(selectedRequest, 'rejected', decisionReason)} disabled={reviewingId !== null} className="min-h-11 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">
                {reviewingId === selectedRequest.id ? 'Submitting…' : 'Confirm rejection'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </AppLayout>
  );
};

export default BusinessUpgradeRequests;
