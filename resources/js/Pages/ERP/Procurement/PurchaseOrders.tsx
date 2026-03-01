import { Head } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type PurchaseOrderStatus = "Draft" | "Sent" | "Confirmed" | "In Transit" | "Delivered" | "Completed" | "Cancelled";
type MetricColor = "success" | "warning" | "info";

interface ApprovedPrOption {
	id: number;
	prNo: string;
	productName: string;
	supplierName: string;
	quantity: number;
	unitCost: number;
}

interface PurchaseOrderItem {
	id: number;
	poNo: string;
	prNo: string;
	productName: string;
	supplierName: string;
	quantity: number;
	unitCost: number;
	totalCost: number;
	expectedDeliveryDate: string;
	paymentTerms: string;
	orderedBy: string;
	orderedDate: string;
	status: PurchaseOrderStatus;
	notes: string;
}

interface PurchaseOrderFormState {
	selectedPrNo: string;
	expectedDeliveryDate: string;
	paymentTerms: string;
	notes: string;
}

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const approvedPrOptions: ApprovedPrOption[] = [
	{
		id: 1,
		prNo: "PR-2026-002",
		productName: "Adidas Ultraboost 22",
		supplierName: "Prime Shoe Goods",
		quantity: 25,
		unitCost: 4800,
	},
	{
		id: 2,
		prNo: "PR-2026-004",
		productName: "Nike Air Force 1 '07",
		supplierName: "Metro Footwear Trading",
		quantity: 30,
		unitCost: 4500,
	},
	{
		id: 3,
		prNo: "PR-2026-007",
		productName: "Cleaning Foam",
		supplierName: "CleanKicks Supply Co.",
		quantity: 60,
		unitCost: 180,
	},
];

const initialPurchaseOrders: PurchaseOrderItem[] = [
	{
		id: 1,
		poNo: "PO-2026-001",
		prNo: "PR-2026-002",
		productName: "Adidas Ultraboost 22",
		supplierName: "Prime Shoe Goods",
		quantity: 25,
		unitCost: 4800,
		totalCost: 120000,
		expectedDeliveryDate: "2026-03-05",
		paymentTerms: "Net 30",
		orderedBy: "Procurement Team",
		orderedDate: "2026-02-28 01:10 PM",
		status: "Sent",
		notes: "Expedite for first-week March campaign.",
	},
	{
		id: 2,
		poNo: "PO-2026-002",
		prNo: "PR-2026-004",
		productName: "Nike Air Force 1 '07",
		supplierName: "Metro Footwear Trading",
		quantity: 30,
		unitCost: 4500,
		totalCost: 135000,
		expectedDeliveryDate: "2026-03-07",
		paymentTerms: "50% down, 50% on delivery",
		orderedBy: "Procurement Team",
		orderedDate: "2026-02-28 02:35 PM",
		status: "Confirmed",
		notes: "Supplier confirmed stock allocation.",
	},
	{
		id: 3,
		poNo: "PO-2026-003",
		prNo: "PR-2026-007",
		productName: "Cleaning Foam",
		supplierName: "CleanKicks Supply Co.",
		quantity: 60,
		unitCost: 180,
		totalCost: 10800,
		expectedDeliveryDate: "2026-03-01",
		paymentTerms: "COD",
		orderedBy: "Procurement Team",
		orderedDate: "2026-02-27 11:45 AM",
		status: "Delivered",
		notes: "Partial received in warehouse already.",
	},
];

const initialFormState: PurchaseOrderFormState = {
	selectedPrNo: "",
	expectedDeliveryDate: "",
	paymentTerms: "Net 30",
	notes: "",
};

const statusBadgeClass: Record<PurchaseOrderStatus, string> = {
	Draft: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Sent: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Confirmed: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	"In Transit": "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Delivered: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
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

const TruckIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 17h6m-6 0a2 2 0 11-4 0m4 0a2 2 0 104 0m0 0h2a2 2 0 002-2v-3l-3-3h-3V7a2 2 0 00-2-2H3" />
	</svg>
);

const CheckCircleIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<circle cx="12" cy="12" r="9" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4" />
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

const currency = new Intl.NumberFormat("en-PH", {
	style: "currency",
	currency: "PHP",
	maximumFractionDigits: 2,
});

const nextStatusMap: Partial<Record<PurchaseOrderStatus, PurchaseOrderStatus>> = {
	Draft: "Sent",
	Sent: "Confirmed",
	Confirmed: "In Transit",
	"In Transit": "Delivered",
	Delivered: "Completed",
};

export default function PurchaseOrders() {
	const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrderItem[]>(initialPurchaseOrders);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [viewingOrder, setViewingOrder] = useState<PurchaseOrderItem | null>(null);
	const [formData, setFormData] = useState<PurchaseOrderFormState>(initialFormState);

	const selectedPrOption = useMemo(
		() => approvedPrOptions.find((item) => item.prNo === formData.selectedPrNo) ?? null,
		[formData.selectedPrNo]
	);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		if (!query) return purchaseOrders;

		return purchaseOrders.filter((order) =>
			order.poNo.toLowerCase().includes(query) ||
			order.prNo.toLowerCase().includes(query) ||
			order.productName.toLowerCase().includes(query) ||
			order.supplierName.toLowerCase().includes(query) ||
			order.status.toLowerCase().includes(query)
		);
	}, [searchQuery, purchaseOrders]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalPoCount = purchaseOrders.length;
	const activePoCount = purchaseOrders.filter((item) => item.status === "Sent" || item.status === "Confirmed" || item.status === "In Transit").length;
	const completedPoCount = purchaseOrders.filter((item) => item.status === "Completed").length;

	const closeCreateModal = () => {
		setIsCreateModalOpen(false);
		setFormData(initialFormState);
	};

	const handleCreatePO = async () => {
		if (!formData.selectedPrNo || !formData.expectedDeliveryDate.trim() || !formData.paymentTerms.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Missing fields",
				text: "Please select approved PR, expected delivery date, and payment terms.",
				confirmButtonColor: "#111827",
			});
			return;
		}

		if (!selectedPrOption) {
			await Swal.fire({
				icon: "warning",
				title: "Invalid PR",
				text: "Selected PR is not available.",
				confirmButtonColor: "#111827",
			});
			return;
		}

		const nextId = Math.max(...purchaseOrders.map((item) => item.id), 0) + 1;
		const poNo = `PO-2026-${String(nextId).padStart(3, "0")}`;

		const newOrder: PurchaseOrderItem = {
			id: nextId,
			poNo,
			prNo: selectedPrOption.prNo,
			productName: selectedPrOption.productName,
			supplierName: selectedPrOption.supplierName,
			quantity: selectedPrOption.quantity,
			unitCost: selectedPrOption.unitCost,
			totalCost: selectedPrOption.quantity * selectedPrOption.unitCost,
			expectedDeliveryDate: formData.expectedDeliveryDate,
			paymentTerms: formData.paymentTerms,
			orderedBy: "Procurement Team",
			orderedDate: new Date().toLocaleString(),
			status: "Draft",
			notes: formData.notes.trim() || "No additional notes.",
		};

		setPurchaseOrders((prev) => [newOrder, ...prev]);
		setCurrentPage(1);
		closeCreateModal();

		await Swal.fire({
			icon: "success",
			title: "Purchase Order Created",
			text: `${poNo} is ready to be sent to supplier.`,
			confirmButtonColor: "#111827",
			timer: 1500,
			showConfirmButton: false,
		});
	};

	const updateOrderStatus = (orderId: number, nextStatus: PurchaseOrderStatus) => {
		setPurchaseOrders((prev) => prev.map((item) => (item.id === orderId ? { ...item, status: nextStatus } : item)));
		setViewingOrder((prev) => (prev && prev.id === orderId ? { ...prev, status: nextStatus } : prev));
	};

	const handleProgressOrder = async (order: PurchaseOrderItem) => {
		const nextStatus = nextStatusMap[order.status];
		if (!nextStatus) return;

		const result = await Swal.fire({
			title: `Move to ${nextStatus}?`,
			text: `${order.poNo} will be updated from ${order.status} to ${nextStatus}.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: `Yes, mark as ${nextStatus}`,
			cancelButtonText: "Cancel",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		updateOrderStatus(order.id, nextStatus);

		await Swal.fire({
			title: "Updated",
			text: `${order.poNo} is now ${nextStatus}.`,
			icon: "success",
			timer: 1400,
			showConfirmButton: false,
		});
	};

	const handleSetOrderStatus = async (order: PurchaseOrderItem, nextStatus: PurchaseOrderStatus) => {
		if (order.status === nextStatus) return;

		const result = await Swal.fire({
			title: `Move to ${nextStatus}?`,
			text: `${order.poNo} will be updated from ${order.status} to ${nextStatus}.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: `Yes, mark as ${nextStatus}`,
			cancelButtonText: "Cancel",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		updateOrderStatus(order.id, nextStatus);

		await Swal.fire({
			title: "Updated",
			text: `${order.poNo} is now ${nextStatus}.`,
			icon: "success",
			timer: 1400,
			showConfirmButton: false,
		});
	};

	const handleCancelOrder = async (order: PurchaseOrderItem) => {
		if (order.status === "Completed" || order.status === "Cancelled") return;

		const result = await Swal.fire({
			title: "Cancel this PO?",
			text: `${order.poNo} will be marked as Cancelled.`,
			icon: "warning",
			showCancelButton: true,
			confirmButtonText: "Yes, cancel PO",
			cancelButtonText: "Back",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		updateOrderStatus(order.id, "Cancelled");

		await Swal.fire({
			title: "Cancelled",
			text: `${order.poNo} has been cancelled.`,
			icon: "success",
			timer: 1400,
			showConfirmButton: false,
		});
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingOrder);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Purchase Orders - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Purchase Orders</h1>
						<p className="text-gray-600 dark:text-gray-400">Create PO from approved PR, send to supplier, then track order progress end-to-end</p>
					</div>
					<button
						onClick={() => setIsCreateModalOpen(true)}
						className="px-4 py-2 bg-blue-600 hover:bg-blue-900 text-white rounded-lg font-medium transition-colors whitespace-nowrap"
					>
						+ New PO
					</button>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total PO" value={totalPoCount} description="Purchase orders created" icon={ClipboardIcon} color="info" />
					<MetricCard title="In Progress" value={activePoCount} description="Sent, confirmed, or in-transit orders" icon={TruckIcon} color="warning" />
					<MetricCard title="Completed" value={completedPoCount} description="Fully received and closed" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Purchase Order Table</h2>
						<p className="text-sm text-gray-500">Monitor supplier order lifecycle: Sent, Confirmed, In Transit, Delivered, and Completed</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by PO no, PR no, product, supplier, or status..."
								value={searchQuery}
								onChange={(event) => {
									setSearchQuery(event.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">PO no</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">PR / Supplier</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Item</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Total</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Expected delivery</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{paginatedItems.length > 0 ? (
									paginatedItems.map((order) => (
										<tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{order.poNo}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{order.prNo}</p>
												<p className="text-xs text-gray-500 dark:text-gray-400">{order.supplierName}</p>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{order.productName}</p>
												<p className="text-xs text-gray-500 dark:text-gray-400">Qty {order.quantity}</p>
											</td>
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white whitespace-nowrap">{currency.format(order.totalCost)}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{order.expectedDeliveryDate}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[order.status]}`}>
													{order.status}
												</span>
											</td>
											<td className="px-4 py-3 text-center">
												<button
													onClick={() => setViewingOrder(order)}
													className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
													title="View PO details"
												>
													<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
														<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
														<circle cx="12" cy="12" r="3" />
													</svg>
												</button>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">No purchase orders found.</td>
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

			{isCreateModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close create purchase order modal" className="absolute inset-0 bg-black/50" onClick={closeCreateModal} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Create Purchase Order</h2>
							<button onClick={closeCreateModal} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Approved PR *</label>
								<select
									title="Select approved PR"
									aria-label="Select approved PR"
									value={formData.selectedPrNo}
									onChange={(event) => setFormData((prev) => ({ ...prev, selectedPrNo: event.target.value }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">Select approved PR</option>
									{approvedPrOptions.map((item) => (
										<option key={item.id} value={item.prNo}>{item.prNo} - {item.productName}</option>
									))}
								</select>
							</div>

							{selectedPrOption && (
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Selected PR Details</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{selectedPrOption.productName}</p>
									<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Supplier: {selectedPrOption.supplierName}</p>
									<p className="text-sm text-gray-500 dark:text-gray-400">Qty: {selectedPrOption.quantity} · Unit Cost: {currency.format(selectedPrOption.unitCost)}</p>
									<p className="text-sm font-semibold text-gray-900 dark:text-white mt-2">Total: {currency.format(selectedPrOption.quantity * selectedPrOption.unitCost)}</p>
								</div>
							)}

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Expected Delivery Date *</label>
									<input
										type="date"
										title="Expected delivery date"
										aria-label="Expected delivery date"
										value={formData.expectedDeliveryDate}
										onChange={(event) => setFormData((prev) => ({ ...prev, expectedDeliveryDate: event.target.value }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payment Terms *</label>
									<input
										type="text"
										value={formData.paymentTerms}
										onChange={(event) => setFormData((prev) => ({ ...prev, paymentTerms: event.target.value }))}
										placeholder="e.g., Net 30"
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Procurement Notes</label>
								<textarea
									rows={3}
									value={formData.notes}
									onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
									placeholder="Optional notes for supplier coordination"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button onClick={closeCreateModal} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Cancel</button>
							<button onClick={handleCreatePO} className="flex-1 px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors">Create PO</button>
						</div>
					</div>
				</div>
			)}

			{viewingOrder && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close purchase order details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingOrder(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Purchase Order Details</h2>
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
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewingOrder.status]}`}>{viewingOrder.status}</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Linked PR and Supplier</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.prNo}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">{viewingOrder.supplierName}</p>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.productName}</p>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.quantity}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Unit Cost</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{currency.format(viewingOrder.unitCost)}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Total Cost</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{currency.format(viewingOrder.totalCost)}</p>
								</div>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Expected Delivery</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.expectedDeliveryDate}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Payment Terms</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.paymentTerms}</p>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white whitespace-pre-wrap">{viewingOrder.notes}</p>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingOrder(null)} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Close</button>
							{viewingOrder.status === "Confirmed" && (
								<button onClick={() => void handleSetOrderStatus(viewingOrder, "In Transit")} className="flex-1 px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors">
									Mark as In Transit
								</button>
							)}
							{viewingOrder.status === "In Transit" && (
								<button onClick={() => void handleSetOrderStatus(viewingOrder, "Delivered")} className="flex-1 px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors">
									Mark as Delivered
								</button>
							)}
							{nextStatusMap[viewingOrder.status] && viewingOrder.status !== "Confirmed" && viewingOrder.status !== "In Transit" && (
								<button
									onClick={() => void handleProgressOrder(viewingOrder)}
									className="flex-1 px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors"
								>
									Mark as {nextStatusMap[viewingOrder.status]}
								</button>
							)}
							{viewingOrder.status !== "Completed" && viewingOrder.status !== "Cancelled" && (
								<button onClick={() => void handleCancelOrder(viewingOrder)} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Cancel PO</button>
							)}
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
