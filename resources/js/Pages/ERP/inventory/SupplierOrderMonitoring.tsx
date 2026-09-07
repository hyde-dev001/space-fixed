import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import type { PurchaseOrder } from "@/types/procurement";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import PurchaseOrderReceiptPanel from "../Procurement/components/PurchaseOrderReceiptPanel";
import { DashboardMetricCard } from "../../../components/dashboard";
import { Eye, PackageCheck, ShoppingCart, Truck, CheckCircle2 } from "lucide-react";

const label = (status: string) => status.split("_").map((part) => part[0].toUpperCase() + part.slice(1)).join(" ");
const formatDate = (value?: string) => value ? new Date(value).toLocaleDateString() : "—";

export default function SupplierOrderMonitoring() {
	const { auth, initialData } = usePage().props as any;
	const ownerMode = auth?.erpActor?.ownerMode === true;
	const [search, setSearch] = useState(() => typeof window === "undefined" ? "" : new URLSearchParams(window.location.search).get("supplier") ?? "");
	const [statusFilter, setStatusFilter] = useState("All");
	const [supplierFilter, setSupplierFilter] = useState("All");
	const [orders, setOrders] = useState<PurchaseOrder[]>(initialData?.data ?? []);
	const [viewingOrder, setViewingOrder] = useState<PurchaseOrder | null>(null);
	const filtered = useMemo(() => {
		const query = search.trim().toLowerCase();
		return orders.filter((order) => {
			const matchesSearch = !query || [order.po_number, order.supplier?.name, order.product_name, order.status]
				.some((value) => String(value ?? "").toLowerCase().includes(query));
			return matchesSearch && (statusFilter === "All" || order.status === statusFilter) && (supplierFilter === "All" || String(order.supplier?.id ?? "") === supplierFilter);
		});
	}, [orders, search, statusFilter, supplierFilter]);
	const statusCounts = useMemo(() => orders.reduce<Record<string, number>>((counts, order) => ({ ...counts, [order.status]: (counts[order.status] ?? 0) + 1 }), {}), [orders]);
	const suppliers = useMemo(() => Array.from(new Map(orders.filter((order) => order.supplier?.id).map((order) => [order.supplier!.id, order.supplier!])).values()), [orders]);
	const openOrder = async (id: number) => {
		if (ownerMode) {
			setViewingOrder(orders.find((order) => order.id === id) ?? null);
			return;
		}

		setViewingOrder(await purchaseOrderApi.getById(id));
	};
	const refreshViewingOrder = async () => {
		if (ownerMode || !viewingOrder) return;
		const refreshed = await purchaseOrderApi.getById(viewingOrder.id);
		setViewingOrder(refreshed);
		setOrders((current) => current.map((order) => order.id === refreshed.id ? refreshed : order));
	};

	return (
		<AppLayoutERP hideHeader={Boolean(viewingOrder)}>
			<Head title="Supplier Order Monitoring - Solespace" />
			<div className="p-6 space-y-6">
				<h1 className="sr-only">Supplier Order Monitoring</h1>

				<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
					<DashboardMetricCard label="Total Supplier Orders" value={orders.length.toLocaleString()} description="Orders in the loaded view" context="Procurement" icon={ShoppingCart} />
					<DashboardMetricCard label="Pending / Active" value={((statusCounts.sent ?? 0) + (statusCounts.confirmed ?? 0)).toLocaleString()} description="Sent or confirmed orders" context="Procurement" icon={ShoppingCart} />
					<DashboardMetricCard label="In Transit" value={(statusCounts.in_transit ?? 0).toLocaleString()} description="Orders being delivered" context="Procurement" icon={Truck} />
					<DashboardMetricCard label="Delivered" value={(statusCounts.delivered ?? 0).toLocaleString()} description="Completed deliveries" context="Procurement" icon={CheckCircle2} />
				</div>

				<div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
					<div className="mb-4 flex flex-col gap-3 sm:flex-row">
						<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search PO, supplier, product, or status" className="min-h-11 flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2 dark:border-gray-600 dark:bg-gray-800" />
						<select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} aria-label="Filter supplier orders by status" className="min-h-11 rounded-lg border border-gray-300 bg-white px-4 py-2 dark:border-gray-600 dark:bg-gray-800">
							<option value="All">All statuses</option>
							{Array.from(new Set(orders.map((order) => order.status))).map((status) => <option key={status} value={status}>{label(status)}</option>)}
						</select>
						<select value={supplierFilter} onChange={(event) => setSupplierFilter(event.target.value)} aria-label="Filter supplier orders by supplier" className="min-h-11 rounded-lg border border-gray-300 bg-white px-4 py-2 dark:border-gray-600 dark:bg-gray-800">
							<option value="All">All suppliers</option>
							{suppliers.map((supplier) => <option key={supplier.id} value={String(supplier.id)}>{supplier.name}</option>)}
						</select>
					</div>
					<div className="overflow-x-auto">
						<table className="min-w-full text-sm">
							<thead><tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700"><th className="px-3 py-3">PO</th><th className="px-3 py-3">Supplier / Product</th><th className="px-3 py-3">Expected</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Action</th></tr></thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{filtered.map((order) => { const canReceive = !ownerMode && ["in_transit", "partially_received"].includes(order.status); return <tr key={order.id}><td className="px-3 py-3 font-medium">{order.po_number}</td><td className="px-3 py-3"><p>{order.supplier?.name ?? "Unknown supplier"}</p><p className="text-xs text-gray-500">{order.product_name}</p></td><td className="px-3 py-3">{formatDate(order.expected_delivery_date)}</td><td className="px-3 py-3"><span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800">{label(order.status)}</span></td><td className="px-3 py-3"><div className="flex items-center gap-2"><button type="button" onClick={() => void openOrder(order.id)} title="View" aria-label="View" className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"><Eye className="h-4 w-4" aria-hidden="true" /></button>{canReceive && <button type="button" onClick={() => void openOrder(order.id)} title="Receive" aria-label="Receive" className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"><PackageCheck className="h-4 w-4" aria-hidden="true" /></button>}</div></td></tr>; })}
								{filtered.length === 0 && <tr><td colSpan={5} className="px-3 py-10 text-center text-gray-500">No supplier orders found.</td></tr>}
							</tbody>
						</table>
					</div>
				</div>
			</div>

			{viewingOrder && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
				<div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
					<div className="mb-4 flex items-center justify-between"><div><h2 className="text-xl font-semibold">{viewingOrder.po_number}</h2><p className="text-sm text-gray-500">{viewingOrder.supplier?.name} · {label(viewingOrder.status)}</p></div><button type="button" onClick={() => setViewingOrder(null)} aria-label="Close" className="text-2xl text-gray-500">×</button></div>
					<PurchaseOrderReceiptPanel order={viewingOrder} canReceive={!ownerMode} canVoid={false} onChanged={refreshViewingOrder} />
				</div>
			</div>}
		</AppLayoutERP>
	);
}
