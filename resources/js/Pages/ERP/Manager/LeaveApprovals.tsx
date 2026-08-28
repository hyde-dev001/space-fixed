import { Head } from "@inertiajs/react";
import { useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { decideManagerLeaveRequest, useManagerLeaveApprovals } from "../../../hooks/useManagerApi";
import type {
    ManagerLeaveApprovalFilters,
    ManagerLeaveRequest,
} from "../../../hooks/useManagerApi";

type LeaveFilterForm = {
    search: string;
    status: string;
    leave_type: string;
    date_from: string;
    date_to: string;
};

const initialFilterForm: LeaveFilterForm = {
    search: "",
    status: "pending",
    leave_type: "",
    date_from: "",
    date_to: "",
};

const statusLabel = (value: string): string => value
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (character) => character.toUpperCase());

const statusClasses = (value: string): string => {
    switch (value) {
        case "pending":
            return "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300";
        case "approved":
            return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
        case "rejected":
            return "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300";
        default:
            return "border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300";
    }
};

const StatusBadge = ({ value }: { value: string }) => (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(value)}`}>
        {statusLabel(value)}
    </span>
);

const formatDate = (value: string | null | undefined): string => {
    if (!value) {
        return "Not available";
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? "Not available" : date.toLocaleDateString([], {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
};

const formatDateTime = (value: string | null | undefined): string => {
    if (!value) {
        return "Not available";
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? "Not available" : date.toLocaleString([], {
        dateStyle: "medium",
        timeStyle: "short",
    });
};

const ActionButtons = ({
    request,
    processing,
    onApprove,
    onReject,
}: {
    request: ManagerLeaveRequest;
    processing: boolean;
    onApprove: (request: ManagerLeaveRequest) => void;
    onReject: (request: ManagerLeaveRequest) => void;
}) => {
    if (request.status !== "pending") {
        return <span className="text-sm text-gray-500 dark:text-gray-400">No action required</span>;
    }

    return (
        <div className="flex flex-wrap gap-2">
            <button
                type="button"
                onClick={() => onApprove(request)}
                disabled={processing}
                className="min-h-11 rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-offset-gray-950"
            >
                Approve
            </button>
            <button
                type="button"
                onClick={() => onReject(request)}
                disabled={processing}
                className="min-h-11 rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 transition-colors hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30 dark:focus:ring-offset-gray-950"
            >
                Reject
            </button>
        </div>
    );
};

const LeaveRequestCard = ({
    request,
    processing,
    onApprove,
    onReject,
}: {
    request: ManagerLeaveRequest;
    processing: boolean;
    onApprove: (request: ManagerLeaveRequest) => void;
    onReject: (request: ManagerLeaveRequest) => void;
}) => (
    <article className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-start justify-between gap-3">
            <div>
                <h3 className="text-base font-semibold text-gray-900 dark:text-white">{request.employee.name}</h3>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{request.employee.position || request.employee.email}</p>
            </div>
            <StatusBadge value={request.status} />
        </div>

        <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Leave type</dt>
                <dd className="mt-1 font-medium text-gray-900 dark:text-white">{request.leave_type_label}</dd>
            </div>
            <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Duration</dt>
                <dd className="mt-1 font-medium text-gray-900 dark:text-white">{request.no_of_days} day(s)</dd>
            </div>
            <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Dates</dt>
                <dd className="mt-1 text-gray-700 dark:text-gray-300">{formatDate(request.start_date)} – {formatDate(request.end_date)}</dd>
            </div>
            <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Age</dt>
                <dd className={`mt-1 font-medium ${request.overdue ? "text-red-700 dark:text-red-300" : "text-gray-700 dark:text-gray-300"}`}>
                    {request.age_days} day(s){request.overdue ? " · Overdue" : ""}
                </dd>
            </div>
        </dl>

        <p className="mt-4 text-sm leading-6 text-gray-700 dark:text-gray-300">{request.reason}</p>
        <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">Coverage: {statusLabel(request.coverage_status)}</p>
        <div className="mt-4">
            <ActionButtons request={request} processing={processing} onApprove={onApprove} onReject={onReject} />
        </div>
    </article>
);

const LoadingState = () => (
    <div className="space-y-3" aria-label="Loading leave approvals" aria-busy="true">
        {[1, 2, 3].map((item) => <div key={item} className="h-24 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />)}
    </div>
);

const EmptyState = () => (
    <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-white/[0.03]">
        <h2 className="text-base font-semibold text-gray-900 dark:text-white">No leave requests found</h2>
        <p className="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-400">
            There are no requests matching the selected status and filters in your authorized shop.
        </p>
    </div>
);

export default function LeaveApprovals() {
    const [form, setForm] = useState<LeaveFilterForm>(initialFilterForm);
    const [filters, setFilters] = useState<ManagerLeaveApprovalFilters>({ status: "pending", page: 1, per_page: 20 });
    const approvals = useManagerLeaveApprovals(filters);
    const payload = approvals.data;
    const requests = payload?.data ?? [];
    const [requestToReject, setRequestToReject] = useState<ManagerLeaveRequest | null>(null);
    const [rejectionReason, setRejectionReason] = useState("");
    const [processingId, setProcessingId] = useState<number | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setActionError(null);
        setFilters({ ...form, page: 1, per_page: 20 });
    };

    const clearFilters = () => {
        setForm(initialFilterForm);
        setFilters({ status: "pending", page: 1, per_page: 20 });
        setActionError(null);
    };

    const approve = async (request: ManagerLeaveRequest) => {
        if (!window.confirm(`Approve ${request.no_of_days}-day leave for ${request.employee.name}?`)) {
            return;
        }

        setProcessingId(request.id);
        setActionError(null);
        try {
            await decideManagerLeaveRequest(request.id, "approve");
            await approvals.refetch();
        } catch (error) {
            setActionError(error instanceof Error ? error.message : "Unable to approve this request.");
        } finally {
            setProcessingId(null);
        }
    };

    const openReject = (request: ManagerLeaveRequest) => {
        setActionError(null);
        setRequestToReject(request);
        setRejectionReason("");
    };

    const reject = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!requestToReject || !rejectionReason.trim()) {
            return;
        }

        setProcessingId(requestToReject.id);
        setActionError(null);
        try {
            await decideManagerLeaveRequest(requestToReject.id, "reject", rejectionReason.trim());
            setRequestToReject(null);
            setRejectionReason("");
            await approvals.refetch();
        } catch (error) {
            setActionError(error instanceof Error ? error.message : "Unable to reject this request.");
        } finally {
            setProcessingId(null);
        }
    };

    const goToPage = (page: number) => {
        if (!payload || page < 1 || page > payload.last_page || page === payload.current_page) {
            return;
        }
        setFilters((current) => ({ ...current, page }));
    };

    return (
        <AppLayoutERP>
            <Head title="Leave Approvals - Solespace ERP" />

            <main className="space-y-6 py-6 md:py-8" aria-labelledby="leave-approvals-title">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">People &amp; approvals</p>
                        <h1 id="leave-approvals-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Leave Approvals</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                            Review shop-scoped leave requests. Manager approval is terminal by default and applies the balance effect once.
                        </p>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="font-semibold text-gray-900 dark:text-white">{payload?.total ?? 0} request(s)</p>
                        <p className="mt-1 text-gray-500 dark:text-gray-400">Latest request: {formatDateTime(payload?.data[0]?.created_at)}</p>
                    </div>
                </header>

                <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="leave-filters-title">
                    <div className="mb-4">
                        <h2 id="leave-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter requests</h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Age and overdue status are calculated from the request creation time. Formal SLA appears only when configured.</p>
                    </div>
                    <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
                        <div className="xl:col-span-2">
                            <label htmlFor="leave-search" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                            <input
                                id="leave-search"
                                type="search"
                                value={form.search}
                                onChange={(event) => setForm((current) => ({ ...current, search: event.target.value }))}
                                placeholder="Employee, email, or reason"
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            />
                        </div>
                        <div>
                            <label htmlFor="leave-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                            <select id="leave-status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                <option value="">All statuses</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div>
                            <label htmlFor="leave-type" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Leave type</label>
                            <select id="leave-type" value={form.leave_type} onChange={(event) => setForm((current) => ({ ...current, leave_type: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                <option value="">All leave types</option>
                                <option value="vacation">Vacation</option>
                                <option value="sick">Sick Leave</option>
                                <option value="personal">Personal Leave</option>
                                <option value="maternity">Maternity Leave</option>
                                <option value="paternity">Paternity Leave</option>
                                <option value="unpaid">Unpaid Leave</option>
                            </select>
                        </div>
                        <div>
                            <label htmlFor="leave-date-from" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Date from</label>
                            <input id="leave-date-from" type="date" value={form.date_from} onChange={(event) => setForm((current) => ({ ...current, date_from: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                        </div>
                        <div>
                            <label htmlFor="leave-date-to" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Date to</label>
                            <input id="leave-date-to" type="date" value={form.date_to} onChange={(event) => setForm((current) => ({ ...current, date_to: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                        </div>
                        <div className="flex items-end gap-2 md:col-span-2 xl:col-span-6">
                            <button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-offset-gray-950">Apply filters</button>
                            <button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-950">Clear</button>
                        </div>
                    </form>
                </section>

                {actionError && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{actionError}</div>}
                {approvals.isError && payload && <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">The latest refresh failed. Showing the last successful snapshot. <button type="button" onClick={() => approvals.refetch()} className="ml-1 font-semibold underline underline-offset-2">Retry</button></div>}
                {approvals.isFetching && payload && <p className="text-sm text-gray-500 dark:text-gray-400" role="status" aria-live="polite">Refreshing leave approvals…</p>}
                {approvals.isLoading && <LoadingState />}
                {approvals.isError && !payload && (
                    <div role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-6 py-8 dark:border-red-900/60 dark:bg-red-950/30">
                        <h2 className="text-base font-semibold text-red-800 dark:text-red-200">Unable to load leave approvals</h2>
                        <p className="mt-2 text-sm text-red-700 dark:text-red-300">{approvals.error?.message || "Please try again."}</p>
                        <button type="button" onClick={() => approvals.refetch()} className="mt-4 min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">Retry</button>
                    </div>
                )}
                {payload && requests.length === 0 && <EmptyState />}

                {payload && requests.length > 0 && (
                    <section aria-labelledby="leave-results-title" className="space-y-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 id="leave-results-title" className="text-lg font-semibold text-gray-900 dark:text-white">Leave request queue</h2>
                                <p className="text-sm text-gray-500 dark:text-gray-400">{payload.total.toLocaleString()} request(s) in the selected view.</p>
                            </div>
                            {approvals.isStale && !approvals.isFetching && <p className="text-sm font-medium text-amber-700 dark:text-amber-300">Snapshot may be stale</p>}
                        </div>

                        <div className="hidden overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:block dark:border-gray-800 dark:bg-white/[0.03]">
                            <table className="w-full table-fixed text-left">
                                <caption className="sr-only">Leave approval requests</caption>
                                <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400">
                                    <tr>
                                        <th scope="col" className="w-[20%] px-5 py-4 font-semibold">Employee</th>
                                        <th scope="col" className="w-[16%] px-3 py-4 font-semibold">Leave</th>
                                        <th scope="col" className="w-[19%] px-3 py-4 font-semibold">Dates</th>
                                        <th scope="col" className="w-[12%] px-3 py-4 font-semibold">Age</th>
                                        <th scope="col" className="w-[13%] px-3 py-4 font-semibold">Status</th>
                                        <th scope="col" className="w-[20%] px-5 py-4 font-semibold">Next action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {requests.map((request) => (
                                        <tr key={request.id} className="align-top transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold text-gray-900 dark:text-white">{request.employee.name}</p>
                                                <p className="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{request.employee.position || request.employee.email}</p>
                                            </td>
                                            <td className="px-3 py-4">
                                                <p className="font-medium text-gray-900 dark:text-white">{request.leave_type_label}</p>
                                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{request.no_of_days} day(s)</p>
                                            </td>
                                            <td className="px-3 py-4 text-sm text-gray-700 dark:text-gray-300">
                                                <p>{formatDate(request.start_date)} – {formatDate(request.end_date)}</p>
                                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Coverage: {statusLabel(request.coverage_status)}</p>
                                            </td>
                                            <td className={`px-3 py-4 text-sm font-medium ${request.overdue ? "text-red-700 dark:text-red-300" : "text-gray-700 dark:text-gray-300"}`}>
                                                {request.age_days} day(s){request.overdue ? " · Overdue" : ""}
                                            </td>
                                            <td className="px-3 py-4"><StatusBadge value={request.status} /></td>
                                            <td className="px-5 py-4">
                                                <p className="mb-3 text-sm text-gray-700 dark:text-gray-300">{request.next_action}</p>
                                                <ActionButtons request={request} processing={processingId === request.id} onApprove={approve} onReject={openReject} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid gap-4 lg:hidden">
                            {requests.map((request) => <LeaveRequestCard key={request.id} request={request} processing={processingId === request.id} onApprove={approve} onReject={openReject} />)}
                        </div>

                        {payload.last_page > 1 && (
                            <nav className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/[0.03]" aria-label="Leave approval pagination">
                                <p className="text-sm text-gray-600 dark:text-gray-400">Showing {payload.from ?? 0}–{payload.to ?? 0} of {payload.total.toLocaleString()}</p>
                                <div className="flex items-center gap-2">
                                    <button type="button" onClick={() => goToPage(payload.current_page - 1)} disabled={payload.current_page <= 1 || approvals.isFetching} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Previous</button>
                                    <span className="px-2 text-sm font-medium text-gray-700 dark:text-gray-300" aria-current="page">Page {payload.current_page} of {payload.last_page}</span>
                                    <button type="button" onClick={() => goToPage(payload.current_page + 1)} disabled={payload.current_page >= payload.last_page || approvals.isFetching} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Next</button>
                                </div>
                            </nav>
                        )}
                    </section>
                )}
            </main>

            {requestToReject && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" role="presentation">
                    <div role="dialog" aria-modal="true" aria-labelledby="reject-leave-title" className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900">
                        <h2 id="reject-leave-title" className="text-lg font-semibold text-gray-900 dark:text-white">Reject leave request</h2>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">Provide a reason for rejecting {requestToReject.employee.name}’s request. This reason is saved in the request history.</p>
                        <form onSubmit={reject} className="mt-5 space-y-4">
                            <div>
                                <label htmlFor="leave-rejection-reason" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Reason <span aria-hidden="true">*</span></label>
                                <textarea id="leave-rejection-reason" value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} required minLength={3} maxLength={500} rows={4} className="w-full rounded-lg border border-gray-300 bg-white p-3 text-sm text-gray-900 outline-none focus:border-red-500 focus:ring-2 focus:ring-red-500/20 dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                            </div>
                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button type="button" onClick={() => setRequestToReject(null)} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">Cancel</button>
                                <button type="submit" disabled={!rejectionReason.trim() || processingId === requestToReject.id} className="min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-offset-gray-900">Confirm rejection</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayoutERP>
    );
}
