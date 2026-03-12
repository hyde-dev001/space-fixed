import { Head, router, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import type { SupplierOrder as ApiSupplierOrder } from "@/types/inventory";

type OrderStatus = "Sent" | "Confirmed" | "In Transit" | "Delivered" | "Completed" | "Cancelled";
type MetricColor = "success" | "warning" | "info";

interface SupplierOrderItem {
	id: number;
	poNo: string;
	supplierName: string;
	productName: string;
	quantity: number;
	orderedDate: string;
	expectedDeliveryDate: string;
	status: OrderStatus;
	remarks: string;
}

const apiStatusToDisplay: Record<string, OrderStatus> = {
	sent: "Sent",
	confirmed: "Confirmed",
	in_transit: "In Transit",
	delivered: "Delivered",
	completed: "Completed",
	cancelled: "Cancelled",
	draft: "Sent",
};

const mapApiOrder = (order: ApiSupplierOrder): SupplierOrderItem => ({
	id: order.id,
	poNo: order.po_number,
	supplierName: order.supplier?.name ?? "Unknown Supplier",
	productName: order.items?.[0]?.product_name ?? "—",
	quantity: order.items?.[0]?.quantity ?? 0,
	orderedDate: order.order_date,
	expectedDeliveryDate: order.expected_delivery_date ?? "",
	status: apiStatusToDisplay[order.status] ?? "Sent",
	remarks: order.remarks ?? "",
});

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const statusBadgeClass: Record<OrderStatus, string> = {
	Sent: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Confirmed: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	"In Transit": "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Delivered: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	Completed: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	Cancelled: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
};

const ClipboardIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
		<rect x="9" y="3" width="6" height="4" rx="1" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6M9 16h4" />
	</svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<circle cx="12" cy="12" r="9" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M12 7v5l3 3" />
	</svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
	</svg>
);

const TruckIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 17h6m-6 0a2 2 0 11-4 0m4 0a2 2 0 104 0m0 0h2a2 2 0 002-2v-3l-3-3h-3V7a2 2 0 00-2-2H3" />
	</svg>
);

const ChevronLeftIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
	</svg>
);

const ChevronRightIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
	</svg>
);

const MetricCard = ({ title, value, description, icon: Icon, color }: MetricCardProps) => {
	const getColorClasses = () => {
		switch (color) {
			case "success":
				return "from-green-500 to-emerald-600";
			case "warning":
				return "from-yellow-500 to-orange-600";
			case "info":
				return "from-blue-500 to-indigo-600";
			default:
				return "from-gray-500 to-gray-600";
		}
	};

	return (
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
			<div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
			<div className="relative">
				<div className="flex items-center justify-between mb-4">
					<div className={`flex items-center justify-center w-14 h-14 bg-linear-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
						<Icon className="text-white size-7 drop-shadow-sm" />
					</div>
				</div>
				<div className="space-y-2">
					<p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
					<h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">{value}</h3>
					<p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
				</div>
			</div>
		</div>
	);
};

const getDaysToDelivery = (expectedDate: string) => {
	const today = new Date();
	today.setHours(0, 0, 0, 0);

	const expected = new Date(expectedDate);
	expected.setHours(0, 0, 0, 0);

	return Math.round((expected.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
};

const getSlaBadge = (order: SupplierOrderItem) => {
	if (order.status === "Delivered" || order.status === "Completed") {
		return { label: "Closed", className: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300" };
	}

	if (order.status === "Cancelled") {
		return { label: "Cancelled", className: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" };
	}

	const days = getDaysToDelivery(order.expectedDeliveryDate);

	if (days < 0) {
		return { label: `${Math.abs(days)} days overdue`, className: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" };
	}

	if (days === 0) {
		return { label: "Due today", className: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600" };
	}

	return { label: `${days} days left`, className: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600" };
};

const getStatusBadge = (order: SupplierOrderItem) => {
	if (order.status === "In Transit" && getDaysToDelivery(order.expectedDeliveryDate) < 0) {
		return "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300";
	}

	return statusBadgeClass[order.status];
};

export default function SupplierOrderMonitoring() {
	const supplierParam = new URLSearchParams(window.location.search).get("supplier") ?? "";
	const { initialData } = usePage().props as any;
	const [orders, setOrders] = useState<SupplierOrderItem[]>(
		() => (initialData?.data ?? []).map(mapApiOrder)
	);
	const [loading, setLoading] = useState(false);
	const [searchQuery, setSearchQuery] = useState(supplierParam);
	const [currentPage, setCurrentPage] = useState(1);
	const [viewingOrder, setViewingOrder] = useState<SupplierOrderItem | null>(null);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		if (!query) return orders;

		return orders.filter((order) =>
			order.poNo.toLowerCase().includes(query) ||
			order.supplierName.toLowerCase().includes(query) ||
			order.productName.toLowerCase().includes(query) ||
			order.status.toLowerCase().includes(query)
		);
	}, [orders, searchQuery]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const activeOrders = orders.filter((order) => !["Delivered", "Completed", "Cancelled"].includes(order.status));
	const totalActiveCount = activeOrders.length;
	const dueTodayCount = activeOrders.filter((order) => getDaysToDelivery(order.expectedDeliveryDate) === 0).length;
	const overdueCount = activeOrders.filter((order) => getDaysToDelivery(order.expectedDeliveryDate) < 0).length;
	const arrivingSoonCount = activeOrders.filter((order) => {
		const days = getDaysToDelivery(order.expectedDeliveryDate);
		return days > 0 && days <= 3;
	}).length;

	const isAnyModalOpen = Boolean(viewingOrder);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Supplier Order Monitoring - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Supplier Order Monitoring</h1>
						<p className="text-gray-600 dark:text-gray-400">Track PO delivery timelines and monitor remaining days before expected arrival</p>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
					<MetricCard title="Active Orders" value={totalActiveCount} description="PO still in progress" icon={ClipboardIcon} color="info" />
					<MetricCard title="Due Today" value={dueTodayCount} description="Require receiving coordination" icon={ClockIcon} color="warning" />
					<MetricCard title="Overdue" value={overdueCount} description="Need supplier follow-up" icon={AlertIcon} color="warning" />
					<MetricCard title="Arriving ≤3 Days" value={arrivingSoonCount} description="Prepare warehouse receiving" icon={TruckIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Supplier Delivery Timeline</h2>
						<p className="text-sm text-gray-500">Monitor expected delivery dates and remaining days per purchase order</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by PO no, supplier, product, or status..."
								value={searchQuery}
								onChange={(event) => {
									setSearchQuery(event.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
					</div>
					{supplierParam && searchQuery === supplierParam && (
						<div className="mb-4 flex items-center gap-2">
							<span className="text-sm text-gray-500 dark:text-gray-400">Filtered by supplier:</span>
							<span className="inline-flex items-center gap-1.5 rounded-full bg-blue-100 dark:bg-blue-900/30 px-3 py-1 text-sm font-medium text-blue-700 dark:text-blue-300">
								{supplierParam}
								<button
									type="button"
									onClick={() => { setSearchQuery(""); window.history.replaceState({}, "", window.location.pathname); }}
									className="ml-0.5 text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-200 text-base leading-none"
									title="Clear supplier filter"
								>
									×
								</button>
							</span>
							<button
								type="button"
								onClick={() => router.visit("/erp/procurement/suppliers-management")}
								className="text-sm text-blue-600 dark:text-blue-400 hover:underline"
							>
								← Back to Suppliers
							</button>
						</div>
					)}

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">PO no</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Supplier / Product</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Expected delivery</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Days monitor</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
						{loading ? (
							<tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">Loading supplier orders...</td></tr>
						) : paginatedItems.length > 0 ? (
									paginatedItems.map((order) => {
										const sla = getSlaBadge(order);

										return (
											<tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
												<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{order.poNo}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
													<p className="font-medium text-gray-900 dark:text-white">{order.supplierName}</p>
													<p className="text-xs text-gray-500 dark:text-gray-400">{order.productName} · Qty {order.quantity}</p>
												</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{order.expectedDeliveryDate}</td>
												<td className="px-4 py-3">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${sla.className}`}>{sla.label}</span>
												</td>
												<td className="px-4 py-3">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadge(order)}`}>{order.status}</span>
												</td>
												<td className="px-4 py-3 text-center">
													<button
														onClick={() => setViewingOrder(order)}
														className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
														title="View supplier order details"
													>
														<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
															<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
															<circle cx="12" cy="12" r="3" />
														</svg>
													</button>
												</td>
											</tr>
										);
									})
								) : (
									<tr>
										<td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">No supplier orders found.</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {filteredData.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} orders
						</p>
						<div className="flex gap-2">
							<button
								type="button"
								onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
								disabled={currentPage === 1}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Previous page"
							>
								<ChevronLeftIcon className="w-5 h-5" />
							</button>
							<button
								type="button"
								onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
								disabled={currentPage === totalPages}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Next page"
							>
								<ChevronRightIcon className="w-5 h-5" />
							</button>
						</div>
					</div>
				</div>
			</div>

			{viewingOrder && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close supplier order details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingOrder(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Supplier Order Details</h2>
							<button onClick={() => setViewingOrder(null)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">PO No</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.poNo}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadge(viewingOrder)}`}>{viewingOrder.status}</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Supplier and Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.supplierName}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">{viewingOrder.productName} · Qty {viewingOrder.quantity}</p>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Ordered Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.orderedDate}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Expected Delivery Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.expectedDeliveryDate}</p>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Days to Delivery</p>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getSlaBadge(viewingOrder).className}`}>{getSlaBadge(viewingOrder).label}</span>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Remarks</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white whitespace-pre-wrap">{viewingOrder.remarks}</p>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingOrder(null)} className="w-full px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors">Close</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
