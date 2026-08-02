import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import type { PurchaseOrder } from "@/types/procurement";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import PurchaseOrderReceiptPanel from "../Procurement/components/PurchaseOrderReceiptPanel";

const label = (status: string) => status.split("_").map((part) => part[0].toUpperCase() + part.slice(1)).join(" ");
const formatDate = (value?: string) => value ? new Date(value).toLocaleDateString() : "—";

export default function SupplierOrderMonitoring() {
	const { initialData } = usePage().props as any;
	const [search, setSearch] = useState(() => typeof window === "undefined" ? "" : new URLSearchParams(window.location.search).get("supplier") ?? "");
	const [orders, setOrders] = useState<PurchaseOrder[]>(initialData?.data ?? []);
	const [viewingOrder, setViewingOrder] = useState<PurchaseOrder | null>(null);
	const filtered = useMemo(() => {
		const query = search.trim().toLowerCase();
		return query ? orders.filter((order) => [order.po_number, order.supplier?.name, order.product_name, order.status]
			.some((value) => String(value ?? "").toLowerCase().includes(query))) : orders;
	}, [orders, search]);
	const openOrder = async (id: number) => setViewingOrder(await purchaseOrderApi.getById(id));
	const refreshViewingOrder = async () => {
		if (!viewingOrder) return;
		const refreshed = await purchaseOrderApi.getById(viewingOrder.id);
		setViewingOrder(refreshed);
		setOrders((current) => current.map((order) => order.id === refreshed.id ? refreshed : order));
	};

	return (
		<AppLayoutERP hideHeader={Boolean(viewingOrder)}>
			<Head title="Supplier Order Monitoring - Solespace" />
			<div className="p-6 space-y-6">
				<div><h1 className="text-2xl font-semibold">Supplier Order Monitoring</h1><p className="text-sm text-gray-500">Track deliveries and receive goods into Inventory.</p></div>

				<div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
					<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search PO, supplier, product, or status" className="mb-4 w-full rounded-lg border border-gray-300 bg-white px-4 py-2 dark:border-gray-600 dark:bg-gray-800" />
					<div className="overflow-x-auto">
						<table className="min-w-full text-sm">
							<thead><tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700"><th className="px-3 py-3">PO</th><th className="px-3 py-3">Supplier / Product</th><th className="px-3 py-3">Expected</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Action</th></tr></thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{filtered.map((order) => <tr key={order.id}><td className="px-3 py-3 font-medium">{order.po_number}</td><td className="px-3 py-3"><p>{order.supplier?.name ?? "Unknown supplier"}</p><p className="text-xs text-gray-500">{order.product_name}</p></td><td className="px-3 py-3">{formatDate(order.expected_delivery_date)}</td><td className="px-3 py-3"><span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800">{label(order.status)}</span></td><td className="px-3 py-3"><button type="button" onClick={() => void openOrder(order.id)} className="text-sm font-medium text-blue-600 hover:underline">{["in_transit", "partially_received"].includes(order.status) ? "View / Receive" : "View"}</button></td></tr>)}
								{filtered.length === 0 && <tr><td colSpan={5} className="px-3 py-10 text-center text-gray-500">No supplier orders found.</td></tr>}
							</tbody>
						</table>
					</div>
				</div>
			</div>

			{viewingOrder && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
				<div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900">
					<div className="mb-4 flex items-center justify-between"><div><h2 className="text-xl font-semibold">{viewingOrder.po_number}</h2><p className="text-sm text-gray-500">{viewingOrder.supplier?.name} · {label(viewingOrder.status)}</p></div><button type="button" onClick={() => setViewingOrder(null)} aria-label="Close" className="text-2xl text-gray-500">×</button></div>
					<PurchaseOrderReceiptPanel order={viewingOrder} canReceive canVoid={false} onChanged={refreshViewingOrder} />
				</div>
			</div>}
		</AppLayoutERP>
	);
}
