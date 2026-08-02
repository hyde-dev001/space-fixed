import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import type { PurchaseOrder } from "@/types/procurement";

const label = (status: string) => status.split("_").map((part) => part[0].toUpperCase() + part.slice(1)).join(" ");
const formatDate = (value?: string) => value ? new Date(value).toLocaleDateString() : "—";

export default function SupplierOrderMonitoring() {
	const { initialData } = usePage().props as any;
	const [search, setSearch] = useState(() => typeof window === "undefined" ? "" : new URLSearchParams(window.location.search).get("supplier") ?? "");
	const orders = (initialData?.data ?? []) as PurchaseOrder[];
	const filtered = useMemo(() => {
		const query = search.trim().toLowerCase();
		return query ? orders.filter((order) => [order.po_number, order.supplier?.name, order.product_name, order.status]
			.some((value) => String(value ?? "").toLowerCase().includes(query))) : orders;
	}, [orders, search]);

	return (
		<AppLayoutERP>
			<Head title="Supplier Order Monitoring - Solespace" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
					<div><h1 className="text-2xl font-semibold">Supplier Order Monitoring</h1><p className="text-sm text-gray-500">Read-only delivery timeline for supplier follow-up.</p></div>
					<a href="/erp/procurement/purchase-orders" className="rounded-lg bg-blue-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-blue-700">Open Purchase Orders to receive goods</a>
				</div>

				<div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
					Receiving and status updates now happen only in Purchase Orders so stock and Finance stay synchronized.
				</div>

				<div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
					<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search PO, supplier, product, or status" className="mb-4 w-full rounded-lg border border-gray-300 bg-white px-4 py-2 dark:border-gray-600 dark:bg-gray-800" />
					<div className="overflow-x-auto">
						<table className="min-w-full text-sm">
							<thead><tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700"><th className="px-3 py-3">PO</th><th className="px-3 py-3">Supplier / Product</th><th className="px-3 py-3">Expected</th><th className="px-3 py-3">Status</th></tr></thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{filtered.map((order) => <tr key={order.id}><td className="px-3 py-3 font-medium">{order.po_number}</td><td className="px-3 py-3"><p>{order.supplier?.name ?? "Unknown supplier"}</p><p className="text-xs text-gray-500">{order.product_name}</p></td><td className="px-3 py-3">{formatDate(order.expected_delivery_date)}</td><td className="px-3 py-3"><span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800">{label(order.status)}</span></td></tr>)}
								{filtered.length === 0 && <tr><td colSpan={4} className="px-3 py-10 text-center text-gray-500">No supplier orders found.</td></tr>}
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</AppLayoutERP>
	);
}
