import { Head } from "@inertiajs/react";
import { useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { workflowFeedback } from "../../../utils/workflowFeedback";
import {
    decideManagerSuspensionRequest,
    useManagerSuspensionApprovals,
} from "../../../hooks/useManagerApi";
import type {
    ManagerSuspensionApprovalFilters,
    ManagerSuspensionRequest,
} from "../../../hooks/useManagerApi";

type SuspensionFilterForm = {
    search: string;
    status: string;
};

const initialFilterForm: SuspensionFilterForm = {
    search: "",
    status: "pending_manager",
};

const statusLabel = (value: string): string => {
    const labels: Record<string, string> = {
        pending_manager: "Pending Manager Review",
        pending_owner: "Waiting for Shop Owner",
        approved: "Approved",
        rejected_manager: "Rejected by Manager",
        rejected_owner: "Rejected by Shop Owner",
        pending: "Pending",
        rejected: "Rejected",
    };

    return labels[value] ?? value.replace(/[_-]+/g, " ").replace(/\b\w/g, (character) => character.toUpperCase());
};

const statusClasses = (value: string): string => {
    if (value === "pending_manager") {
        return "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300";
    }
    if (value === "pending_owner") {
        return "border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300";
    }
    if (value === "approved") {
        return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
    }
    if (value.includes("rejected")) {
        return "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300";
    }
    return "border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300";
};

const StatusBadge = ({ value }: { value: string }) => (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(value)}`}>
        {statusLabel(value)}
    </span>
);

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

const MetricCard = ({ label, value, tone }: { label: string; value: number; tone: "amber" | "blue" | "green" | "red" }) => {
    const tones = {
        amber: "border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/20",
        blue: "border-blue-200 bg-blue-50 dark:border-blue-900/50 dark:bg-blue-950/20",
        green: "border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/20",
        red: "border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/20",
    };

    return (
        <div className={`rounded-2xl border p-4 shadow-sm ${tones[tone]}`}>
            <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{label}</p>
            <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{value.toLocaleString()}</p>
        </div>
    );
};

const LoadingState = () => (
    <div className="space-y-3" aria-label="Loading suspension approvals" aria-busy="true">
        {[1, 2, 3].map((item) => <div key={item} className="h-28 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />)}
    </div>
);

const EmptyState = () => (
    <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-white/[0.03]">
        <h2 className="text-base font-semibold text-gray-900 dark:text-white">No suspension requests found</h2>
        <p className="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-400">
            There are no requests matching the selected status and search filters in your authorized shop.
        </p>
    </div>
);

const ActionButtons = ({
    request,
    onView,
}: {
    request: ManagerSuspensionRequest;
    onView: (request: ManagerSuspensionRequest) => void;
}) => (
    <div className="flex flex-wrap gap-2">
        <button
            type="button"
            onClick={() => onView(request)}
            className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-950"
        >
            View details
        </button>
    </div>
);

const SuspensionRequestCard = ({
    request,
    onView,
}: {
    request: ManagerSuspensionRequest;
    onView: (request: ManagerSuspensionRequest) => void;
}) => (
    <article className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-start justify-between gap-3">
            <div>
                <h3 className="text-base font-semibold text-gray-900 dark:text-white">{request.name || "Unknown employee"}</h3>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{request.position || request.email || "No employee context"}</p>
            </div>
            <StatusBadge value={request.workflow_status} />
        </div>

        <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Requested by</dt>
                <dd className="mt-1 font-medium text-gray-900 dark:text-white">{request.requested_by || "Not available"}</dd>
            </div>
            <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Age</dt>
                <dd className={`mt-1 font-medium ${request.overdue ? "text-red-700 dark:text-red-300" : "text-gray-700 dark:text-gray-300"}`}>
                    {request.age_days} day(s){request.overdue ? " - Overdue" : ""}
                </dd>
            </div>
            <div className="col-span-2">
                <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Next action</dt>
                <dd className="mt-1 text-gray-700 dark:text-gray-300">{request.next_action}</dd>
            </div>
        </dl>

        <p className="mt-4 line-clamp-3 text-sm leading-6 text-gray-700 dark:text-gray-300">{request.reason}</p>
        <div className="mt-4">
            <ActionButtons request={request} onView={onView} />
        </div>
    </article>
);

export default function SuspensionApprovals() {
    const [form, setForm] = useState<SuspensionFilterForm>(initialFilterForm);
    const [filters, setFilters] = useState<ManagerSuspensionApprovalFilters>({ status: "pending_manager", page: 1, per_page: 20 });
    const approvals = useManagerSuspensionApprovals(filters);
    const payload = approvals.data;
    const page = payload?.data;
    const requests = page?.data ?? [];
    const metrics = payload?.metrics ?? { pending: 0, awaiting_owner: 0, approved: 0, rejected: 0, total: 0 };
    const [selectedRequest, setSelectedRequest] = useState<ManagerSuspensionRequest | null>(null);
    const [requestToReject, setRequestToReject] = useState<ManagerSuspensionRequest | null>(null);
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
        setFilters({ status: "pending_manager", page: 1, per_page: 20 });
        setActionError(null);
    };

    const approve = async (request: ManagerSuspensionRequest) => {
        const confirmation = await workflowFeedback.confirm({
            title: "Approve suspension stage?",
            text: `Approve the Manager stage for ${request.name || "this employee"}? The request will move to Shop Owner review.`,
            confirmButtonText: "Approve stage",
        });
        if (!confirmation.isConfirmed) {
            return;
        }

        setProcessingId(request.id);
        setActionError(null);
        try {
            await decideManagerSuspensionRequest(request.id, "approve");
            setSelectedRequest(null);
            await workflowFeedback.success({
                title: "Suspension stage approved",
                text: "The request was forwarded to Shop Owner review.",
            });
            await approvals.refetch();
        } catch (error) {
            const message = error instanceof Error ? error.message : "Unable to approve this request.";
            setActionError(message);
            await workflowFeedback.error(message, "Suspension approval failed");
        } finally {
            setProcessingId(null);
        }
    };

    const openReject = (request: ManagerSuspensionRequest) => {
        setActionError(null);
        setSelectedRequest(null);
        setRequestToReject(request);
        setRejectionReason("");
    };

    const reject = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!requestToReject || rejectionReason.trim().length < 3) {
            return;
        }

        setProcessingId(requestToReject.id);
        setActionError(null);
        try {
            await decideManagerSuspensionRequest(requestToReject.id, "reject", rejectionReason.trim());
            setRequestToReject(null);
            setRejectionReason("");
            await workflowFeedback.success({
                title: "Suspension request rejected",
                text: "The request was rejected and closed at the Manager stage.",
            });
            await approvals.refetch();
        } catch (error) {
            const message = error instanceof Error ? error.message : "Unable to reject this request.";
            setActionError(message);
            await workflowFeedback.error(message, "Suspension rejection failed");
        } finally {
            setProcessingId(null);
        }
    };

    const goToPage = (nextPage: number) => {
        if (!page || nextPage < 1 || nextPage > page.last_page || nextPage === page.current_page) {
            return;
        }
        setFilters((current) => ({ ...current, page: nextPage }));
    };

    return (
        <AppLayoutERP>
            <Head title="Suspension Approvals - Solespace ERP" />

            <main className="space-y-6 py-6 md:py-8" aria-labelledby="suspension-approvals-title">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">People &amp; approvals</p>
                        <h1 id="suspension-approvals-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Suspension Approvals</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                            Review the Manager stage of the HR -&gt; Manager -&gt; Shop Owner suspension workflow. Approval forwards the request; it does not suspend the employee by itself.
                        </p>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="font-semibold text-gray-900 dark:text-white">{page?.total ?? 0} request(s)</p>
                        <p className="mt-1 text-gray-500 dark:text-gray-400">Latest request: {formatDateTime(page?.data[0]?.requested_at)}</p>
                    </div>
                </header>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Suspension approval summary">
                    <MetricCard label="Pending Manager review" value={metrics.pending} tone="amber" />
                    <MetricCard label="Waiting for Shop Owner" value={metrics.awaiting_owner} tone="blue" />
                    <MetricCard label="Approved" value={metrics.approved} tone="green" />
                    <MetricCard label="Rejected" value={metrics.rejected} tone="red" />
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="suspension-filters-title">
                    <div className="mb-4">
                        <h2 id="suspension-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter requests</h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Age is based on request creation. Formal SLA appears only when a policy is configured.</p>
                    </div>
                    <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div className="md:col-span-2">
                            <label htmlFor="suspension-search" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                            <input
                                id="suspension-search"
                                type="search"
                                value={form.search}
                                onChange={(event) => setForm((current) => ({ ...current, search: event.target.value }))}
                                placeholder="Employee, email, or reason"
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            />
                        </div>
                        <div>
                            <label htmlFor="suspension-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Workflow status</label>
                            <select id="suspension-status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                <option value="pending_manager">Pending Manager review</option>
                                <option value="pending_owner">Waiting for Shop Owner</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="">All statuses</option>
                            </select>
                        </div>
                        <div className="flex items-end gap-2 md:col-span-3">
                            <button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-offset-gray-950">Apply filters</button>
                            <button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-950">Clear</button>
                        </div>
                    </form>
                </section>

                {actionError && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{actionError}</div>}
                {approvals.isError && payload && <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">The latest refresh failed. Showing the last successful snapshot. <button type="button" onClick={() => approvals.refetch()} className="ml-1 font-semibold underline underline-offset-2">Retry</button></div>}
                {approvals.isFetching && payload && <p className="text-sm text-gray-500 dark:text-gray-400" role="status" aria-live="polite">Refreshing suspension approvals...</p>}
                {approvals.isLoading && <LoadingState />}
                {approvals.isError && !payload && (
                    <div role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-6 py-8 dark:border-red-900/60 dark:bg-red-950/30">
                        <h2 className="text-base font-semibold text-red-800 dark:text-red-200">Unable to load suspension approvals</h2>
                        <p className="mt-2 text-sm text-red-700 dark:text-red-300">{approvals.error?.message || "Please try again."}</p>
                        <button type="button" onClick={() => approvals.refetch()} className="mt-4 min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">Retry</button>
                    </div>
                )}
                {payload && requests.length === 0 && <EmptyState />}

                {payload && requests.length > 0 && (
                    <section aria-labelledby="suspension-results-title" className="space-y-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 id="suspension-results-title" className="text-lg font-semibold text-gray-900 dark:text-white">Suspension request queue</h2>
                                <p className="text-sm text-gray-500 dark:text-gray-400">{page?.total.toLocaleString()} request(s) in the selected view.</p>
                            </div>
                            {approvals.isStale && !approvals.isFetching && <p className="text-sm font-medium text-amber-700 dark:text-amber-300">Snapshot may be stale</p>}
                        </div>

                        <div className="hidden overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:block dark:border-gray-800 dark:bg-white/[0.03]">
                            <table className="w-full table-fixed text-left">
                                <caption className="sr-only">Suspension approval requests</caption>
                                <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400">
                                    <tr>
                                        <th scope="col" className="w-[18%] px-5 py-4 font-semibold">Employee</th>
                                        <th scope="col" className="w-[15%] px-3 py-4 font-semibold">Requester</th>
                                        <th scope="col" className="w-[19%] px-3 py-4 font-semibold">HR reason / evidence</th>
                                        <th scope="col" className="w-[12%] px-3 py-4 font-semibold">Age</th>
                                        <th scope="col" className="w-[16%] px-3 py-4 font-semibold">Stage</th>
                                        <th scope="col" className="w-[20%] px-5 py-4 font-semibold">Next action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {requests.map((request) => (
                                        <tr key={request.id} className="align-top transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold text-gray-900 dark:text-white">{request.name || "Unknown employee"}</p>
                                                <p className="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{request.position || request.email || "No employee context"}</p>
                                            </td>
                                            <td className="px-3 py-4 text-sm text-gray-700 dark:text-gray-300">{request.requested_by || "Not available"}</td>
                                            <td className="px-3 py-4">
                                                <p className="line-clamp-2 text-sm text-gray-700 dark:text-gray-300">{request.reason}</p>
                                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{request.evidence ? "Evidence attached" : "No evidence supplied"}</p>
                                            </td>
                                            <td className={`px-3 py-4 text-sm font-medium ${request.overdue ? "text-red-700 dark:text-red-300" : "text-gray-700 dark:text-gray-300"}`}>
                                                {request.age_days} day(s){request.overdue ? " - Overdue" : ""}
                                            </td>
                                            <td className="px-3 py-4"><StatusBadge value={request.workflow_status} /></td>
                                            <td className="px-5 py-4">
                                                <p className="mb-3 text-sm text-gray-700 dark:text-gray-300">{request.next_action}</p>
                                                <ActionButtons request={request} onView={setSelectedRequest} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid gap-4 lg:hidden">
                            {requests.map((request) => <SuspensionRequestCard key={request.id} request={request} onView={setSelectedRequest} />)}
                        </div>

                        {page && page.last_page > 1 && (
                            <nav className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/[0.03]" aria-label="Suspension approval pagination">
                                <p className="text-sm text-gray-600 dark:text-gray-400">Showing {page.from ?? 0}-{page.to ?? 0} of {page.total.toLocaleString()}</p>
                                <div className="flex items-center gap-2">
                                    <button type="button" onClick={() => goToPage(page.current_page - 1)} disabled={page.current_page <= 1 || approvals.isFetching} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Previous</button>
                                    <span className="px-2 text-sm font-medium text-gray-700 dark:text-gray-300" aria-current="page">Page {page.current_page} of {page.last_page}</span>
                                    <button type="button" onClick={() => goToPage(page.current_page + 1)} disabled={page.current_page >= page.last_page || approvals.isFetching} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Next</button>
                                </div>
                            </nav>
                        )}
                    </section>
                )}
            </main>

            {selectedRequest && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" role="presentation">
                    <div role="dialog" aria-modal="true" aria-labelledby="suspension-details-title" className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">Suspension request #{selectedRequest.id}</p>
                                <h2 id="suspension-details-title" className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{selectedRequest.name || "Unknown employee"}</h2>
                            </div>
                            <button type="button" onClick={() => setSelectedRequest(null)} aria-label="Close suspension request details" className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Close</button>
                        </div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <div><p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</p><div className="mt-1"><StatusBadge value={selectedRequest.workflow_status} /></div></div>
                            <div><p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Age / SLA</p><p className="mt-1 text-sm text-gray-800 dark:text-gray-200">{selectedRequest.age_days} day(s){selectedRequest.sla.configured ? ` / ${selectedRequest.sla.minutes} minutes` : " / age-based"}{selectedRequest.overdue ? " - overdue" : ""}</p></div>
                            <div><p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Requested by</p><p className="mt-1 text-sm text-gray-800 dark:text-gray-200">{selectedRequest.requested_by || "Not available"}</p></div>
                            <div><p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Requested at</p><p className="mt-1 text-sm text-gray-800 dark:text-gray-200">{formatDateTime(selectedRequest.requested_at)}</p></div>
                        </div>
                        <div className="mt-5 space-y-4 text-sm">
                            <div><h3 className="font-semibold text-gray-900 dark:text-white">HR suspension reason</h3><p className="mt-1 leading-6 text-gray-700 dark:text-gray-300">{selectedRequest.reason || "No reason supplied."}</p></div>
                            <div><h3 className="font-semibold text-gray-900 dark:text-white">HR evidence / details</h3><p className="mt-1 leading-6 text-gray-700 dark:text-gray-300">{selectedRequest.evidence || "No evidence supplied."}</p></div>
                            <div><h3 className="font-semibold text-gray-900 dark:text-white">Next action</h3><p className="mt-1 leading-6 text-gray-700 dark:text-gray-300">{selectedRequest.next_action}</p></div>
                            <div>
                                <h3 className="font-semibold text-gray-900 dark:text-white">Previous decisions</h3>
                                <ul className="mt-2 space-y-2">
                                    {selectedRequest.previous_decisions.map((decision) => <li key={decision.stage} className="rounded-lg border border-gray-200 px-3 py-2 text-gray-700 dark:border-gray-800 dark:text-gray-300"><span className="font-medium capitalize">{decision.stage}:</span> {decision.status || "pending"}{decision.reason ? ` - ${decision.reason}` : ""}{decision.at ? ` (${formatDateTime(decision.at)})` : ""}</li>)}
                                </ul>
                            </div>
                        </div>
                        {selectedRequest.workflow_status === "pending_manager" && (
                            <div className="mt-6 flex flex-col-reverse gap-2 border-t border-gray-200 pt-5 sm:flex-row sm:justify-end dark:border-gray-800">
                                <button
                                    type="button"
                                    onClick={() => openReject(selectedRequest)}
                                    disabled={processingId === selectedRequest.id}
                                    className="min-h-11 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30 dark:focus:ring-offset-gray-900"
                                >
                                    Reject request
                                </button>
                                <button
                                    type="button"
                                    onClick={() => approve(selectedRequest)}
                                    disabled={processingId === selectedRequest.id}
                                    className="min-h-11 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-offset-gray-900"
                                >
                                    Approve stage
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {requestToReject && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" role="presentation">
                    <div role="dialog" aria-modal="true" aria-labelledby="reject-suspension-title" className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900">
                        <h2 id="reject-suspension-title" className="text-lg font-semibold text-gray-900 dark:text-white">Reject suspension request</h2>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">Provide a reason for rejecting {requestToReject.name || "this request"}. The request will close at the Manager stage and the employee access state will be restored.</p>
                        <form onSubmit={reject} className="mt-5 space-y-4">
                            <div>
                                <label htmlFor="suspension-rejection-reason" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Reason <span aria-hidden="true">*</span></label>
                                <textarea id="suspension-rejection-reason" value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} required minLength={3} maxLength={1000} rows={4} className="w-full rounded-lg border border-gray-300 bg-white p-3 text-sm text-gray-900 outline-none focus:border-red-500 focus:ring-2 focus:ring-red-500/20 dark:border-gray-700 dark:bg-gray-950 dark:text-white" />
                            </div>
                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button type="button" onClick={() => setRequestToReject(null)} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">Cancel</button>
                                <button type="submit" disabled={rejectionReason.trim().length < 3 || processingId === requestToReject.id} className="min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-offset-gray-900">Confirm rejection</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayoutERP>
    );
}
