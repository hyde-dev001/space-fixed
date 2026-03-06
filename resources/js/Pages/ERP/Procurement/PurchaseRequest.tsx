import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState, useEffect } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { purchaseRequestApi, supplierApi, type PurchaseRequest as PurchaseRequestType, type Supplier } from "@/services/procurementApi";
import { stockRequestApi } from "@/services/stockRequestApi";
import type { StockRequestApproval } from "@/types/procurement";

type RequestPriority = "high" | "medium" | "low";
type PurchaseRequestStatus = "draft" | "pending_finance" | "approved" | "rejected";
type MetricColor = "success" | "warning" | "info";

interface PurchaseRequestFormState {
	productName: string;
	requestedSize: string;
	supplierId: string;
	quantity: string;
	unitCost: string;
	priority: RequestPriority;
	justification: string;
	inventoryItemId: string;
}

const initialFormState: PurchaseRequestFormState = {
	productName: "",
	requestedSize: "",
	supplierId: "",
	quantity: "",
	unitCost: "",
	priority: "medium",
	justification: "",
	inventoryItemId: "",
};

const formatPriority = (priority: string): string => {
	const map: Record<string, string> = {
		high: "High",
		medium: "Medium",
		low: "Low",
	};
	return map[priority] || priority;
};

const formatStatus = (status: string): string => {
	const map: Record<string, string> = {
		draft: "Draft",
		pending_finance: "Pending Finance",
		approved: "Approved",
		rejected: "Rejected",
	};
	return map[status] || status;
};

const priorityBadgeClass: Record<string, string> = {
	high: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
	low: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<string, string> = {
	draft: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
	pending_finance: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
	approved: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	rejected: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
};

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

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

export default function PurchaseRequest() {
	const { initialData, initialSuppliers, initialAcceptedRequests } = usePage().props as any;
	const [purchaseRequests, setPurchaseRequests] = useState<PurchaseRequestType[]>(initialData?.data ?? []);
	const [suppliers, setSuppliers] = useState<Supplier[]>(initialSuppliers ?? []);
	const [acceptedStockRequests, setAcceptedStockRequests] = useState<StockRequestApproval[]>(initialAcceptedRequests?.data ?? []);
	const [loading, setLoading] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [viewingRequest, setViewingRequest] = useState<PurchaseRequestType | null>(null);
	const [formData, setFormData] = useState<PurchaseRequestFormState>(initialFormState);
	const [metrics, setMetrics] = useState({ total_requests: 0, pending_finance: 0, approved: 0 });

	// Fetch purchase requests
	const fetchPurchaseRequests = async () => {
		try {
			setLoading(true);
			const response = await purchaseRequestApi.getAll({ page: currentPage, per_page: 100 });
			setPurchaseRequests(response.data || []);
		} catch (error) {
			console.error("Error fetching purchase requests:", error);
			await Swal.fire({
				icon: "error",
				title: "Error",
				text: "Failed to load purchase requests. Please try again.",
				confirmButtonColor: "#2563eb",
			});
		} finally {
			setLoading(false);
		}
	};

	// Fetch suppliers
	const fetchSuppliers = async () => {
		try {
			const response = await supplierApi.getAll({ page: 1, per_page: 100 });
			setSuppliers(response.data || []);
		} catch (error) {
			console.error("Error fetching suppliers:", error);
		}
	};

	// Fetch accepted stock requests — only these may become a PR
	const fetchAcceptedStockRequests = async () => {
		try {
			const response = await stockRequestApi.getAll({ status: 'accepted', per_page: 200 });
			const data = (response as any).data ?? response ?? [];
			setAcceptedStockRequests(Array.isArray(data) ? data : []);
		} catch (error) {
			console.error("Error fetching accepted stock requests:", error);
		}
	};

	// Fetch metrics
	const fetchMetrics = async () => {
		try {
			const data = await purchaseRequestApi.getMetrics();
			setMetrics(data);
		} catch (error) {
			console.error("Error fetching metrics:", error);
		}
	};

	useEffect(() => {
		fetchMetrics();
	}, []);

	// Inventory item IDs that already have an active (non-rejected) PR
	const activeInventoryItemIds = useMemo(() => {
		return new Set(
			(purchaseRequests || [])
				.filter((pr) => pr.status !== 'rejected')
				.map((pr) => String(pr.inventory_item_id))
				.filter(Boolean)
		);
	}, [purchaseRequests]);

	// Map inventory_item_id → existing active PR number (for warning messages)
	const activePrByInventoryItemId = useMemo(() => {
		const map: Record<string, string> = {};
		(purchaseRequests || [])
			.filter((pr) => pr.status !== 'rejected')
			.forEach((pr) => {
				if (pr.inventory_item_id) {
					map[String(pr.inventory_item_id)] = pr.pr_number;
				}
			});
		return map;
	}, [purchaseRequests]);

	const filteredData = useMemo(() => {
		if (!purchaseRequests || !Array.isArray(purchaseRequests)) return [];
		const query = searchQuery.trim().toLowerCase();
		if (!query) return purchaseRequests;

		return purchaseRequests.filter((request) =>
			request.pr_number.toLowerCase().includes(query) ||
			request.product_name.toLowerCase().includes(query) ||
			request.supplier?.name.toLowerCase().includes(query) ||
			request.status.toLowerCase().includes(query)
		);
	}, [searchQuery, purchaseRequests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil((filteredData?.length || 0) / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData?.slice(startIndex, startIndex + itemsPerPage) || [];

	const closeCreateModal = () => {
		setIsCreateModalOpen(false);
		setFormData(initialFormState);
	};

	const handleCreatePR = async () => {
		if (!formData.inventoryItemId.trim() || !formData.supplierId.trim() || !formData.quantity.trim() || !formData.unitCost.trim() || !formData.justification.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Missing fields",
				text: "Please select an approved stock request, a supplier, quantity, unit cost, and justification.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		if (activeInventoryItemIds.has(formData.inventoryItemId)) {
			const existingPrNumber = activePrByInventoryItemId[formData.inventoryItemId];
			await Swal.fire({
				icon: "error",
				title: "Duplicate PR",
				text: `${existingPrNumber} already covers this product. You can only submit a new PR after the existing one is rejected.`,
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const quantity = Number(formData.quantity);
		const unitCost = Number(formData.unitCost);

		if (Number.isNaN(quantity) || Number.isNaN(unitCost) || quantity <= 0 || unitCost <= 0) {
			await Swal.fire({
				icon: "warning",
				title: "Invalid quantity or cost",
				text: "Quantity and unit cost must be greater than zero.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		try {
			setLoading(true);
			const requestData: Record<string, unknown> = {
				product_name: formData.productName,
				supplier_id: Number(formData.supplierId),
				inventory_item_id: Number(formData.inventoryItemId),
				requested_size: formData.requestedSize || undefined,
				quantity,
				unit_cost: unitCost,
				priority: formData.priority,
				justification: formData.justification,
				submit_to_finance: true,
			};

			console.log("Sending purchase request:", requestData);
			const newPR = await purchaseRequestApi.create(requestData);

			closeCreateModal();
			await fetchPurchaseRequests();
			await fetchMetrics();

			await Swal.fire({
				icon: "success",
				title: "Sent to Finance",
				text: `${newPR.pr_number} has been submitted for finance approval.`,
				confirmButtonColor: "#2563eb",
				timer: 2000,
				showConfirmButton: false,
			});
		} catch (error: any) {
			console.error("Error creating purchase request:", error);
			console.error("Error response:", error.response?.data);
			
			let errorMessage = "Failed to create purchase request. Please try again.";
			if (error.response?.data?.errors) {
				const validationErrors = error.response.data.errors;
				errorMessage = Object.values(validationErrors).flat().join(", ");
			} else if (error.response?.data?.message) {
				errorMessage = error.response.data.message;
			}
			
			await Swal.fire({
				icon: "error",
				title: "Error",
				text: errorMessage,
				confirmButtonColor: "#2563eb",
			});
		} finally {
			setLoading(false);
		}
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingRequest);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Purchase Request - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Purchase Request</h1>
						<p className="text-gray-600 dark:text-gray-400">Build PR using selected supplier, set quantity and cost, then submit to Finance</p>
					</div>
					<button
						onClick={() => setIsCreateModalOpen(true)}
						className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors whitespace-nowrap"
					>
						+ New PR
					</button>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total PR" value={metrics.total_requests} description="Purchase requests created" icon={ClipboardIcon} color="info" />
					<MetricCard title="Pending Finance" value={metrics.pending_finance} description="Requests waiting approval" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved" value={metrics.approved} description="Finance-approved requests" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Purchase Request Table</h2>
						<p className="text-sm text-gray-500">Track PR quantity, cost, supplier, and finance approval status</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by PR no, product, supplier, or status..."
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
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">PR no</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product / Supplier</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Unit cost</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Total</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{loading ? (
									<tr>
										<td colSpan={8} className="px-4 py-10 text-center text-sm text-gray-500">
											<div className="flex justify-center items-center">
												<svg className="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
													<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
													<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
												</svg>
											</div>
										</td>
									</tr>
								) : paginatedItems.length > 0 ? (
									paginatedItems.map((request) => (
										<tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{request.pr_number}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{request.product_name}</p>
												<p className="text-xs text-gray-500 dark:text-gray-400">{request.supplier?.name || 'N/A'}</p>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.quantity}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{currency.format(request.unit_cost)}</td>
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white whitespace-nowrap">{currency.format(request.total_cost)}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[request.priority]}`}>
													{formatPriority(request.priority)}
												</span>
											</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
													{formatStatus(request.status)}
												</span>
											</td>
											<td className="px-4 py-3 text-center">
												<button
													onClick={() => setViewingRequest(request)}
													className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
													title="View PR details"
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
										<td colSpan={8} className="px-4 py-10 text-center text-sm text-gray-500">No purchase requests found.</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {(filteredData?.length || 0) === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData?.length || 0)} of {filteredData?.length || 0} requests
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
					<button type="button" aria-label="Close create purchase request modal" className="absolute inset-0 bg-black/50" onClick={closeCreateModal} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Purchase Request Builder</h2>
							<button onClick={closeCreateModal} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							{/* Only approved stock requests can be turned into a PR */}
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Approved Stock Request *
									<span className="ml-1 text-xs text-gray-400 font-normal">(auto-fills product, quantity &amp; priority)</span>
								</label>
								<select
									title="Select approved stock request"
									aria-label="Select approved stock request"
									value={formData.inventoryItemId}
									onChange={(event) => {
										const sr = acceptedStockRequests.find((r) => String(r.inventory_item_id) === event.target.value);
										setFormData((prev) => ({
											...prev,
											inventoryItemId: event.target.value,
											productName:   sr ? sr.product_name : "",
											requestedSize: sr ? (sr.requested_size ?? "") : "",
											quantity:      sr ? String(sr.quantity_needed) : "",
											priority:      sr ? sr.priority : prev.priority,
										}));
									}}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">— Select an approved stock request —</option>
									{acceptedStockRequests
										.filter((sr) => !activeInventoryItemIds.has(String(sr.inventory_item_id)))
										.map((sr) => (
											<option key={sr.id} value={String(sr.inventory_item_id)}>
												{sr.request_number} — {sr.product_name} (Qty: {sr.quantity_needed}{sr.requested_size ? `, Size: ${sr.requested_size}` : ""})
											</option>
										))}
								</select>
								{acceptedStockRequests.filter((sr) => !activeInventoryItemIds.has(String(sr.inventory_item_id))).length === 0 && (
									<p className="mt-1 text-xs text-amber-600 dark:text-amber-400">⚠ No approved stock requests yet. Inventory staff must submit and get approval first.</p>
								)}
								{formData.inventoryItemId && activeInventoryItemIds.has(formData.inventoryItemId) && (
									<div className="mt-2 flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 px-4 py-3">
										<svg className="h-4 w-4 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
										</svg>
										<span className="text-sm text-amber-700 dark:text-amber-300">
											A PR for this product already exists (<strong>{activePrByInventoryItemId[formData.inventoryItemId]}</strong>). You cannot submit another until the existing one is rejected.
										</span>
									</div>
								)}
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Product Name</label>
								<input
									type="text"
									value={formData.productName}
									readOnly
									placeholder="Auto-filled from stock request"
									className="w-full px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800/60 text-gray-700 dark:text-gray-300 placeholder-gray-400 dark:placeholder-gray-500 cursor-not-allowed"
								/>
							</div>

						{formData.requestedSize && (
							<div className="flex items-center gap-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 px-4 py-3">
								<svg className="h-4 w-4 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
									<path strokeLinecap="round" strokeLinejoin="round" d="M7 7h10M7 12h6" />
									<rect x="3" y="3" width="18" height="18" rx="2" />
								</svg>
								<span className="text-sm text-indigo-700 dark:text-indigo-300">
									Inventory requested <strong>Size {formData.requestedSize}</strong> specifically
								</span>
							</div>
						)}
						<div>
							<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supplier (from Suppliers Management) *</label>
							<select
								title="Select supplier"
								aria-label="Select supplier"
								value={formData.supplierId}
								onChange={(event) => setFormData((prev) => ({ ...prev, supplierId: event.target.value }))}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="">Select supplier</option>
								{suppliers.map((supplier) => (
									<option key={supplier.id} value={supplier.id}>{supplier.name} ({supplier.contact_email})</option>
								))}
							</select>
						</div>

							<div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quantity</label>
									<input
										type="number"
										min={1}
										value={formData.quantity}
										readOnly
										placeholder="Auto-filled from stock request"
										className="w-full px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800/60 text-gray-700 dark:text-gray-300 placeholder-gray-400 cursor-not-allowed"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Unit Cost *</label>
									<input
										type="number"
										min={1}
										value={formData.unitCost}
										onChange={(event) => setFormData((prev) => ({ ...prev, unitCost: event.target.value }))}
										placeholder="e.g., 5100"
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority *</label>
									<select
										title="Select priority"
										aria-label="Select priority"
										value={formData.priority}
										onChange={(event) => setFormData((prev) => ({ ...prev, priority: event.target.value as RequestPriority }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									>
										<option value="high">High</option>
										<option value="medium">Medium</option>
										<option value="low">Low</option>
									</select>
								</div>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Justification *</label>
								<textarea
									rows={3}
									value={formData.justification}
									onChange={(event) => setFormData((prev) => ({ ...prev, justification: event.target.value }))}
									placeholder="Explain why this PR is needed before submitting to Finance"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button onClick={closeCreateModal} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Cancel</button>
							<button
								onClick={handleCreatePR}
								disabled={!!(formData.inventoryItemId && activeInventoryItemIds.has(formData.inventoryItemId))}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium transition-colors"
							>
								Send to Finance
							</button>
						</div>
					</div>
				</div>
			)}

			{viewingRequest && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close PR details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingRequest(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Purchase Request Details</h2>
							<button onClick={() => setViewingRequest(null)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">PR No</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.pr_number}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewingRequest.status]}`}>{formatStatus(viewingRequest.status)}</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product / Supplier</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.product_name}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">{viewingRequest.supplier?.name || 'N/A'}</p>
							</div>

{viewingRequest.requested_size && (
							<div className="flex items-center gap-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 px-4 py-3">
								<svg className="h-4 w-4 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
									<path strokeLinecap="round" strokeLinejoin="round" d="M7 7h10M7 12h6" />
									<rect x="3" y="3" width="18" height="18" rx="2" />
								</svg>
								<span className="text-sm text-indigo-700 dark:text-indigo-300">
									Requested Size: <strong>Size {viewingRequest.requested_size}</strong>
									</span>
								</div>
							)}

							<div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.quantity}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Unit Cost</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{currency.format(viewingRequest.unit_cost)}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Total Cost</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{currency.format(viewingRequest.total_cost)}</p>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Justification</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white whitespace-pre-wrap">{viewingRequest.justification}</p>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingRequest(null)} className="w-full px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors">Close</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
