import { Head, Link, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { useManagerStaffWorkload } from "../../../hooks/useManagerApi";
import type { ManagerStaffWorkload, ManagerStaffWorkloadFilters } from "../../../hooks/useManagerApi";
import { getManagerBusinessCapabilities } from "../../../utils/managerBusinessCapabilities";

type WorkloadFilterForm = {
    search: string;
    role: string;
    status: string;
    date_from: string;
    date_to: string;
};

const initialFilterForm: WorkloadFilterForm = {
    search: "",
    role: "",
    status: "",
    date_from: "",
    date_to: "",
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

    if (["active", "available"].includes(normalized)) {
        return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
    }

    if (["inactive", "suspended", "terminated", "resigned", "offboarded"].includes(normalized)) {
        return "border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300";
    }

    if (["approved_leave", "explicitly_unavailable", "reassignment_required"].includes(normalized)) {
        return "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300";
    }

    return "border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300";
};

const StatusBadge = ({ value }: { value: string }) => (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${statusClasses(value)}`}>
        {statusLabel(value)}
    </span>
);

const NumberMetric = ({ label, value, alert = false }: { label: string; value: number; alert?: boolean }) => (
    <div className="min-w-0">
        <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className={`mt-1 text-lg font-semibold ${alert ? "text-amber-700 dark:text-amber-300" : "text-gray-900 dark:text-white"}`}>
            {value.toLocaleString()}
        </p>
    </div>
);

const initials = (name: string): string => {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return "?";
    }

    return parts
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? "")
        .join("");
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

const ActionLinks = ({ staff, canRetail, canRepair }: {
    staff: ManagerStaffWorkload;
    canRetail: boolean;
    canRepair: boolean;
}) => {
    const links: Array<{ href: string; label: string }> = [];

    if (canRetail && staff.requires_order_reassignment) {
        links.push({ href: staff.links.orders, label: "Review order work" });
    }

    if (canRepair && staff.requires_repair_reassignment) {
        links.push({ href: staff.links.repairs, label: "Review repair work" });
    }

    if (links.length === 0) {
        return <span className="text-sm text-gray-500 dark:text-gray-400">No exception</span>;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {links.map((link) => (
                <Link
                    key={link.href}
                    href={link.href}
                    className="inline-flex min-h-11 items-center rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-950/40 dark:focus:ring-offset-gray-950"
                >
                    {link.label}
                </Link>
            ))}
        </div>
    );
};

const StaffWorkloadCard = ({ staff, canRetail, canRepair }: {
    staff: ManagerStaffWorkload;
    canRetail: boolean;
    canRepair: boolean;
}) => (
    <article className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-start justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gray-900 text-sm font-semibold text-white dark:bg-white dark:text-gray-900">
                    {initials(staff.name)}
                </div>
                <div className="min-w-0">
                    <h3 className="truncate text-base font-semibold text-gray-900 dark:text-white">{staff.name}</h3>
                    <p className="truncate text-sm text-gray-500 dark:text-gray-400">{staff.position || staff.role}</p>
                </div>
            </div>
            <StatusBadge value={staff.status} />
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span>{statusLabel(staff.role)}</span>
            <span aria-hidden="true">•</span>
            <span>{statusLabel(staff.availability_state)}</span>
        </div>

        <div className="mt-4 grid grid-cols-2 gap-4 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/60">
            {canRetail && <NumberMetric label="Active orders" value={staff.active_orders} />}
            {canRepair && <NumberMetric label="Active repairs" value={staff.active_repairs} />}
            <NumberMetric label="Overdue" value={staff.overdue_work} alert={staff.overdue_work > 0} />
            <NumberMetric label="Total active" value={staff.total_active_work} />
        </div>

        <div className="mt-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Next action</p>
            <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">{staff.next_action}</p>
            {staff.reassignment_reason && (
                <p className="mt-1 text-sm font-medium text-amber-700 dark:text-amber-300">{staff.reassignment_reason}</p>
            )}
        </div>

        <div className="mt-4">
            <ActionLinks staff={staff} canRetail={canRetail} canRepair={canRepair} />
        </div>
    </article>
);

const LoadingState = () => (
    <div className="space-y-3" aria-label="Loading staff workload" aria-busy="true">
        {[1, 2, 3].map((item) => (
            <div key={item} className="h-20 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
        ))}
    </div>
);

const EmptyState = () => (
    <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-white/[0.03]">
        <h2 className="text-base font-semibold text-gray-900 dark:text-white">No staff found</h2>
        <p className="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-400">
            Try clearing a filter or search for another staff member. This page shows operational staff and repairers in your authorized shop.
        </p>
    </div>
);

export default function StaffWorkload() {
    const { props } = usePage<{
        auth?: {
            business_type?: string | null;
            shop_owner?: { business_type?: string | null };
            user?: {
                business_type?: string | null;
                shop_owner?: { business_type?: string | null };
            };
        };
    }>();
    const authBusinessType = props.auth?.shop_owner?.business_type
        ?? props.auth?.user?.shop_owner?.business_type
        ?? props.auth?.business_type
        ?? props.auth?.user?.business_type;
    const [form, setForm] = useState<WorkloadFilterForm>(initialFilterForm);
    const [filters, setFilters] = useState<ManagerStaffWorkloadFilters>({ per_page: 25, page: 1 });
    const workload = useManagerStaffWorkload(filters);
    const payload = workload.data;
    const rows = payload?.data.data ?? [];
    const pagination = payload?.data;
    const authCapabilities = getManagerBusinessCapabilities(authBusinessType);
    const businessCapabilities = payload?.business_capabilities
        ? {
            canRetail: payload.business_capabilities.can_retail,
            canRepair: payload.business_capabilities.can_repair,
        }
        : authCapabilities;
    const { canRetail, canRepair } = businessCapabilities;

    const visibleSummary = useMemo(() => rows.reduce(
        (summary, staff) => ({
            activeOrders: summary.activeOrders + (canRetail ? staff.active_orders : 0),
            activeRepairs: summary.activeRepairs + (canRepair ? staff.active_repairs : 0),
            exceptions: summary.exceptions + Number(
                (canRetail && staff.requires_order_reassignment)
                || (canRepair && staff.requires_repair_reassignment),
            ),
        }),
        { activeOrders: 0, activeRepairs: 0, exceptions: 0 },
    ), [canRepair, canRetail, rows]);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFilters({
            ...form,
            page: 1,
            per_page: 25,
        });
    };

    const clearFilters = () => {
        setForm(initialFilterForm);
        setFilters({ per_page: 25, page: 1 });
    };

    const goToPage = (page: number) => {
        if (!pagination || page < 1 || page > pagination.last_page || page === pagination.current_page) {
            return;
        }

        setFilters((current) => ({ ...current, page }));
    };

    return (
        <AppLayoutERP>
            <Head title="Staff & Workload - Solespace ERP" />

            <main className="space-y-6 py-6 md:py-8" aria-labelledby="staff-workload-title">
                <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">People & approvals</p>
                        <h1 id="staff-workload-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">
                            Staff &amp; Workload
                        </h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                            Monitor current assignments, availability, and operational exceptions. Off-shift alone does not trigger reassignment.
                        </p>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="font-semibold text-gray-900 dark:text-white">{pagination?.total ?? 0} staff in view</p>
                        <p className="mt-1 text-gray-500 dark:text-gray-400">
                            Snapshot: {formatDateTime(payload?.last_updated_at)}
                        </p>
                    </div>
                </header>

                <section className={`grid grid-cols-1 gap-4 ${canRetail && canRepair ? "sm:grid-cols-3" : "sm:grid-cols-2"}`} aria-label="Visible workload summary">
                    {canRetail && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                            <NumberMetric label="Visible active orders" value={visibleSummary.activeOrders} />
                        </div>
                    )}
                    {canRepair && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                            <NumberMetric label="Visible active repairs" value={visibleSummary.activeRepairs} />
                        </div>
                    )}
                    <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                        <NumberMetric label="Staff with exceptions" value={visibleSummary.exceptions} alert={visibleSummary.exceptions > 0} />
                    </div>
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="workload-filters-title">
                    <div className="mb-4">
                        <h2 id="workload-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter workload</h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Period fields affect the work totals shown for the selected reporting window.</p>
                    </div>
                    <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
                        <div className="xl:col-span-2">
                            <label htmlFor="staff-search" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Search staff</label>
                            <input
                                id="staff-search"
                                type="search"
                                value={form.search}
                                onChange={(event) => setForm((current) => ({ ...current, search: event.target.value }))}
                                placeholder="Name, email, or position"
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            />
                        </div>
                        <div>
                            <label htmlFor="staff-role" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Role</label>
                            <select
                                id="staff-role"
                                value={form.role}
                                onChange={(event) => setForm((current) => ({ ...current, role: event.target.value }))}
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            >
                                <option value="">All roles</option>
                                {canRetail && <option value="staff">Staff</option>}
                                {canRepair && <option value="repairer">Repairer</option>}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="staff-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                            <select
                                id="staff-status"
                                value={form.status}
                                onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            >
                                <option value="">All statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                                <option value="terminated">Terminated</option>
                                <option value="offboarded">Offboarded</option>
                            </select>
                        </div>
                        <div>
                            <label htmlFor="staff-date-from" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Period from</label>
                            <input
                                id="staff-date-from"
                                type="date"
                                value={form.date_from}
                                onChange={(event) => setForm((current) => ({ ...current, date_from: event.target.value }))}
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            />
                        </div>
                        <div>
                            <label htmlFor="staff-date-to" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Period to</label>
                            <input
                                id="staff-date-to"
                                type="date"
                                value={form.date_to}
                                onChange={(event) => setForm((current) => ({ ...current, date_to: event.target.value }))}
                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            />
                        </div>
                        <div className="flex items-end gap-2 md:col-span-2 xl:col-span-6">
                            <button
                                type="submit"
                                className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-offset-gray-950"
                            >
                                Apply filters
                            </button>
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-950"
                            >
                                Clear
                            </button>
                        </div>
                    </form>
                </section>

                {workload.isError && payload && (
                    <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                        The latest refresh failed. Showing the last successful snapshot. <button type="button" onClick={() => workload.refetch()} className="ml-1 font-semibold underline underline-offset-2">Retry</button>
                    </div>
                )}

                {workload.isFetching && payload && (
                    <p className="text-sm text-gray-500 dark:text-gray-400" role="status" aria-live="polite">Refreshing workload snapshot…</p>
                )}

                {workload.isLoading && <LoadingState />}

                {workload.isError && !payload && (
                    <div role="alert" className="rounded-2xl border border-red-200 bg-red-50 px-6 py-8 dark:border-red-900/60 dark:bg-red-950/30">
                        <h2 className="text-base font-semibold text-red-800 dark:text-red-200">Unable to load staff workload</h2>
                        <p className="mt-2 text-sm text-red-700 dark:text-red-300">{workload.error?.message || "Please try again."}</p>
                        <button
                            type="button"
                            onClick={() => workload.refetch()}
                            className="mt-4 min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950"
                        >
                            Retry
                        </button>
                    </div>
                )}

                {payload && rows.length === 0 && <EmptyState />}

                {payload && rows.length > 0 && (
                    <section aria-labelledby="workload-results-title" className="space-y-4">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 id="workload-results-title" className="text-lg font-semibold text-gray-900 dark:text-white">Current staff workload</h2>
                                <p className="text-sm text-gray-500 dark:text-gray-400">
                                    Period: {formatDateTime(payload.period.start)} – {formatDateTime(payload.period.end)}
                                </p>
                            </div>
                            {workload.isStale && !workload.isFetching && <p className="text-sm font-medium text-amber-700 dark:text-amber-300">Snapshot may be stale</p>}
                        </div>

                        <div className="hidden overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:block dark:border-gray-800 dark:bg-white/[0.03]">
                            <table className="w-full table-fixed text-left">
                                <caption className="sr-only">Staff assignments and workload metrics</caption>
                                <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-900/60 dark:text-gray-400">
                                    <tr>
                                        <th scope="col" className="w-[24%] px-5 py-4 font-semibold">Staff</th>
                                        <th scope="col" className="w-[12%] px-3 py-4 font-semibold">Status</th>
                                        <th scope="col" className="w-[22%] px-3 py-4 font-semibold">Current work</th>
                                        <th scope="col" className="w-[12%] px-3 py-4 font-semibold">Overdue</th>
                                        <th scope="col" className="w-[18%] px-3 py-4 font-semibold">Next action</th>
                                        <th scope="col" className="w-[12%] px-5 py-4 font-semibold">Review</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {rows.map((staff) => (
                                        <tr key={staff.id} className="align-top transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                            <td className="px-5 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white dark:bg-white dark:text-gray-900">
                                                        {initials(staff.name)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="truncate font-semibold text-gray-900 dark:text-white">{staff.name}</p>
                                                        <p className="truncate text-xs text-gray-500 dark:text-gray-400">{staff.position || staff.role}</p>
                                                        <p className="truncate text-xs text-gray-500 dark:text-gray-400">{statusLabel(staff.role)} · {statusLabel(staff.availability_state)}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-4"><StatusBadge value={staff.status} /></td>
                                            <td className="px-3 py-4">
                                                <div className="flex flex-wrap gap-3">
                                                    {canRetail && <NumberMetric label="Orders" value={staff.active_orders} />}
                                                    {canRepair && <NumberMetric label="Repairs" value={staff.active_repairs} />}
                                                </div>
                                            </td>
                                            <td className="px-3 py-4"><NumberMetric label="Items" value={staff.overdue_work} alert={staff.overdue_work > 0} /></td>
                                            <td className="px-3 py-4">
                                                <p className="text-sm text-gray-700 dark:text-gray-300">{staff.next_action}</p>
                                                {staff.reassignment_reason && <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">{staff.reassignment_reason}</p>}
                                            </td>
                                            <td className="px-5 py-4"><ActionLinks staff={staff} canRetail={canRetail} canRepair={canRepair} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid gap-4 lg:hidden">
                            {rows.map((staff) => <StaffWorkloadCard key={staff.id} staff={staff} canRetail={canRetail} canRepair={canRepair} />)}
                        </div>

                        {pagination && pagination.last_page > 1 && (
                            <nav className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/[0.03]" aria-label="Staff workload pagination">
                                <p className="text-sm text-gray-600 dark:text-gray-400">
                                    Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total.toLocaleString()}
                                </p>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => goToPage(pagination.current_page - 1)}
                                        disabled={pagination.current_page <= 1 || workload.isFetching}
                                        className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    >
                                        Previous
                                    </button>
                                    <span className="px-2 text-sm font-medium text-gray-700 dark:text-gray-300" aria-current="page">
                                        Page {pagination.current_page} of {pagination.last_page}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => goToPage(pagination.current_page + 1)}
                                        disabled={pagination.current_page >= pagination.last_page || workload.isFetching}
                                        className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    >
                                        Next
                                    </button>
                                </div>
                            </nav>
                        )}
                    </section>
                )}
            </main>
        </AppLayoutERP>
    );
}
