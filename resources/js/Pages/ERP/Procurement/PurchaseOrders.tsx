import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState, useEffect } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import { purchaseRequestApi } from "@/services/purchaseRequestApi";
import type { PurchaseOrder as PurchaseOrderType, PurchaseRequest } from "@/types/procurement";

type PurchaseOrderStatus = "draft" | "sent" | "confirmed" | "in_transit" | "delivered" | "completed" | "cancelled";
type MetricColor = "success" | "warning" | "info";

interface PurchaseOrderFormState {
	selectedPrId: number | null;
	expectedDeliveryDate: string;
	paymentTerms: string;
	notes: string;
}

interface ReceivingFormState {
	receivedQuantity: string;
	defectiveQuantity: string;
	actualDeliveryDate: string;
	notes: string;
}

const formatStatus = (status: string): string => {
	const statusMap: Record<string, string> = {
		draft: "Draft",
		sent: "Sent",
		confirmed: "Confirmed",
		in_transit: "In Transit",
		delivered: "Delivered",
		completed: "Completed",
		cancelled: "Cancelled"
	};
	return statusMap[status] || status;
};

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const initialFormState: PurchaseOrderFormState = {
	selectedPrId: null,
	expectedDeliveryDate: "",
	paymentTerms: "Net 30",
	notes: "",
};

const statusBadgeClass: Record<string, string> = {
	draft: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	sent: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	confirmed: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	in_transit: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	delivered: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
	completed: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	cancelled: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
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
	draft: "sent",
	sent: "confirmed",
	confirmed: "in_transit",
	in_transit: "delivered",
	delivered: "completed",
};

export default function PurchaseOrders() {
	const { initialData, initialApprovedPRs } = usePage().props as any;
	const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrderType[]>(initialData?.data ?? []);
	const [approvedPRs, setApprovedPRs] = useState<PurchaseRequest[]>(initialApprovedPRs ?? []);
	const [loading, setLoading] = useState(false);
	const [metrics, setMetrics] = useState({ total_orders: 0, active_orders: 0, completed_orders: 0 });
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [viewingOrder, setViewingOrder] = useState<PurchaseOrderType | null>(null);
	const [receivingOrder, setReceivingOrder] = useState<PurchaseOrderType | null>(null);
	const [receivingData, setReceivingData] = useState<ReceivingFormState>({
		receivedQuantity: "",
		defectiveQuantity: "0",
		actualDeliveryDate: new Date().toISOString().split("T")[0],
		notes: "",
	});
	const [formData, setFormData] = useState<PurchaseOrderFormState>(initialFormState);

	const fetchPurchaseOrders = async () => {
		try {
			setLoading(true);
			const response = await purchaseOrderApi.getAll();
			setPurchaseOrders(response.data);
		} catch (error) {
			console.error("Failed to fetch purchase orders:", error);
			Swal.fire("Error", "Failed to load purchase orders", "error");
		} finally {
			setLoading(false);
		}
	};

	const fetchApprovedPRs = async () => {
		try {
			const response = await purchaseRequestApi.getApproved();
			setApprovedPRs(response);
		} catch (error) {
			console.error("Failed to fetch approved PRs:", error);
		}
	};

	const fetchMetrics = async () => {
		try {
			const data = await purchaseOrderApi.getMetrics();
			setMetrics(data);
		} catch (error) {
			console.error("Failed to fetch metrics:", error);
		}
	};

	useEffect(() => {
		fetchMetrics();
	}, []);

	const selectedPrOption = useMemo(
		() => approvedPRs.find((item) => item.id === formData.selectedPrId) ?? null,
		[formData.selectedPrId, approvedPRs]
	);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		if (!query) return purchaseOrders;

		return purchaseOrders.filter((order) =>
			order.po_number.toLowerCase().includes(query) ||
			(order.purchase_request?.pr_number || "").toLowerCase().includes(query) ||
			order.product_name.toLowerCase().includes(query) ||
			(order.supplier?.name || "").toLowerCase().includes(query) ||
			order.status.toLowerCase().includes(query)
		);
	}, [searchQuery, purchaseOrders]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalPoCount = metrics.total_orders;
	const activePoCount = metrics.active_orders;
	const completedPoCount = metrics.completed_orders;

	const closeCreateModal = () => {
		setIsCreateModalOpen(false);
		setFormData(initialFormState);
	};

	const handleCreatePO = async () => {
		if (!formData.selectedPrId || !formData.expectedDeliveryDate.trim() || !formData.paymentTerms.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Missing fields",
				text: "Please select approved PR, expected delivery date, and payment terms.",
				confirmButtonColor: "#111827",
			});
			return;
		}

		try {
			await purchaseOrderApi.create({
				pr_id: formData.selectedPrId,
				expected_delivery_date: formData.expectedDeliveryDate,
				payment_terms: formData.paymentTerms,
				notes: formData.notes.trim() || undefined,
			});

			await Swal.fire({
				icon: "success",
				title: "Purchase Order Created",
				text: "PO is ready to be sent to supplier.",
				confirmButtonColor: "#111827",
				timer: 1500,
				showConfirmButton: false,
			});

			closeCreateModal();
			fetchPurchaseOrders();
			fetchMetrics();
		} catch (error) {
			console.error("Failed to create PO:", error);
			await Swal.fire({
				icon: "error",
				title: "Creation Failed",
				text: "Failed to create purchase order. Please try again.",
				confirmButtonColor: "#111827",
			});
		}
	};

	const handleProgressOrder = async (order: PurchaseOrderType) => {
		const nextStatus = nextStatusMap[order.status as PurchaseOrderStatus];
		if (!nextStatus) return;

		// Delivering requires goods receipt verification — open dedicated modal
		if (nextStatus === "delivered") {
			setReceivingOrder(order);
			setReceivingData({
				receivedQuantity: String(order.quantity),
				defectiveQuantity: "0",
				actualDeliveryDate: new Date().toISOString().split("T")[0],
				notes: "",
			});
			return;
		}

		const result = await Swal.fire({
			title: `Move to ${formatStatus(nextStatus)}?`,
			text: `${order.po_number} will be updated from ${formatStatus(order.status)} to ${formatStatus(nextStatus)}.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: `Yes, mark as ${formatStatus(nextStatus)}`,
			cancelButtonText: "Cancel",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		try {
			await purchaseOrderApi.updateStatus(order.id, { status: nextStatus });

			await Swal.fire({
				title: "Updated",
				text: `${order.po_number} is now ${formatStatus(nextStatus)}.`,
				icon: "success",
				timer: 1400,
				showConfirmButton: false,
			});

			fetchPurchaseOrders();
			fetchMetrics();
			if (viewingOrder && viewingOrder.id === order.id) {
				const updatedOrder = await purchaseOrderApi.getById(order.id);
				setViewingOrder(updatedOrder);
			}
		} catch (error) {
			console.error("Failed to update status:", error);
			await Swal.fire({
				title: "Error",
				text: "Failed to update order status. Please try again.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		}
	};

	const handleConfirmReceiving = async () => {
		if (!receivingOrder) return;

		const received = Number(receivingData.receivedQuantity);
		const defective = Number(receivingData.defectiveQuantity);

		if (Number.isNaN(received) || received < 0) {
			await Swal.fire({ icon: "warning", title: "Invalid", text: "Received quantity must be 0 or more.", confirmButtonColor: "#111827" });
			return;
		}
		if (Number.isNaN(defective) || defective < 0) {
			await Swal.fire({ icon: "warning", title: "Invalid", text: "Defective quantity must be 0 or more.", confirmButtonColor: "#111827" });
			return;
		}
		if (defective > received) {
			await Swal.fire({ icon: "warning", title: "Invalid", text: "Defective quantity cannot exceed received quantity.", confirmButtonColor: "#111827" });
			return;
		}

		try {
			await purchaseOrderApi.markAsDelivered(receivingOrder.id, {
				actual_delivery_date: receivingData.actualDeliveryDate || new Date().toISOString().split("T")[0],
				received_quantity: received,
				defective_quantity: defective,
				notes: receivingData.notes || undefined,
			});

			const netAccepted = received - defective;
			await Swal.fire({
				title: "Goods Received",
				html: defective > 0
					? `<p>${receivingOrder.po_number} marked as delivered.</p><p class="mt-1 text-sm text-gray-500">Received: ${received} &nbsp;|&nbsp; Defective: ${defective} &nbsp;|&nbsp; <strong>Added to inventory: ${netAccepted}</strong></p>`
					: `<p>${receivingOrder.po_number} marked as delivered.</p><p class="mt-1 text-sm text-gray-500">${netAccepted} units added to inventory.</p>`,
				icon: "success",
				confirmButtonColor: "#111827",
				timer: 2500,
				showConfirmButton: false,
			});

			setReceivingOrder(null);
			fetchPurchaseOrders();
			fetchMetrics();
			if (viewingOrder && viewingOrder.id === receivingOrder.id) {
				const updatedOrder = await purchaseOrderApi.getById(receivingOrder.id);
				setViewingOrder(updatedOrder);
			}
		} catch (error) {
			console.error("Failed to confirm receipt:", error);
			await Swal.fire({ icon: "error", title: "Error", text: "Failed to confirm goods receipt. Please try again.", confirmButtonColor: "#111827" });
		}
	};

	const handleSetOrderStatus = async (order: PurchaseOrderType, nextStatus: PurchaseOrderStatus) => {
		if (order.status === nextStatus) return;

		const result = await Swal.fire({
			title: `Move to ${formatStatus(nextStatus)}?`,
			text: `${order.po_number} will be updated from ${formatStatus(order.status)} to ${formatStatus(nextStatus)}.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: `Yes, mark as ${formatStatus(nextStatus)}`,
			cancelButtonText: "Cancel",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		try {
			if (nextStatus === "delivered") {
				await purchaseOrderApi.markAsDelivered(order.id);
			} else {
				await purchaseOrderApi.updateStatus(order.id, { status: nextStatus });
			}

			await Swal.fire({
				title: "Updated",
				text: `${order.po_number} is now ${formatStatus(nextStatus)}.`,
				icon: "success",
				timer: 1400,
				showConfirmButton: false,
			});

			fetchPurchaseOrders();
			fetchMetrics();
			if (viewingOrder && viewingOrder.id === order.id) {
				const updatedOrder = await purchaseOrderApi.getById(order.id);
				setViewingOrder(updatedOrder);
			}
		} catch (error) {
			console.error("Failed to update status:", error);
			await Swal.fire({
				title: "Error",
				text: "Failed to update order status. Please try again.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		}
	};

	const handleCancelOrder = async (order: PurchaseOrderType) => {
		if (order.status === "completed" || order.status === "cancelled") return;

		const { value: reason } = await Swal.fire({
			title: "Cancel this PO?",
			text: `${order.po_number} will be marked as Cancelled.`,
			input: "textarea",
			inputLabel: "Cancellation Reason",
			inputPlaceholder: "Enter reason for cancellation...",
			inputAttributes: {
				"aria-label": "Enter reason for cancellation"
			},
			showCancelButton: true,
			confirmButtonText: "Yes, cancel PO",
			cancelButtonText: "Back",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
			inputValidator: (value) => {
				if (!value) {
					return "Please provide a reason for cancellation";
				}
				return null;
			}
		});

		if (!reason) return;

		try {
			await purchaseOrderApi.cancel(order.id, { cancellation_reason: reason });

			await Swal.fire({
				title: "Cancelled",
				text: `${order.po_number} has been cancelled.`,
				icon: "success",
				timer: 1400,
				showConfirmButton: false,
			});

			fetchPurchaseOrders();
			fetchMetrics();
			if (viewingOrder && viewingOrder.id === order.id) {
				setViewingOrder(null);
			}
		} catch (error) {
			console.error("Failed to cancel order:", error);
			await Swal.fire({
				title: "Error",
				text: "Failed to cancel order. Please try again.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		}
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingOrder) || Boolean(receivingOrder);

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

					{loading ? (
						<div className="text-center py-8">
							<div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-white"></div>
							<p className="mt-2 text-gray-600 dark:text-gray-400">Loading purchase orders...</p>
						</div>
					) : paginatedItems.length === 0 ? (
						<div className="text-center py-8 text-gray-500 dark:text-gray-400">
							{searchQuery ? "No purchase orders found matching your search." : "No purchase orders yet. Create your first PO."}
						</div>
					) : (
						<>
							<div className="overflow-x-auto">
								<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
									<thead className="bg-gray-50 dark:bg-gray-800/50">
										<tr>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">PO No</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">PR No</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Product</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Supplier</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Total Cost</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Status</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Actions</th>
										</tr>
									</thead>
									<tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
										{paginatedItems.map((order) => (
											<tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
												<td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">{order.po_number}</td>
												<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{order.purchase_request?.pr_number || "—"}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{order.product_name}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{order.supplier?.name || "—"}</td>
												<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{currency.format(order.total_cost)}</td>
												<td className="px-4 py-3 whitespace-nowrap">
													<span className={`px-2 py-1 text-xs font-semibold rounded-full ${statusBadgeClass[order.status]}`}>
														{formatStatus(order.status)}
													</span>
												</td>
												<td className="px-4 py-3 whitespace-nowrap text-sm">
													<div className="flex gap-2">
														<button
															onClick={() => setViewingOrder(order)}
															className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium"
														>
															View
														</button>
														{nextStatusMap[order.status as PurchaseOrderStatus] && (
															<button
																onClick={() => handleProgressOrder(order)}
																className="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 font-medium"
															>
																Progress
															</button>
														)}
													</div>
												</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>

							<div className="mt-4 flex items-center justify-between">
								<p className="text-sm text-gray-500">
									Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} orders
								</p>
								<div className="flex gap-2">
									<button
										onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
										disabled={currentPage === 1}
										className="px-3 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-800"
									>
										<ChevronLeftIcon className="w-5 h-5" />
									</button>
									<button
										onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
										disabled={currentPage === totalPages}
										className="px-3 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-800"
									>
										<ChevronRightIcon className="w-5 h-5" />
									</button>
								</div>
							</div>
						</>
					)}
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
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Select Approved PR *</label>
								<select
									value={formData.selectedPrId || ""}
									onChange={(event) => setFormData((prev) => ({ ...prev, selectedPrId: event.target.value ? Number(event.target.value) : null }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">-- Choose an approved PR --</option>
									{approvedPRs.map((pr) => (
										<option key={pr.id} value={pr.id}>
											{pr.pr_number} - {pr.product_name} (Qty: {pr.quantity}{pr.requested_size ? `, Size: ${pr.requested_size}` : ""}, {currency.format(pr.total_cost)})
										</option>
									))}
								</select>
								{approvedPRs.length === 0 && (
									<p className="mt-1 text-xs text-amber-600 dark:text-amber-400">&#9888; No approved PRs available. All approved PRs may already have purchase orders.</p>
								)}
							</div>

							{selectedPrOption && (
								<div className="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Supplier:</strong> {selectedPrOption.supplier?.name || "N/A"}</p>
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Quantity:</strong> {selectedPrOption.quantity} units</p>
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Unit Cost:</strong> {currency.format(selectedPrOption.unit_cost)}</p>
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Total:</strong> {currency.format(selectedPrOption.total_cost)}</p>
								</div>
							)}

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Expected Delivery Date *</label>
									<input
										type="date"
										value={formData.expectedDeliveryDate}
										onChange={(event) => setFormData((prev) => ({ ...prev, expectedDeliveryDate: event.target.value }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Payment Terms *</label>
									<select
										value={formData.paymentTerms}
										onChange={(event) => setFormData((prev) => ({ ...prev, paymentTerms: event.target.value }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									>
										<option value="Net 30">Net 30</option>
										<option value="COD">COD</option>
										<option value="50% down, 50% on delivery">50% down, 50% on delivery</option>
										<option value="Net 15">Net 15</option>
										<option value="Net 60">Net 60</option>
									</select>
								</div>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Notes</label>
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
							<div className="grid grid-cols-2 gap-4">
								<div>
									<p className="text-sm text-gray-500 dark:text-gray-400">PO Number</p>
									<p className="text-base font-medium text-gray-900 dark:text-white">{viewingOrder.po_number}</p>
								</div>
								<div>
									<p className="text-sm text-gray-500 dark:text-gray-400">PR Number</p>
									<p className="text-base font-medium text-gray-900 dark:text-white">{viewingOrder.purchase_request?.pr_number || "—"}</p>
								</div>
							</div>

							<div>
								<p className="text-sm text-gray-500 dark:text-gray-400">Status</p>
								<span className={`inline-block mt-1 px-2 py-1 text-xs font-semibold rounded-full ${statusBadgeClass[viewingOrder.status]}`}>
									{formatStatus(viewingOrder.status)}
								</span>
							</div>

							<div className="border-t border-gray-200 dark:border-gray-700 pt-4">
								<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-2">Product Details</h3>
								<div className="space-y-2">
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Product:</strong> {viewingOrder.product_name}</p>
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Supplier:</strong> {viewingOrder.supplier?.name || "—"}</p>
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Quantity Ordered:</strong> {viewingOrder.quantity} units</p>
									{viewingOrder.received_quantity != null && (
										<p className="text-sm text-gray-700 dark:text-gray-300">
											<strong>Received:</strong> {viewingOrder.received_quantity} units
											{(viewingOrder.defective_quantity ?? 0) > 0 && (
												<span className="ml-2 text-amber-600 dark:text-amber-400">({viewingOrder.defective_quantity} defective — {viewingOrder.received_quantity - (viewingOrder.defective_quantity ?? 0)} accepted)</span>
											)}
										</p>
									)}
									{viewingOrder.requested_size && (
										<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Requested Size:</strong> <span className="text-indigo-600 dark:text-indigo-400">Size {viewingOrder.requested_size}</span></p>
									)}
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Unit Cost:</strong> {currency.format(viewingOrder.unit_cost)}</p>
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Total Cost:</strong> {currency.format(viewingOrder.total_cost)}</p>
								</div>
							</div>

							<div className="border-t border-gray-200 dark:border-gray-700 pt-4">
								<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-2">Order Information</h3>
								<div className="space-y-2">
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Payment Terms:</strong> {viewingOrder.payment_terms}</p>
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Expected Delivery:</strong> {viewingOrder.expected_delivery_date || "—"}</p>
									{viewingOrder.actual_delivery_date && (
										<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Actual Delivery:</strong> {viewingOrder.actual_delivery_date}</p>
									)}
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Ordered By:</strong> {viewingOrder.orderer?.name || "—"}</p>
									<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Ordered Date:</strong> {viewingOrder.ordered_date}</p>
								</div>
							</div>

							{viewingOrder.notes && (
								<div className="border-t border-gray-200 dark:border-gray-700 pt-4">
									<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-2">Notes</h3>
									<p className="text-sm text-gray-700 dark:text-gray-300">{viewingOrder.notes}</p>
								</div>
							)}
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingOrder(null)} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Close</button>
							{nextStatusMap[viewingOrder.status as PurchaseOrderStatus] && (
								<button
									onClick={() => handleProgressOrder(viewingOrder)}
									className="flex-1 px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors"
								>
									Mark as {formatStatus(nextStatusMap[viewingOrder.status as PurchaseOrderStatus]!)}
								</button>
							)}
							{viewingOrder.status !== "completed" && viewingOrder.status !== "cancelled" && (
								<button
									onClick={() => handleCancelOrder(viewingOrder)}
									className="flex-1 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-colors"
								>
									Cancel PO
								</button>
							)}
						</div>
					</div>
				</div>
			)}
		{/* ── Goods Receipt Verification Modal ── */}
			{receivingOrder && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close goods receipt modal" className="absolute inset-0 bg-black/50" onClick={() => setReceivingOrder(null)} />
					<div className="relative w-full max-w-lg rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<div>
								<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Goods Receipt Verification</h2>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{receivingOrder.po_number} — {receivingOrder.product_name}</p>
							</div>
							<button onClick={() => setReceivingOrder(null)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							{/* Ordered qty reference */}
							<div className="flex items-center gap-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 px-4 py-3">
								<svg className="h-4 w-4 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 110 20A10 10 0 0112 2z" /></svg>
								<span className="text-sm text-blue-700 dark:text-blue-300">
									Ordered quantity: <strong>{receivingOrder.quantity} units</strong>
									{receivingOrder.requested_size && <span className="ml-2 text-blue-500">(Size {receivingOrder.requested_size})</span>}
								</span>
							</div>

							<div className="grid grid-cols-2 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
										Received Quantity *
										<span className="ml-1 text-xs text-gray-400 font-normal">(max: {receivingOrder.quantity})</span>
									</label>
									<input
										type="number"
										min={0}
										max={receivingOrder.quantity}
										value={receivingData.receivedQuantity}
										onChange={(e) => setReceivingData((prev) => ({ ...prev, receivedQuantity: e.target.value, defectiveQuantity: "0" }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
										Defective / Damaged
										<span className="ml-1 text-xs text-gray-400 font-normal">(0 if none)</span>
									</label>
									<input
										type="number"
										min={0}
										max={Number(receivingData.receivedQuantity) || 0}
										value={receivingData.defectiveQuantity}
										onChange={(e) => setReceivingData((prev) => ({ ...prev, defectiveQuantity: e.target.value }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
							</div>

							{/* Net accepted summary */}
							{(() => {
								const rec = Number(receivingData.receivedQuantity) || 0;
								const def = Number(receivingData.defectiveQuantity) || 0;
								const net = rec - def;
								const short = receivingOrder.quantity - rec;
								return (
									<div className={`rounded-xl border px-4 py-3 ${net < receivingOrder.quantity ? "bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800" : "bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800"}`}>
										<p className={`text-sm font-semibold ${net < receivingOrder.quantity ? "text-amber-700 dark:text-amber-300" : "text-green-700 dark:text-green-300"}`}>
											✅ Net added to inventory: <span className="text-base">{Math.max(0, net)} units</span>
										</p>
										{short > 0 && <p className="text-xs text-amber-600 dark:text-amber-400 mt-0.5">⚠ Short by {short} unit{short !== 1 ? "s" : ""} vs ordered quantity</p>}
										{def > 0 && <p className="text-xs text-amber-600 dark:text-amber-400 mt-0.5">⚠ {def} defective unit{def !== 1 ? "s" : ""} will NOT be added to inventory</p>}
									</div>
								);
							})()}

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Actual Delivery Date *</label>
								<input
									type="date"
									value={receivingData.actualDeliveryDate}
									onChange={(e) => setReceivingData((prev) => ({ ...prev, actualDeliveryDate: e.target.value }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Notes <span className="text-xs text-gray-400 font-normal">(required if short/defective)</span></label>
								<textarea
									rows={2}
									value={receivingData.notes}
									onChange={(e) => setReceivingData((prev) => ({ ...prev, notes: e.target.value }))}
									placeholder="Describe any missing items, damage, or supplier issues..."
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button onClick={() => setReceivingOrder(null)} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Cancel</button>
							<button onClick={handleConfirmReceiving} className="flex-1 px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors">
								Confirm Receipt &amp; Update Inventory
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
