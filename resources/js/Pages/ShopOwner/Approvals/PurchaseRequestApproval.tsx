import { Head } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayout_shopOwner from "../../../layout/AppLayout_shopOwner";
import type { PurchaseRequest } from "@/services/purchaseRequestApi";

type RequestPriority = "high" | "medium" | "low";
type ApprovalStatus = "pending_finance" | "pending_shop_owner" | "approved" | "rejected";
type MetricColor = "success" | "warning" | "info";

interface PurchaseRequestApprovalItem extends PurchaseRequest {
	// Additional frontend-only fields if needed
}

const priorityBadgeClass: Record<RequestPriority, string> = {
	high: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
	low: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<ApprovalStatus, string> = {
	pending_finance: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
	pending_shop_owner: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
	approved: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	rejected: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
};

const statusDisplayName: Record<ApprovalStatus, string> = {
	pending_finance: "Pending Finance",
	pending_shop_owner: "Pending Shop Owner",
	approved: "Approved",
	rejected: "Rejected",
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

const currency = new Intl.NumberFormat("en-PH", {
	style: "currency",
	currency: "PHP",
	maximumFractionDigits: 2,
});

interface PurchaseRequestApprovalProps {
	onModalStateChange?: (isOpen: boolean) => void;
}

export default function PurchaseRequestApproval({ onModalStateChange }: PurchaseRequestApprovalProps) {
	const [requests, setRequests] = useState<PurchaseRequestApprovalItem[]>([]);
	const [loading, setLoading] = useState(true);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [viewingRequest, setViewingRequest] = useState<PurchaseRequestApprovalItem | null>(null);

	const fetchPurchaseRequests = async () => {
		try {
			setLoading(true);
			const response = await axios.get('/api/shop-owner/purchase-requests', {
				params: { status: 'pending_shop_owner', per_page: 100 },
			});
			const data = response.data?.data || response.data || [];
			setRequests(Array.isArray(data) ? data : []);
		} catch (error) {
			console.error("Error fetching purchase requests:", error);
			setRequests([]);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchPurchaseRequests();
	}, []);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		if (!query) return requests;

		return requests.filter((request) =>
			request.pr_number?.toLowerCase().includes(query) ||
			request.product_name?.toLowerCase().includes(query) ||
			request.supplier?.name?.toLowerCase().includes(query) ||
			request.status?.toLowerCase().includes(query)
		);
	}, [searchQuery, requests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalRequests = requests.length;
	const pendingCount = requests.filter((request) => request.status === "pending_shop_owner").length;
	const approvedCount = requests.filter((request) => request.status === "approved").length;

	const handleApprove = async (request: PurchaseRequestApprovalItem) => {
		setViewingRequest(null);

		if (request.status !== "pending_shop_owner") {
			await Swal.fire({
				icon: "warning",
				title: "Cannot Approve",
				text: "Only requests pending shop owner approval can be approved.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const result = await Swal.fire({
			title: "Approve purchase request?",
			text: `Give final approval for ${request.pr_number}? This will allow Procurement to proceed.`,
			input: "textarea",
			inputLabel: "Shop Owner Notes (optional)",
			inputPlaceholder: "Add approval notes...",
			showCancelButton: true,
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#16a34a",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		try {
			await axios.post(`/api/shop-owner/purchase-requests/${request.id}/approve`, {
				approval_notes: result.value || undefined,
			});

			await fetchPurchaseRequests();

			await Swal.fire({
				icon: "success",
				title: "Approved",
				text: `${request.pr_number} was approved. Procurement can now proceed with purchasing.`,
				timer: 1600,
				showConfirmButton: false,
			});
		} catch (error: any) {
			console.error("Error approving purchase request:", error);
			await Swal.fire({
				icon: "error",
				title: "Error",
				text: error.response?.data?.message || "Failed to approve purchase request.",
				confirmButtonColor: "#2563eb",
			});
		}
	};

	const handleReject = async (request: PurchaseRequestApprovalItem) => {
		setViewingRequest(null);

		if (request.status !== "pending_shop_owner") {
			await Swal.fire({
				icon: "warning",
				title: "Cannot Reject",
				text: "Only requests pending shop owner approval can be rejected.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const result = await Swal.fire({
			title: "Reject purchase request?",
			text: `Please provide reason for rejecting ${request.pr_number}.`,
			icon: "warning",
			input: "textarea",
			inputLabel: "Rejection reason",
			inputPlaceholder: "Type reason...",
			showCancelButton: true,
			confirmButtonText: "Reject",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#dc2626",
			cancelButtonColor: "#6b7280",
			inputValidator: (value) => (!value || !value.trim() ? "Rejection reason is required." : undefined),
		});

		if (!result.isConfirmed || !result.value) return;

		try {
			await axios.post(`/api/shop-owner/purchase-requests/${request.id}/reject`, {
				rejection_reason: String(result.value),
			});

			await fetchPurchaseRequests();

			await Swal.fire({
				icon: "success",
				title: "Rejected",
				text: `${request.pr_number} was rejected and returned to Procurement Team.`,
				timer: 1600,
				showConfirmButton: false,
			});
		} catch (error: any) {
			console.error("Error rejecting purchase request:", error);
			await Swal.fire({
				icon: "error",
				title: "Error",
				text: error.response?.data?.message || "Failed to reject purchase request.",
				confirmButtonColor: "#2563eb",
			});
		}
	};

	const isAnyModalOpen = Boolean(viewingRequest);

	useEffect(() => {
		onModalStateChange?.(isAnyModalOpen);
		return () => {
			onModalStateChange?.(false);
		};
	}, [isAnyModalOpen, onModalStateChange]);

	return (
		<AppLayout_shopOwner>
			<Head title="Purchase Request Approval - Solespace ERP" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Purchase Request Approval</h1>
						<p className="text-gray-600 dark:text-gray-400">Review procurement purchase requests before final purchasing action</p>
					</div>
					<span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 w-fit">Shop Owner Review</span>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests" value={totalRequests} description="All PR submissions" icon={ClipboardIcon} color="info" />
					<MetricCard title="Pending Review" value={pendingCount} description="Awaiting shop owner action" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved Requests" value={approvedCount} description="Approved and returned to procurement" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Purchase Request Table</h2>
						<p className="text-sm text-gray-500">Check supplier, quantity, total cost, and justification before approval</p>
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
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Total Cost</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{loading ? (
									<tr>
										<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">Loading...</td>
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
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white whitespace-nowrap">{currency.format(request.total_cost)}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[request.priority]}`}>
													{request.priority.charAt(0).toUpperCase() + request.priority.slice(1)}
												</span>
											</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
													{statusDisplayName[request.status]}
												</span>
											</td>
											<td className="px-4 py-3 text-center">
												<div className="flex items-center justify-center gap-2">
													<button
														onClick={() => setViewingRequest(request)}
														className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
														title="View details"
													>
														<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
															<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
															<circle cx="12" cy="12" r="3" />
														</svg>
													</button>
												</div>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">No purchase requests found.</td>
									</tr>
								)}
							</tbody>
						</table>
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

			{viewingRequest && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close request details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingRequest(null)} />
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
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewingRequest.status]}`}>
										{statusDisplayName[viewingRequest.status]}
									</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product / Supplier</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.product_name}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">{viewingRequest.supplier?.name || 'N/A'}</p>
							</div>

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

							{viewingRequest.notes && (
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Review Notes</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white whitespace-pre-wrap">{viewingRequest.notes}</p>
								</div>
							)}
						</div>

						<div className="flex flex-wrap gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => handleApprove(viewingRequest)} className="flex-1 min-w-35 px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors">Approve</button>
							<button onClick={() => handleReject(viewingRequest)} className="flex-1 min-w-35 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-colors">Reject</button>
							<button onClick={() => setViewingRequest(null)} className="flex-1 min-w-35 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Close</button>
						</div>
					</div>
				</div>
			)}
		</AppLayout_shopOwner>
	);
}

