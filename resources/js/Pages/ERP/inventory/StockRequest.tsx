import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { stockRequestApi } from "@/services/stockRequestApi";
import { inventoryItemAPI } from "@/services/inventoryAPI";
import type { InventoryItem } from "@/types/inventory";
import type { StockRequestApproval } from "@/types/procurement";

type DisplayStatus = "Pending" | "Approved" | "Rejected" | "Needs Details";
type MetricColor = "success" | "warning" | "info";

interface RequestFormState {
	inventoryItemId: string;
	requestSize: string;
	quantityNeeded: string;
	priority: "high" | "medium" | "low";
	notes: string;
}

const initialFormState: RequestFormState = {
	inventoryItemId: "",
	requestSize: "",
	quantityNeeded: "",
	priority: "medium",
	notes: "",
};

// Map API status (lowercase) → display label
function getDisplayStatus(status: StockRequestApproval["status"]): DisplayStatus {
	switch (status) {
		case "accepted":    return "Approved";
		case "rejected":    return "Rejected";
		case "needs_details": return "Needs Details";
		default:            return "Pending";
	}
}

// Map API priority (lowercase) → display label
function getDisplayPriority(priority: StockRequestApproval["priority"]): string {
	return priority.charAt(0).toUpperCase() + priority.slice(1);
}

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

const priorityBadgeClass: Record<string, string> = {
	High:   "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	Medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
	Low:    "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<DisplayStatus, string> = {
	"Pending":       "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
	"Approved":      "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	"Rejected":      "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	"Needs Details": "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
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

const MetricCard = ({ title, value, description, icon: Icon, color }: MetricCardProps) => {
	const getColorClasses = () => {
		switch (color) {
			case "success": return "from-green-500 to-emerald-600";
			case "warning": return "from-yellow-500 to-orange-600";
			case "info":    return "from-blue-500 to-indigo-600";
			default:        return "from-gray-500 to-gray-600";
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

export default function StockRequest() {
	const { initialRequests, initialInventoryItems } = usePage().props as any;
	const [requests, setRequests] = useState<StockRequestApproval[]>(initialRequests?.data ?? []);
	const [inventoryItems, setInventoryItems] = useState<InventoryItem[]>(initialInventoryItems?.data ?? []);
	const [isLoading, setIsLoading] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [formData, setFormData] = useState<RequestFormState>(initialFormState);
	const [viewingRequest, setViewingRequest] = useState<StockRequestApproval | null>(null);

	// Derive selected inventory item for stock preview
	const selectedItem = useMemo(
		() => inventoryItems.find((i) => String(i.id) === formData.inventoryItemId) ?? null,
		[inventoryItems, formData.inventoryItemId],
	);

	const refreshData = async () => {
		setIsLoading(true);
		try {
			const [requestsRes, itemsRes] = await Promise.all([
				stockRequestApi.getAll({ per_page: 200 }),
				inventoryItemAPI.getAll({ per_page: 200 }),
			]);
			setRequests(requestsRes.data ?? []);
			setInventoryItems(itemsRes.data ?? []);
		} catch {
			// silently fall back to empty
		} finally {
			setIsLoading(false);
		}
	};

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		if (!query) return requests;
		return requests.filter((r) =>
			r.request_number.toLowerCase().includes(query) ||
			r.product_name.toLowerCase().includes(query) ||
			r.sku_code.toLowerCase().includes(query) ||
			r.status.toLowerCase().includes(query),
		);
	}, [searchQuery, requests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalRequests  = requests.length;
	const pendingRequests  = requests.filter((r) => r.status === "pending").length;
	const approvedRequests = requests.filter((r) => r.status === "accepted").length;

	const handleCreateRequest = async () => {
		if (!formData.inventoryItemId || !formData.quantityNeeded.trim() || !formData.notes.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Missing fields",
				text: "Please select a product, enter the quantity needed, and add a note.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const parsedQty = Number(formData.quantityNeeded);
		if (Number.isNaN(parsedQty) || parsedQty <= 0) {
			await Swal.fire({
				icon: "warning",
				title: "Invalid quantity",
				text: "Quantity needed must be greater than 0.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		try {
			const created = await stockRequestApi.create({
				inventory_item_id: Number(formData.inventoryItemId),
				quantity_needed:   parsedQty,
				priority:          formData.priority,
				requested_size:    formData.requestSize || undefined,
				notes:             formData.notes || undefined,
			});

			setRequests((prev) => [created, ...prev]);
			setFormData(initialFormState);
			setIsCreateModalOpen(false);
			setCurrentPage(1);

			await Swal.fire({
				icon: "success",
				title: "Request submitted",
				text: "Stock request has been sent to Procurement for approval.",
				confirmButtonColor: "#2563eb",
				timer: 1500,
				showConfirmButton: false,
			});
		} catch {
			await Swal.fire({
				icon: "error",
				title: "Submission failed",
				text: "Could not submit the stock request. Please try again.",
				confirmButtonColor: "#2563eb",
			});
		}
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingRequest);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Stock Request - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Stock Request</h1>
						<p className="text-gray-600 dark:text-gray-400">Create and track stock requests to Procurement for out-of-stock items</p>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<button
							onClick={() => setIsCreateModalOpen(true)}
							className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
						>
							+ New Request
						</button>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests"    value={totalRequests}   description="All stock requests created"             icon={ClipboardIcon}  color="info" />
					<MetricCard title="Pending Approval"  value={pendingRequests}  description="Requests currently waiting review"    icon={ClockIcon}      color="warning" />
					<MetricCard title="Approved Requests" value={approvedRequests} description="Requests approved for procurement"    icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Stock Request Table</h2>
						<p className="text-sm text-gray-500">View request status and track updates from Procurement</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by request no, product, SKU, or status..."
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
						{isLoading ? (
							<div className="py-10 text-center text-sm text-gray-500">Loading requests…</div>
						) : (
							<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
								<thead className="bg-gray-50 dark:bg-gray-800/50">
									<tr>
										<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Request no</th>
										<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
										<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
										<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
										<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Requested</th>
										<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
										<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
									{paginatedItems.length > 0 ? (
										paginatedItems.map((request) => {
											const displayStatus   = getDisplayStatus(request.status);
											const displayPriority = getDisplayPriority(request.priority);
											return (
												<tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
													<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{request.request_number}</td>
													<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
														<p className="font-medium text-gray-900 dark:text-white">{request.product_name}</p>
														<p className="text-xs text-gray-500 dark:text-gray-400">{request.sku_code}</p>
													</td>
													<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.quantity_needed}</td>
													<td className="px-4 py-3">
														<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[displayPriority] ?? ""}`}>
															{displayPriority}
														</span>
													</td>
													<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
														{new Date(request.requested_date).toLocaleDateString()}
													</td>
													<td className="px-4 py-3">
														<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[displayStatus]}`}>
															{displayStatus}
														</span>
													</td>
													<td className="px-4 py-3 text-center">
														<button
															onClick={() => setViewingRequest(request)}
															className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
															title="View request details"
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
											<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">No stock requests found.</td>
										</tr>
									)}
								</tbody>
							</table>
						)}
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {filteredData.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} requests
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

			{/* ── Create Request Modal ── */}
			{isCreateModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button
						type="button"
						aria-label="Close create request modal"
						className="absolute inset-0 bg-black/50"
						onClick={() => { setIsCreateModalOpen(false); setFormData(initialFormState); }}
					/>
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Create Stock Request</h2>
							<button onClick={() => { setIsCreateModalOpen(false); setFormData(initialFormState); }} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							{/* Inventory item dropdown */}
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Product (Inventory Item) *
								</label>
								<select
									title="Select inventory item"
									aria-label="Select inventory item"
									value={formData.inventoryItemId}
								onChange={(event) => setFormData((prev) => ({ ...prev, inventoryItemId: event.target.value, requestSize: "" }))}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="">— Select an uploaded product —</option>
								{inventoryItems.map((item) => (
									<option key={item.id} value={String(item.id)}>
										{item.name}{item.sku ? ` (${item.sku})` : ""} — {item.available_quantity ?? 0} {item.unit ?? "pcs"} in stock
								</option>
							))}
						</select>

						{/* Current stock info card */}
						{selectedItem && (
							<div className="mt-2 flex items-center gap-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 px-3 py-2 text-sm">
								<svg className="h-4 w-4 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
									<circle cx="12" cy="12" r="9" />
									<path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4m0 4h.01" />
								</svg>
								<span className="text-blue-700 dark:text-blue-300">
									Current stock: <strong>{selectedItem.available_quantity ?? 0} {selectedItem.unit ?? "pcs"}</strong>
									{selectedItem.sku ? <span className="ml-2 text-blue-500">SKU: {selectedItem.sku}</span> : null}
								</span>
							</div>
						)}

						{/* Size selector — shown only when the item has sizes (e.g. shoes) */}
						{selectedItem && selectedItem.sizes && selectedItem.sizes.length > 0 && (
							<div className="mt-3">
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Specific Size <span className="text-gray-400 font-normal">(optional — leave blank to request all sizes)</span>
								</label>
								<select
									title="Select specific size"
									aria-label="Select specific size"
									value={formData.requestSize}
									onChange={(event) => setFormData((prev) => ({ ...prev, requestSize: event.target.value }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">— All sizes —</option>
									{selectedItem.sizes.map((s) => (
										<option key={s.id} value={s.size}>
											Size {s.size} — {s.quantity} in stock
										</option>
									))}
								</select>
							</div>
						)}
					</div>
						<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quantity Needed *</label>									<input
										type="number"
										min={1}
										value={formData.quantityNeeded}
										onChange={(event) => setFormData((prev) => ({ ...prev, quantityNeeded: event.target.value }))}
										placeholder="e.g., 40"
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority *</label>
									<select
										title="Select request priority"
										aria-label="Select request priority"
										value={formData.priority}
										onChange={(event) => setFormData((prev) => ({ ...prev, priority: event.target.value as "high" | "medium" | "low" }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									>
										<option value="high">High</option>
										<option value="medium">Medium</option>
										<option value="low">Low</option>
									</select>
								</div>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes *</label>
								<textarea
									rows={3}
									value={formData.notes}
									onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
									placeholder="Add request reason, size breakdown, or branch allocation..."
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button
								onClick={() => { setIsCreateModalOpen(false); setFormData(initialFormState); }}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleCreateRequest}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors"
							>
								Submit Request
							</button>
						</div>
					</div>
				</div>
			)}

			{/* ── View Request Modal ── */}
			{viewingRequest && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close request details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingRequest(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Stock Request Details</h2>
							<button onClick={() => setViewingRequest(null)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Request No</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.request_number}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[getDisplayStatus(viewingRequest.status)]}`}>
										{getDisplayStatus(viewingRequest.status)}
									</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.product_name}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">SKU: {viewingRequest.sku_code}</p>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity Needed</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.quantity_needed}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Priority</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[getDisplayPriority(viewingRequest.priority)] ?? ""}`}>
										{getDisplayPriority(viewingRequest.priority)}
									</span>
								</div>
							</div>

						{viewingRequest.requested_size && (
							<div className="rounded-xl bg-indigo-50 dark:bg-indigo-900/20 p-4 border border-indigo-200 dark:border-indigo-800">
								<p className="text-sm font-medium text-indigo-600 dark:text-indigo-400 mb-1">Requested Size</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">Size {viewingRequest.requested_size}</p>
							</div>
						)}

						{viewingRequest.approval_notes && (
								<div className="rounded-xl bg-amber-50 dark:bg-amber-900/20 p-4 border border-amber-200 dark:border-amber-800">
									<p className="text-sm font-medium text-amber-600 dark:text-amber-400 mb-1">Procurement Notes</p>
									<p className="text-base text-gray-900 dark:text-white whitespace-pre-wrap">{viewingRequest.approval_notes}</p>
								</div>
							)}

							{viewingRequest.rejection_reason && (
								<div className="rounded-xl bg-red-50 dark:bg-red-900/20 p-4 border border-red-200 dark:border-red-800">
									<p className="text-sm font-medium text-red-600 dark:text-red-400 mb-1">Rejection Reason</p>
									<p className="text-base text-gray-900 dark:text-white whitespace-pre-wrap">{viewingRequest.rejection_reason}</p>
								</div>
							)}
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
