import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import {
    fetchManagerRepairerOptions,
    finalRejectManagerRepair,
    forwardManagerRepairToOwner,
    reassignManagerRepair,
    useManagerRepairJobs,
} from "../../../hooks/useManagerApi";
import type {
    ManagerRepairFilters,
    ManagerRepairJob,
    ManagerRepairerOption,
} from "../../../hooks/useManagerApi";

type RepairFilterForm = {
    status: string;
    assignment_state: string;
    repairer_id: string;
    search: string;
    date_from: string;
    date_to: string;
    review_pending: boolean;
    overdue: boolean;
};

const initialFilterForm: RepairFilterForm = {
    status: "",
    assignment_state: "",
    repairer_id: "",
    search: "",
    date_from: "",
    date_to: "",
    review_pending: false,
    overdue: false,
};

const statusLabel = (value: string): string => {
    if (!value) {
        return "Unknown";
    }

    return value
        .replace(/[_-]+/g, " ")
        .replace(/\b\w/g, (character) => character.toUpperCase());
};

const statusClasses = (value: string): string => {
    const normalized = value.toLowerCase();

    if (["completed", "ready_for_pickup", "picked_up"].includes(normalized)) {
        return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
    }

    if (["repairer_rejected", "reassignment_required", "awaiting_assignment"].includes(normalized)) {
        return "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300";
    }

    if (["rejected", "manager_rejected", "owner_rejected", "cancelled"].includes(normalized)) {
        return "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300";
    }

    return "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300";
};

const StatusBadge = ({ value, label }: { value: string; label?: string }) => (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(value)}`}>
        {label || statusLabel(value)}
    </span>
);

const formatAge = (minutes: number): string => {
    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours}h`;
    }

    return `${Math.floor(hours / 24)}d ${hours % 24}h`;
};

const formatDateTime = (value: string | null | undefined): string => {
    if (!value) {
        return "Not available";
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? "Not available"
        : date.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
};

const LoadingState = () => (
    <div className="space-y-3" aria-label="Loading repair jobs" aria-busy="true">
        {[1, 2, 3].map((item) => <div key={item} className="h-28 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />)}
    </div>
);

const EmptyState = () => (
    <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-white/[0.03]">
        <h2 className="text-base font-semibold text-gray-900 dark:text-white">No repair requests found</h2>
        <p className="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-400">There are no repair requests matching the current filters in your authorized shop.</p>
    </div>
);

const ErrorState = ({ message, onRetry }: { message: string; onRetry: () => void }) => (
    <div role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-6 py-8 dark:border-red-900/60 dark:bg-red-950/30">
        <h2 className="text-base font-semibold text-red-800 dark:text-red-200">Unable to load repair jobs</h2>
        <p className="mt-2 text-sm text-red-700 dark:text-red-300">{message}</p>
        <button type="button" onClick={onRetry} className="mt-4 min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">Retry</button>
    </div>
);

type DecisionMode = "reassign" | "final-reject" | "forward-owner";

const DecisionDialog = ({
    repair,
    mode,
    options,
    loadingOptions,
    replacementId,
    reason,
    submitting,
    error,
    onModeChange,
    onReplacementChange,
    onReasonChange,
    onSubmit,
    onClose,
}: {
    repair: ManagerRepairJob;
    mode: DecisionMode;
    options: ManagerRepairerOption[];
    loadingOptions: boolean;
    replacementId: string;
    reason: string;
    submitting: boolean;
    error: string | null;
    onModeChange: (mode: DecisionMode) => void;
    onReplacementChange: (value: string) => void;
    onReasonChange: (value: string) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onClose: () => void;
}) => {
    const isReassign = mode === "reassign";
    const isForward = mode === "forward-owner";
    const title = isReassign ? `Reassign ${repair.request_id}` : isForward ? `Forward ${repair.request_id}` : `Reject ${repair.request_id}`;
    const actionLabel = isReassign ? "Confirm reassignment" : isForward ? "Forward to Shop Owner" : "Confirm final rejection";

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="repair-decision-title">
            <form onSubmit={onSubmit} className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-400">Manager decision</p>
                        <h2 id="repair-decision-title" className="mt-2 text-xl font-bold text-gray-900 dark:text-white">{title}</h2>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">{isReassign ? "This is an exception handoff after rejection or repairer unavailability. It does not mean the Manager will perform the repair." : isForward ? "This option is available only when the shop policy explicitly requires a Shop Owner stage." : "This closes the repair request as rejected by Manager. It does not create a refund or other financial remedy."}</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Close repair decision dialog" className="min-h-11 min-w-11 rounded-lg text-2xl text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:hover:bg-gray-800">×</button>
                </div>

                <div className="mt-6 space-y-4">
                    {repair.requires_owner_approval && repair.status === "repairer_rejected" && <div className="flex flex-wrap gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><span className="font-semibold">Explicit Owner stage required.</span><button type="button" onClick={() => onModeChange("forward-owner")} className="font-semibold underline">Forward instead</button></div>}
                    {isReassign && <div><label htmlFor="repair-replacement" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Eligible replacement repairer</label><select id="repair-replacement" required value={replacementId} onChange={(event) => onReplacementChange(event.target.value)} disabled={loadingOptions || submitting} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">{loadingOptions ? "Loading eligible repairers…" : "Choose a repairer"}</option>{options.map((option) => <option key={option.id} value={option.id}>{option.name} · {option.workload} active repairs</option>)}</select>{!loadingOptions && options.length === 0 && <p className="mt-1.5 text-sm text-amber-700 dark:text-amber-300">No eligible replacement is available right now.</p>}</div>}
                    <div><label htmlFor="repair-decision-reason" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Reason <span aria-hidden="true">*</span></label><textarea id="repair-decision-reason" required minLength={3} value={reason} onChange={(event) => onReasonChange(event.target.value)} disabled={submitting} rows={4} placeholder={isReassign ? "Explain the rejection or unavailability exception" : isForward ? "Explain why Owner review is required" : "Explain why the shop is rejecting this request"} className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div>
                    {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">{error}</p>}
                </div>

                <div className="mt-6 flex flex-wrap justify-end gap-2"><button type="button" onClick={onClose} disabled={submitting} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button><button type="submit" disabled={submitting || (isReassign && (loadingOptions || options.length === 0))} className={`min-h-11 rounded-lg px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${isForward ? "bg-amber-700 hover:bg-amber-800 focus:ring-amber-500 dark:focus:ring-offset-gray-900" : "bg-blue-700 hover:bg-blue-800 focus:ring-blue-500 dark:focus:ring-offset-gray-900"}`}>{submitting ? "Saving…" : actionLabel}</button></div>
            </form>
        </div>
    );
};

export default function RepairJobs() {
    const [form, setForm] = useState<RepairFilterForm>(initialFilterForm);
    const [filters, setFilters] = useState<ManagerRepairFilters>({ page: 1, per_page: 25 });
    const repairs = useManagerRepairJobs(filters);
    const payload = repairs.data?.data;
    const rows = payload?.data ?? [];
    const [decisionTarget, setDecisionTarget] = useState<ManagerRepairJob | null>(null);
    const [decisionMode, setDecisionMode] = useState<DecisionMode>("reassign");
    const [options, setOptions] = useState<ManagerRepairerOption[]>([]);
    const [loadingOptions, setLoadingOptions] = useState(false);
    const [replacementId, setReplacementId] = useState("");
    const [reason, setReason] = useState("");
    const [actionError, setActionError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!decisionTarget || decisionMode !== "reassign") {
            setOptions([]);
            setLoadingOptions(false);
            return;
        }

        let cancelled = false;
        setLoadingOptions(true);
        setOptions([]);
        setReplacementId("");
        setReason("");
        setActionError(null);

        fetchManagerRepairerOptions(decisionTarget.id)
            .then((repairers) => {
                if (!cancelled) {
                    setOptions(repairers);
                }
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setActionError(error instanceof Error ? error.message : "Unable to load eligible repairers.");
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingOptions(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [decisionTarget, decisionMode]);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFilters({ ...form, page: 1, per_page: 25 });
    };

    const clearFilters = () => {
        setForm(initialFilterForm);
        setFilters({ page: 1, per_page: 25 });
    };

    const openDecision = (repair: ManagerRepairJob, mode: DecisionMode) => {
        setDecisionTarget(repair);
        setDecisionMode(mode);
        setActionError(null);
    };

    const closeDecision = () => {
        if (submitting) {
            return;
        }

        setDecisionTarget(null);
        setActionError(null);
    };

    const submitDecision = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!decisionTarget || reason.trim().length < 3 || (decisionMode === "reassign" && !replacementId)) {
            setActionError("Choose an eligible repairer when reassigning and provide a reason.");
            return;
        }

        setSubmitting(true);
        setActionError(null);
        try {
            if (decisionMode === "reassign") {
                await reassignManagerRepair(decisionTarget.id, Number(replacementId), reason.trim());
            } else if (decisionMode === "forward-owner") {
                await forwardManagerRepairToOwner(decisionTarget.id, reason.trim());
            } else {
                await finalRejectManagerRepair(decisionTarget.id, reason.trim());
            }

            setDecisionTarget(null);
            await repairs.refetch();
        } catch (error: unknown) {
            setActionError(error instanceof Error ? error.message : "Repair decision was not completed.");
        } finally {
            setSubmitting(false);
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
            <Head title="Repair Jobs - Solespace ERP" />

            <main className="space-y-6 py-6 md:py-8" aria-labelledby="manager-repair-jobs-title">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"><div><p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Operations</p><h1 id="manager-repair-jobs-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Repair Jobs</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">See every repair request and each repairer’s active workload. New requests are autoassigned by workload; Manager decisions are limited to rejection and unavailability exceptions.</p></div><div className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"><p className="font-semibold text-gray-900 dark:text-white">{payload?.total ?? 0} repairs in view</p><p className="mt-1 text-gray-500 dark:text-gray-400">Last updated: {formatDateTime(repairs.data?.last_updated_at)}</p></div></header>

                <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="repair-filters-title"><div className="mb-4"><h2 id="repair-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter repair workload</h2><p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Review pending includes repairer rejections and repairer-unavailability exceptions.</p></div><form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6"><div className="xl:col-span-2"><label htmlFor="repair-search" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label><input id="repair-search" type="search" value={form.search} onChange={(event) => setForm((current) => ({ ...current, search: event.target.value }))} placeholder="Request ID or customer" className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div><div><label htmlFor="repair-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label><select id="repair-status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">All statuses</option><option value="assigned_to_repairer">Assigned</option><option value="in_progress">In progress</option><option value="repairer_rejected">Repairer rejected</option><option value="reassignment_required">Reassignment required</option><option value="awaiting_assignment">Awaiting assignment</option><option value="rejected">Rejected</option></select></div><div><label htmlFor="repair-assignment-state" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Assignment</label><select id="repair-assignment-state" value={form.assignment_state} onChange={(event) => setForm((current) => ({ ...current, assignment_state: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">All assignment states</option><option value="reassignment_required">Reassignment required</option><option value="awaiting_assignment">Awaiting assignment</option></select></div><div><label htmlFor="repairer-id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Repairer ID</label><input id="repairer-id" inputMode="numeric" value={form.repairer_id} onChange={(event) => setForm((current) => ({ ...current, repairer_id: event.target.value }))} placeholder="Optional" className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div><div><label htmlFor="repair-date-from" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">From</label><input id="repair-date-from" type="date" value={form.date_from} onChange={(event) => setForm((current) => ({ ...current, date_from: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div><div><label htmlFor="repair-date-to" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">To</label><input id="repair-date-to" type="date" value={form.date_to} onChange={(event) => setForm((current) => ({ ...current, date_to: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div><label className="flex min-h-11 items-center gap-2 self-end text-sm font-medium text-gray-700 dark:text-gray-300"><input type="checkbox" checked={form.review_pending} onChange={(event) => setForm((current) => ({ ...current, review_pending: event.target.checked }))} className="h-4 w-4 rounded border-gray-300 text-blue-700 focus:ring-blue-500" />Review pending</label><label className="flex min-h-11 items-center gap-2 self-end text-sm font-medium text-gray-700 dark:text-gray-300"><input type="checkbox" checked={form.overdue} onChange={(event) => setForm((current) => ({ ...current, overdue: event.target.checked }))} className="h-4 w-4 rounded border-gray-300 text-blue-700 focus:ring-blue-500" />Overdue only</label><div className="flex items-end gap-2 md:col-span-2 xl:col-span-6"><button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-offset-gray-950">Apply filters</button><button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Clear</button></div></form></section>

                {repairs.isError && payload && <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">The latest refresh failed. Showing the last successful snapshot. <button type="button" onClick={() => repairs.refetch()} className="font-semibold underline">Retry</button></div>}
                {repairs.isFetching && payload && <p role="status" aria-live="polite" className="text-sm text-gray-500 dark:text-gray-400">Refreshing repair snapshot…</p>}
                {repairs.isLoading && <LoadingState />}
                {repairs.isError && !payload && <ErrorState message={repairs.error?.message || "Please try again."} onRetry={() => repairs.refetch()} />}
                {payload && rows.length === 0 && <EmptyState />}

                {payload && rows.length > 0 && <section aria-labelledby="repair-results-title" className="space-y-4"><div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="repair-results-title" className="text-lg font-semibold text-gray-900 dark:text-white">All repairer workloads</h2><p className="text-sm text-gray-500 dark:text-gray-400">Showing {payload.from ?? 0}–{payload.to ?? 0} of {payload.total} requests</p></div>{repairs.isStale && !repairs.isFetching && <p className="text-sm font-medium text-amber-700 dark:text-amber-300">Snapshot may be stale</p>}</div>

                    <div className="hidden overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:block dark:border-gray-800 dark:bg-white/[0.03]"><table className="w-full table-fixed text-left"><caption className="sr-only">Manager repair job workload</caption><thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400"><tr><th scope="col" className="w-[17%] px-5 py-4 font-semibold">Request</th><th scope="col" className="w-[15%] px-3 py-4 font-semibold">Customer / item</th><th scope="col" className="w-[16%] px-3 py-4 font-semibold">Status</th><th scope="col" className="w-[19%] px-3 py-4 font-semibold">Repairer / workload</th><th scope="col" className="w-[18%] px-3 py-4 font-semibold">Age / next action</th><th scope="col" className="w-[15%] px-5 py-4 font-semibold">Decision</th></tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{rows.map((repair) => { const needsDecision = repair.review_state === "pending_manager_review"; return <tr key={repair.id} className="align-top hover:bg-gray-50 dark:hover:bg-gray-900/40"><td className="px-5 py-4"><p className="font-semibold text-gray-900 dark:text-white">{repair.request_id}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{formatDateTime(repair.created_at)}</p></td><td className="px-3 py-4"><p className="text-sm text-gray-700 dark:text-gray-300">{repair.customer_name}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{[repair.brand, repair.shoe_type].filter(Boolean).join(" · ") || "Item details unavailable"}</p></td><td className="px-3 py-4"><StatusBadge value={repair.status} label={repair.display_status} /><p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{statusLabel(repair.assignment_state)}</p>{repair.rejection_reason && <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">Reason: {repair.rejection_reason}</p>}</td><td className="px-3 py-4"><p className="text-sm font-medium text-gray-900 dark:text-white">{repair.assigned_repairer?.name || "No repairer assigned"}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{repair.assigned_repairer ? `${repair.repairer_workload} active repairs` : "Awaiting eligible repairer"}</p>{repair.reassignment_reason_label && <p className="mt-1 text-xs font-medium text-red-700 dark:text-red-300">{repair.reassignment_reason_label}</p>}</td><td className="px-3 py-4"><p className={`text-sm font-semibold ${repair.overdue ? "text-red-700 dark:text-red-300" : "text-gray-900 dark:text-white"}`}>{formatAge(repair.age_minutes)}{repair.overdue ? " · Overdue" : ""}</p><p className="mt-1 text-xs text-gray-600 dark:text-gray-400">{repair.next_action}</p></td><td className="px-5 py-4"><div className="flex flex-col items-start gap-2">{needsDecision ? <><button type="button" onClick={() => openDecision(repair, "reassign")} className="min-h-11 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">Reassign</button>{repair.requires_owner_approval ? <button type="button" onClick={() => openDecision(repair, "forward-owner")} className="min-h-11 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-950/30">Forward to Owner</button> : <button type="button" onClick={() => openDecision(repair, "final-reject")} className="min-h-11 rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30">Final reject</button>}</> : repair.review_state === "pending_owner_review" ? <span className="text-sm text-amber-700 dark:text-amber-300">Waiting for Shop Owner</span> : <span className="text-sm text-gray-500 dark:text-gray-400">No Manager decision</span>}</div></td></tr>; })}</tbody></table></div>

                    <div className="space-y-3 lg:hidden">{rows.map((repair) => { const needsDecision = repair.review_state === "pending_manager_review"; return <article key={repair.id} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"><div className="flex items-start justify-between gap-3"><div><h3 className="font-semibold text-gray-900 dark:text-white">{repair.request_id}</h3><p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{repair.customer_name}</p></div><StatusBadge value={repair.status} label={repair.display_status} /></div><dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt className="text-xs uppercase tracking-wide text-gray-500">Repairer</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{repair.assigned_repairer?.name || "Awaiting assignment"}</dd></div><div><dt className="text-xs uppercase tracking-wide text-gray-500">Workload</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{repair.assigned_repairer ? `${repair.repairer_workload} active` : "—"}</dd></div><div><dt className="text-xs uppercase tracking-wide text-gray-500">Age</dt><dd className={`mt-1 ${repair.overdue ? "font-semibold text-red-700" : "text-gray-700 dark:text-gray-300"}`}>{formatAge(repair.age_minutes)}</dd></div><div><dt className="text-xs uppercase tracking-wide text-gray-500">Assignment</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{statusLabel(repair.assignment_state)}</dd></div><div className="col-span-2"><dt className="text-xs uppercase tracking-wide text-gray-500">Next action</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{repair.next_action}</dd></div></dl>{repair.rejection_reason && <p className="mt-3 text-sm text-amber-700 dark:text-amber-300">Rejection reason: {repair.rejection_reason}</p>}<div className="mt-4 flex flex-wrap gap-2">{needsDecision ? <><button type="button" onClick={() => openDecision(repair, "reassign")} className="min-h-11 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white">Reassign</button>{repair.requires_owner_approval ? <button type="button" onClick={() => openDecision(repair, "forward-owner")} className="min-h-11 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 dark:border-amber-800 dark:text-amber-300">Forward to Owner</button> : <button type="button" onClick={() => openDecision(repair, "final-reject")} className="min-h-11 rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 dark:border-red-800 dark:text-red-300">Final reject</button>}</> : repair.review_state === "pending_owner_review" ? <span className="self-center text-sm text-amber-700 dark:text-amber-300">Waiting for Shop Owner</span> : <span className="self-center text-sm text-gray-500 dark:text-gray-400">No Manager decision</span>}</div></article>; })}</div>

                    <div className="flex items-center justify-between gap-3"><p className="text-sm text-gray-500 dark:text-gray-400">Page {payload.current_page} of {payload.last_page}</p><div className="flex gap-2"><button type="button" disabled={payload.current_page <= 1} onClick={() => goToPage(payload.current_page - 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Previous</button><button type="button" disabled={payload.current_page >= payload.last_page} onClick={() => goToPage(payload.current_page + 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Next</button></div></div>
                </section>}
            </main>

            {decisionTarget && <DecisionDialog repair={decisionTarget} mode={decisionMode} options={options} loadingOptions={loadingOptions} replacementId={replacementId} reason={reason} submitting={submitting} error={actionError} onModeChange={setDecisionMode} onReplacementChange={setReplacementId} onReasonChange={setReason} onSubmit={submitDecision} onClose={closeDecision} />}
        </AppLayoutERP>
    );
}
