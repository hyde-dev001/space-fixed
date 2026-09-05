import { Head, usePage } from "@inertiajs/react";
import { useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import AppLayoutShopOwner from "../../../layout/AppLayout_shopOwner";
import { useShopOwnerOrders } from "../../../hooks/useShopOwnerOperationsApi";
import type { ManagerOrder, ManagerOrderFilters } from "../../../hooks/useManagerApi";

type FilterForm = {
  status: string;
  assignment_state: string;
  handler_id: string;
  date_from: string;
  date_to: string;
  overdue: boolean;
};

type PageProps = {
  erpMode?: boolean;
};

const initialFilterForm: FilterForm = {
  status: "",
  assignment_state: "",
  handler_id: "",
  date_from: "",
  date_to: "",
  overdue: false,
};

const statusLabel = (value: string): string => value
  ? value.replace(/[_-]+/g, " ").replace(/\b\w/g, (character) => character.toUpperCase())
  : "Unknown";

const statusClasses = (value: string): string => {
  const normalized = value.toLowerCase();

  if (["completed", "delivered"].includes(normalized)) {
    return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
  }

  if (["cancelled", "refund"].includes(normalized)) {
    return "border-gray-200 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }

  if (["reassignment_required", "failed"].includes(normalized)) {
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
  <div className="space-y-3" aria-label="Loading job orders" aria-busy="true">
    {[1, 2, 3].map((item) => (
      <div key={item} className="h-20 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
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

const OrderDetailDialog = ({ order, onClose }: { order: ManagerOrder; onClose: () => void }) => (
  <div
    className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="owner-order-detail-title"
  >
    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-600 dark:text-blue-400">Order details</p>
          <h2 id="owner-order-detail-title" className="mt-2 text-xl font-bold text-gray-900 dark:text-white">{order.order_number}</h2>
        </div>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close order details"
          className="min-h-11 min-w-11 rounded-lg text-2xl text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:hover:bg-gray-800"
        >
          ×
        </button>
      </div>

      <dl className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Customer</dt><dd className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{order.customer_name}</dd></div>
        <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Status</dt><dd className="mt-1"><StatusBadge value={order.status} /></dd></div>
        <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Current handler</dt><dd className="mt-1 text-sm text-gray-700 dark:text-gray-300">{order.assigned_staff?.name || "Unassigned"}</dd></div>
        <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Processing lock</dt><dd className="mt-1 text-sm text-gray-700 dark:text-gray-300">{order.lock_state === "locked" ? "Locked to current handler" : "Pending claim"}</dd></div>
        <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Age</dt><dd className={`mt-1 text-sm ${order.overdue ? "font-semibold text-red-700 dark:text-red-300" : "text-gray-700 dark:text-gray-300"}`}>{formatAge(order.age_minutes)}{order.overdue ? " · Overdue" : ""}</dd></div>
        <div><dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Created</dt><dd className="mt-1 text-sm text-gray-700 dark:text-gray-300">{formatDateTime(order.created_at)}</dd></div>
      </dl>

      <div className="mt-6 rounded-xl bg-gray-50 p-4 dark:bg-gray-800/70">
        <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Next action</p>
        <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">{order.next_action}</p>
        {order.reassignment_reason_label && <p className="mt-2 text-sm font-medium text-red-700 dark:text-red-300">{order.reassignment_reason_label}</p>}
      </div>

      <div className="mt-6 flex justify-end">
        <button
          type="button"
          onClick={onClose}
          className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
        >
          Close
        </button>
      </div>
    </div>
  </div>
);

export default function JobOrders() {
  const { props } = usePage();
  const { erpMode } = props as PageProps;
  const Layout = erpMode === true ? AppLayoutERP : AppLayoutShopOwner;
  const [form, setForm] = useState<FilterForm>(initialFilterForm);
  const [filters, setFilters] = useState<ManagerOrderFilters>({ page: 1, per_page: 25 });
  const [detailOrder, setDetailOrder] = useState<ManagerOrder | null>(null);
  const orders = useShopOwnerOrders(filters);
  const payload = orders.data?.data;
  const rows = payload?.data ?? [];

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFilters({ ...form, page: 1, per_page: 25 });
  };

  const clearFilters = () => {
    setForm(initialFilterForm);
    setFilters({ page: 1, per_page: 25 });
  };

  const goToPage = (page: number) => {
    if (!payload || page < 1 || page > payload.last_page || page === payload.current_page) {
      return;
    }

    setFilters((current) => ({ ...current, page }));
  };

  return (
    <Layout>
      <Head title="Job Orders - SoleSpace ERP" />

      <main className="space-y-6 py-6 md:py-8" aria-labelledby="owner-job-orders-title">
        <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Operations</p>
            <h1 id="owner-job-orders-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Job Orders</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">Monitor the shop-wide order workload. A claimed order remains locked to its handler until an inactive or unavailable handler is formally replaced.</p>
          </div>
          <div className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <p className="font-semibold text-gray-900 dark:text-white">{payload?.total ?? 0} orders in view</p>
            <p className="mt-1 text-gray-500 dark:text-gray-400">Last updated: {formatDateTime(orders.data?.last_updated_at)}</p>
          </div>
        </header>

        <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="owner-order-filters-title">
          <div className="mb-4">
            <h2 id="owner-order-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter job orders</h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Use the filters to inspect current assignment, age, and processing state.</p>
          </div>
          <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div className="xl:col-span-2">
              <label htmlFor="owner-order-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
              <select id="owner-order-status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div className="xl:col-span-2">
              <label htmlFor="owner-order-assignment" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Assignment</label>
              <select id="owner-order-assignment" value={form.assignment_state} onChange={(event) => setForm((current) => ({ ...current, assignment_state: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">All assignments</option>
                <option value="unassigned">Pending / unassigned</option>
                <option value="assigned">Assigned and locked</option>
                <option value="reassignment_required">Exception required</option>
              </select>
            </div>
            <div>
              <label htmlFor="owner-order-handler" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Handler ID</label>
              <input id="owner-order-handler" value={form.handler_id} onChange={(event) => setForm((current) => ({ ...current, handler_id: event.target.value }))} placeholder="Optional" className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
            </div>
            <div>
              <label htmlFor="owner-order-from" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">From</label>
              <input id="owner-order-from" type="date" value={form.date_from} onChange={(event) => setForm((current) => ({ ...current, date_from: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
            </div>
            <div>
              <label htmlFor="owner-order-to" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">To</label>
              <input id="owner-order-to" type="date" value={form.date_to} onChange={(event) => setForm((current) => ({ ...current, date_to: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
            </div>
            <label className="flex min-h-11 items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" checked={form.overdue} onChange={(event) => setForm((current) => ({ ...current, overdue: event.target.checked }))} className="h-4 w-4 rounded border-gray-300 text-blue-700 focus:ring-blue-500" />
              Overdue only
            </label>
            <div className="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-5">
              <button type="submit" className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200">Apply filters</button>
              <button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Clear</button>
            </div>
          </form>
        </section>

        {orders.isStale && orders.data && <p className="text-xs text-amber-700 dark:text-amber-300">This monitoring snapshot may be stale. Refreshing automatically.</p>}
        {orders.isLoading && <LoadingState />}
        {orders.isError && !orders.isLoading && <ErrorState message={orders.error?.message || "Please try again."} onRetry={() => void orders.refetch()} />}
        {!orders.isLoading && !orders.isError && rows.length === 0 && <EmptyState />}
        {!orders.isLoading && !orders.isError && rows.length > 0 && payload && (
          <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="owner-order-table-title">
            <div className="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
              <div><h2 id="owner-order-table-title" className="text-base font-semibold text-gray-900 dark:text-white">Order workload</h2><p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Read-only monitoring for the authorized shop.</p></div>
              <span className="text-sm text-gray-500 dark:text-gray-400">Page {payload.current_page} of {payload.last_page}</span>
            </div>
            <div className="hidden overflow-x-auto lg:block">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead className="bg-gray-50 dark:bg-gray-900/60"><tr className="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"><th className="px-5 py-3 font-semibold">Order</th><th className="px-3 py-3 font-semibold">Customer</th><th className="px-3 py-3 font-semibold">Status</th><th className="px-3 py-3 font-semibold">Handler</th><th className="px-3 py-3 font-semibold">Age / next action</th><th className="px-5 py-3 text-right font-semibold">Details</th></tr></thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">{rows.map((order) => <tr key={order.id} className="align-top"><td className="px-5 py-4"><p className="font-semibold text-gray-900 dark:text-white">{order.order_number}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{order.lock_state === "locked" ? "Processing locked" : "Pending claim"}</p></td><td className="px-3 py-4 text-sm text-gray-700 dark:text-gray-300">{order.customer_name}</td><td className="px-3 py-4"><StatusBadge value={order.status} /></td><td className="px-3 py-4"><p className="text-sm text-gray-700 dark:text-gray-300">{order.assigned_staff?.name || "Unassigned"}</p><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{order.assigned_staff?.status || "No handler"}</p></td><td className="px-3 py-4"><p className={`text-sm font-semibold ${order.overdue ? "text-red-700 dark:text-red-300" : "text-gray-900 dark:text-white"}`}>{formatAge(order.age_minutes)}{order.overdue ? " · Overdue" : ""}</p><p className="mt-1 text-xs text-gray-600 dark:text-gray-400">{order.next_action}</p></td><td className="px-5 py-4 text-right"><button type="button" onClick={() => setDetailOrder(order)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">View details</button></td></tr>)}</tbody>
              </table>
            </div>
            <div className="space-y-3 p-4 lg:hidden">{rows.map((order) => <article key={order.id} className="rounded-2xl border border-gray-200 p-4 dark:border-gray-800"><div className="flex items-start justify-between gap-3"><div><h3 className="font-semibold text-gray-900 dark:text-white">{order.order_number}</h3><p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{order.customer_name}</p></div><StatusBadge value={order.status} /></div><dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt className="text-xs uppercase tracking-wide text-gray-500">Handler</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{order.assigned_staff?.name || "Unassigned"}</dd></div><div><dt className="text-xs uppercase tracking-wide text-gray-500">Age</dt><dd className={`mt-1 ${order.overdue ? "font-semibold text-red-700" : "text-gray-700 dark:text-gray-300"}`}>{formatAge(order.age_minutes)}</dd></div><div className="col-span-2"><dt className="text-xs uppercase tracking-wide text-gray-500">Next action</dt><dd className="mt-1 text-gray-700 dark:text-gray-300">{order.next_action}</dd></div></dl><button type="button" onClick={() => setDetailOrder(order)} className="mt-4 min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">View details</button></article>)}</div>
            <div className="flex items-center justify-between gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-800"><p className="text-sm text-gray-500 dark:text-gray-400">Showing {payload.from ?? 0}–{payload.to ?? 0} of {payload.total}</p><div className="flex gap-2"><button type="button" disabled={payload.current_page <= 1} onClick={() => goToPage(payload.current_page - 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Previous</button><button type="button" disabled={payload.current_page >= payload.last_page} onClick={() => goToPage(payload.current_page + 1)} className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Next</button></div></div>
          </section>
        )}
      </main>

      {detailOrder && <OrderDetailDialog order={detailOrder} onClose={() => setDetailOrder(null)} />}
    </Layout>
  );
}
