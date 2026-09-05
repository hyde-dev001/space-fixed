import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import {
    fetchManagerOrderReplacements,
    reassignManagerOrder,
    useManagerOrders,
} from "../../../hooks/useManagerApi";
import type {
    ManagerOrder,
    ManagerOrderFilters,
    ManagerOrderReplacement,
} from "../../../hooks/useManagerApi";

type OrderFilterForm = {
    status: string;
    assignment_state: string;
    handler_id: string;
    date_from: string;
    date_to: string;
    overdue: boolean;
};

const initialFilterForm: OrderFilterForm = {
    status: "",
    assignment_state: "",
    handler_id: "",
    date_from: "",
    date_to: "",
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

    if (["completed", "delivered"].includes(normalized)) {
        return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
    }

    if (["cancelled", "refund"].includes(normalized)) {
        return "border-gray-200 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300";
    }

    if (normalized === "reassignment_required") {
        return "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300";
    }

    return "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300";
};

const StatusBadge = ({ value }: { value: string }) => (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(value)}`}>
        {statusLabel(value)}
    </span>
);

const formatAge = (minutes: number): string => {
    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    if (hours < 24) {
        return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
    }

    const days = Math.floor(hours / 24);
    return `${days}d ${hours % 24}h`;
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
    <div className="space-y-3" aria-label="Loading job orders" aria-busy="true">
        {[1, 2, 3].map((item) => (
            <div key={item} className="h-24 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
        ))}
    </div>
);

const EmptyState = () => (
    <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-white/[0.03]">
        <h2 className="text-base font-semibold text-gray-900 dark:text-white">No job orders found</h2>
        <p className="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-400">
            There are no orders matching the current filters in your authorized shop.
        </p>
    </div>
);

const ErrorState = ({ message, onRetry }: { message: string; onRetry: () => void }) => (
    <div role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-6 py-8 dark:border-red-900/60 dark:bg-red-950/30">
        <h2 className="text-base font-semibold text-red-800 dark:text-red-200">Unable to load job orders</h2>
        <p className="mt-2 text-sm text-red-700 dark:text-red-300">{message}</p>
        <button
            type="button"
            onClick={onRetry}
            className="mt-4 min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950"
        >
            Retry
        </button>
    </div>
);

const OrderDetail = ({ order, onClose, onReassign }: {
    order: ManagerOrder;
    onClose: () => void;
    onReassign: () => void;
}) => (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="order-detail-title">
        <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-400">Order detail</p>
                    <h2 id="order-detail-title" className="mt-2 text-xl font-bold text-gray-900 dark:text-white">{order.order_number}</h2>
                </div>
                <button type="button" onClick={onClose} aria-label="Close order detail" className="min-h-11 min-w-11 rounded-lg text-2xl text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:hover:bg-gray-800">×</button>
            </div>

            <dl className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Customer</dt><dd className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{order.customer_name}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Status</dt><dd className="mt-1"><StatusBadge value={order.status} /></dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Current handler</dt><dd className="mt-1 text-sm text-gray-700 dark:text-gray-300">{order.assigned_staff?.name || "Unassigned"}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Processing lock</dt><dd className="mt-1 text-sm text-gray-700 dark:text-gray-300">{order.lock_state === "locked" ? "Locked to current handler" : "Claimable"}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Age</dt><dd className={`mt-1 text-sm ${order.overdue ? "font-semibold text-red-700 dark:text-red-300" : "text-gray-700 dark:text-gray-300"}`}>{formatAge(order.age_minutes)}{order.overdue ? " · Overdue" : ""}</dd></div>
                <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Created</dt><dd className="mt-1 text-sm text-gray-700 dark:text-gray-300">{formatDateTime(order.created_at)}</dd></div>
            </dl>

            <div className="mt-6 rounded-xl bg-gray-50 p-4 dark:bg-gray-800/70">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Next action</p>
                <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">{order.next_action}</p>
                {order.reassignment_reason_label && <p className="mt-2 text-sm font-medium text-red-700 dark:text-red-300">{order.reassignment_reason_label}</p>}
            </div>

            <div className="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" onClick={onClose} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Close</button>
                {order.assignment_state === "reassignment_required" && <button type="button" onClick={onReassign} className="min-h-11 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">Reassign order</button>}
            </div>
        </div>
    </div>
);

const ReassignDialog = ({
    order,
    options,
    loadingOptions,
    reason,
    replacementId,
    submitting,
    error,
    onReasonChange,
    onReplacementChange,
    onSubmit,
    onClose,
}: {
    order: ManagerOrder;
    options: ManagerOrderReplacement[];
    loadingOptions: boolean;
    reason: string;
    replacementId: string;
    submitting: boolean;
    error: string | null;
    onReasonChange: (value: string) => void;
    onReplacementChange: (value: string) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onClose: () => void;
}) => (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="reassign-order-title">
        <form onSubmit={onSubmit} className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-400">Exception handoff</p>
                    <h2 id="reassign-order-title" className="mt-2 text-xl font-bold text-gray-900 dark:text-white">Reassign {order.order_number}</h2>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">This action is available because the current handler is no longer eligible to continue. The processing lock and handoff history will be preserved.</p>
                </div>
                <button type="button" onClick={onClose} aria-label="Close reassignment dialog" className="min-h-11 min-w-11 rounded-lg text-2xl text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:hover:bg-gray-800">×</button>
            </div>

            <div className="mt-6 space-y-4">
                <div>
                    <label htmlFor="order-replacement" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Eligible replacement</label>
                    <select id="order-replacement" required value={replacementId} onChange={(event) => onReplacementChange(event.target.value)} disabled={loadingOptions || submitting} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                        <option value="">{loadingOptions ? "Loading eligible staff…" : "Choose a staff member"}</option>
                        {options.map((option) => <option key={option.id} value={option.id}>{option.name} · {option.workload} active orders</option>)}
                    </select>
                    {!loadingOptions && options.length === 0 && <p className="mt-1.5 text-sm text-amber-700 dark:text-amber-300">No eligible replacement is available right now.</p>}
                </div>
                <div>
                    <label htmlFor="order-reassignment-reason" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Reason <span aria-hidden="true">*</span></label>
                    <textarea id="order-reassignment-reason" required minLength={3} value={reason} onChange={(event) => onReasonChange(event.target.value)} disabled={submitting} rows={4} placeholder="Explain why the current handler cannot continue" className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                </div>
                {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300">{error}</p>}
            </div>

            <div className="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" onClick={onClose} disabled={submitting} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
                <button type="submit" disabled={submitting || loadingOptions || options.length === 0} className="min-h-11 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-offset-gray-900">{submitting ? "Reassigning…" : "Confirm reassignment"}</button>
            </div>
        </form>
    </div>
);

export default function JobOrders() {
    const [form, setForm] = useState<OrderFilterForm>(initialFilterForm);
    const [filters, setFilters] = useState<ManagerOrderFilters>({ page: 1, per_page: 25 });
    const orders = useManagerOrders(filters);
    const payload = orders.data?.data;
    const rows = payload?.data ?? [];
    const [detailOrder, setDetailOrder] = useState<ManagerOrder | null>(null);
    const [reassignTarget, setReassignTarget] = useState<ManagerOrder | null>(null);
    const [replacementOptions, setReplacementOptions] = useState<ManagerOrderReplacement[]>([]);
    const [loadingOptions, setLoadingOptions] = useState(false);
    const [replacementId, setReplacementId] = useState("");
    const [reason, setReason] = useState("");
    const [actionError, setActionError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!reassignTarget) {
            return;
        }

        let cancelled = false;
        setLoadingOptions(true);
        setReplacementOptions([]);
        setReplacementId("");
        setReason("");
        setActionError(null);

        fetchManagerOrderReplacements(reassignTarget.id)
            .then((options) => {
                if (!cancelled) {
                    setReplacementOptions(options);
                }
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setActionError(error instanceof Error ? error.message : "Unable to load eligible staff.");
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
    }, [reassignTarget]);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFilters({ ...form, page: 1, per_page: 25 });
    };

    const clearFilters = () => {
        setForm(initialFilterForm);
        setFilters({ page: 1, per_page: 25 });
    };

    const closeReassign = () => {
        if (submitting) {
            return;
        }

        setReassignTarget(null);
        setActionError(null);
    };

    const openReassign = (order: ManagerOrder) => {
        setDetailOrder(null);
        setReassignTarget(order);
    };

    const submitReassignment = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!reassignTarget || !replacementId || reason.trim().length < 3) {
            setActionError("Choose an eligible replacement and provide a reason.");
            return;
        }

        setSubmitting(true);
        setActionError(null);
        try {
            await reassignManagerOrder(reassignTarget.id, Number(replacementId), reason.trim());
            setReassignTarget(null);
            await orders.refetch();
        } catch (error: unknown) {
            setActionError(error instanceof Error ? error.message : "Order reassignment was not completed.");
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
            <Head title="Job Orders - Solespace ERP" />

            <main className="space-y-6 py-6 md:py-8" aria-labelledby="manager-job-orders-title">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Operations</p>
                        <h1 id="manager-job-orders-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Job Orders</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">Monitor the shop-wide order workload. A claimed order remains locked to its handler until an inactive or unavailable handler is formally replaced.</p>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="font-semibold text-gray-900 dark:text-white">{payload?.total ?? 0} orders in view</p>
                        <p className="mt-1 text-gray-500 dark:text-gray-400">Last updated: {formatDateTime(orders.data?.last_updated_at)}</p>
                    </div>
                </header>

                <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="order-filters-title">
                    <div className="mb-4"><h2 id="order-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter job orders</h2><p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Use reassignment required to find work whose current handler is no longer eligible.</p></div>
                    <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
                        <div className="xl:col-span-2"><label htmlFor="order-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label><select id="order-status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">All statuses</option><option value="pending">Pending</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                        <div className="xl:col-span-2"><label htmlFor="order-assignment-state" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Assignment</label><select id="order-assignment-state" value={form.assignment_state} onChange={(event) => setForm((current) => ({ ...current, assignment_state: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">All assignments</option><option value="unassigned">Pending / unassigned</option><option value="assigned">Assigned and locked</option><option value="reassignment_required">Reassignment required</option></select></div>
                        <div><label htmlFor="order-handler" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Handler ID</label><input id="order-handler" inputMode="numeric" value={form.handler_id} onChange={(event) => setForm((current) => ({ ...current, handler_id: event.target.value }))} placeholder="Optional" className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div>
                        <div><label htmlFor="order-date-from" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">From</label><input id="order-date-from" type="date" value={form.date_from} onChange={(event) => setForm((current) => ({ ...current, date_from: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div>
                        <div><label htmlFor="order-date-to" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">To</label><input id="order-date-to" type="date" value={form.date_to} onChange={(event) => setForm((current) => ({ ...current, date_to: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></div>
                        <label className="flex min-h-11 items-center gap-2 self-end text-sm font-medium text-gray-700 dark:text-gray-300"><input type="checkbox" checked={form.overdue} onChange={(event) => setForm((current) => ({ ...current, overdue: event.target.checked }))} className="h-4 w-4 rounded border-gray-300 text-blue-700 focus:ring-blue-500" />Overdue only</label>
                        <div className="flex items-end gap-2 md:col-span-2 xl:col-span-6"><button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-offset-gray-950">Apply filters</button><button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Clear</button></div>
                    </form>
                </section>

                {orders.isError && payload && <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">The latest refresh failed. Showing the last successful snapshot. <button type="button" onClick={() => orders.refetch()} className="font-semibold underline">Retry</button></div>}
                {orders.isFetching && payload && <p role="status" aria-live="polite" className="text-sm text-gray-500 dark:text-gray-400">Refreshing job order snapshot…</p>}
                {orders.isLoading && <LoadingState />}
                {orders.isError && !payload && <ErrorState message={orders.error?.message || "Please try again."} onRetry={() => orders.refetch()} />}
                {payload && rows.length === 0 && <EmptyState />}

                {payload && rows.length > 0 && <section aria-labelledby="order-results-title" className="space-y-4">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"><div><h2 id="order-results-title" className="text-lg font-semibold text-gray-900 dark:text-white">Current order workload</h2><p className="text-sm text-gray-500 dark:text-gray-400">Showing {payload.from ?? 0}–{payload.to ?? 0} of {payload.total} orders</p></div>{orders.isStale && !orders.isFetching && <p className="text-sm font-medium text-amber-700 dark:text-amber-300">Snapshot may be stale</p>}</div>

                    <div className="hidden overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:block dark:border-gray-800 dark:bg-white/[0.03]"><table className="w-full table-fixed text-left"><caption className="sr-only">Manager job order workload</caption><thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400"><tr><th scope="col" className="w-[17%] px-5 py-4 font-semibold">Order</th><th scope="col" className="w-[17%] px-3 py-4 font-semibold">Customer</th><th scope="col" className="w-[17%] px-3 py-4 font-semibold">Status</th><th scope="col" className="w-[20%] px-3 py-4 font-semibold">Handler / lock</th><th scope="col" className="w-[16%] px-3 py-4 font-semibold">Age / next action</th><th scope="col" className="w-[13%] px-5 py-4 font-semibold">Review</th></tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{rows.map((order) => <tr key={order.id} className="align-top hover:bg-gray-50 dark:hover:bg-gray-900/40"><td className="px-5 py-4"><p className="font-semibold text-gray-900 dark:text-white">{order.order_number}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{formatDateTime(order.created_at)}</p></td><td className="px-3 py-4 text-sm text-gray-700 dark:text-gray-300">{order.customer_name}</td><td className="px-3 py-4"><StatusBadge value={order.status} /><p className="mt-2 text-xs text-gray-500 dark:text-gray-400">{statusLabel(order.assignment_state)}</p></td><td className="px-3 py-4"><p className="text-sm font-medium text-gray-900 dark:text-white">{order.assigned_staff?.name || "Unassigned"}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{order.lock_state === "locked" ? "Processing locked" : "Pending claim"}</p>{order.reassignment_reason_label && <p className="mt-1 text-xs font-medium text-red-700 dark:text-red-300">{order.reassignment_reason_label}</p>}</td><td className="px-3 py-4"><p className={`text-sm font-semibold ${order.overdue ? "text-red-700 dark:text-red-300" : "text-gray-900 dark:text-white"}`}>{formatAge(order.age_minutes)}{order.overdue ? " · Overdue" : ""}</p><p className="mt-1 text-xs text-gray-600 dark:text-gray-400">{order.next_action}</p></td><td className="px-5 py-4"><div className="flex flex-col items-start gap-2"><button type="button" onClick={() => setDetailOrder(order)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">View details</button>{order.assignment_state === "reassignment_required" && <button type="button" onClick={() => openReassign(order)} className="min-h-11 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">Reassign</button>}</div></td></tr>)}</tbody></table></div>

                    <div className="space-y-3 lg:hidden">{rows.map((order) => <article key={order.id} className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"><div className="flex items-start justify-between gap-3"><div><h3 className="font-semibold text-gray-900 dark:text-white">{order.order_number}</h3><p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{order.customer_name}</p></div><StatusBadge value={order.status} /></div><dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt className="text-xs uppercase tracking-wide text-gray-500">Handler</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{order.assigned_staff?.name || "Unassigned"}</dd></div><div><dt className="text-xs uppercase tracking-wide text-gray-500">Age</dt><dd className={`mt-1 ${order.overdue ? "font-semibold text-red-700" : "text-gray-700 dark:text-gray-300"}`}>{formatAge(order.age_minutes)}</dd></div><div className="col-span-2"><dt className="text-xs uppercase tracking-wide text-gray-500">Next action</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{order.next_action}</dd></div></dl><div className="mt-4 flex flex-wrap gap-2"><button type="button" onClick={() => setDetailOrder(order)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-300">View details</button>{order.assignment_state === "reassignment_required" && <button type="button" onClick={() => openReassign(order)} className="min-h-11 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white">Reassign</button>}</div></article>)}</div>

                    <div className="flex items-center justify-between gap-3"><p className="text-sm text-gray-500 dark:text-gray-400">Page {payload.current_page} of {payload.last_page}</p><div className="flex gap-2"><button type="button" disabled={payload.current_page <= 1} onClick={() => goToPage(payload.current_page - 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Previous</button><button type="button" disabled={payload.current_page >= payload.last_page} onClick={() => goToPage(payload.current_page + 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Next</button></div></div>
                </section>}
            </main>

            {detailOrder && <OrderDetail order={detailOrder} onClose={() => setDetailOrder(null)} onReassign={() => openReassign(detailOrder)} />}
            {reassignTarget && <ReassignDialog order={reassignTarget} options={replacementOptions} loadingOptions={loadingOptions} reason={reason} replacementId={replacementId} submitting={submitting} error={actionError} onReasonChange={setReason} onReplacementChange={setReplacementId} onSubmit={submitReassignment} onClose={closeReassign} />}
        </AppLayoutERP>
    );
}
