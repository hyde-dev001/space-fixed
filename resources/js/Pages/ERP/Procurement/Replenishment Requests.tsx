import { Head } from "@inertiajs/react";
import { useMemo, useState, useEffect } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { replenishmentRequestApi } from "@/services/replenishmentRequestApi";
import type { ReplenishmentRequest } from "@/types/procurement";

type RequestPriority = "high" | "medium" | "low";
type RequestStatus = "pending" | "accepted" | "rejected" | "needs_details";

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
		pending: "Pending",
		accepted: "Accepted",
		rejected: "Rejected",
		needs_details: "Needs Details",
	};
	return map[status] || status;
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

const priorityBadgeClass: Record<string, string> = {
	high: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
	low: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<string, string> = {
	pending: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
	accepted: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	rejected: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	needs_details: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
};

export default function ReplenishmentRequests() {
	const [requests, setRequests] = useState<ReplenishmentRequest[]>([]);
	const [loading, setLoading] = useState(true);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [viewingRequest, setViewingRequest] = useState<ReplenishmentRequest | null>(null);

	const fetchRequests = async () => {
		try {
			setLoading(true);
			const response = await replenishmentRequestApi.getAll();
			setRequests(response.data);
		} catch (error) {
			console.error("Failed to fetch replenishment requests:", error);
			Swal.fire("Error", "Failed to load requests", "error");
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchRequests();
	}, []);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();

		if (!query) return requests;

		return requests.filter((request) =>
			request.request_number.toLowerCase().includes(query) ||
			request.product_name.toLowerCase().includes(query) ||
			request.sku_code.toLowerCase().includes(query) ||
			(request.requester?.name || "").toLowerCase().includes(query) ||
			request.status.toLowerCase().includes(query)
		);
	}, [searchQuery, requests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const handleAccept = async (request: ReplenishmentRequest) => {
		if (request.status === "accepted") return;

		const result = await Swal.fire({
			title: "Accept request?",
			text: `Proceed with supplier sourcing for ${request.product_name}?`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Yes, accept",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#2563eb",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		try {
			await replenishmentRequestApi.accept(request.id);
			await Swal.fire({
				title: "Accepted",
				text: "Request has been accepted for supplier sourcing.",
				icon: "success",
				timer: 1500,
				showConfirmButton: false,
			});
			fetchRequests();
			if (viewingRequest && viewingRequest.id === request.id) {
				setViewingRequest(null);
			}
		} catch (error) {
			console.error("Failed to accept request:", error);
			Swal.fire("Error", "Failed to accept request", "error");
		}
	};

	const handleReject = async (request: ReplenishmentRequest) => {
		if (request.status === "rejected") return;

		const { value: notes } = await Swal.fire({
			title: "Reject request?",
			input: "textarea",
			inputLabel: "Rejection Reason",
			inputPlaceholder: "Enter reason for rejection...",
			text: `Reject replenishment request ${request.request_number}?`,
			icon: "warning",
			showCancelButton: true,
			confirmButtonText: "Yes, reject",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#dc2626",
			cancelButtonColor: "#6b7280",
			inputValidator: (value) => {
				if (!value) return "Please provide a reason";
				return null;
			}
		});

		if (!notes) return;

		try {
			await replenishmentRequestApi.reject(request.id, { rejection_notes: notes });
			await Swal.fire({
				title: "Rejected",
				text: "Request has been rejected.",
				icon: "success",
				timer: 1500,
				showConfirmButton: false,
			});
			fetchRequests();
			if (viewingRequest && viewingRequest.id === request.id) {
				setViewingRequest(null);
			}
		} catch (error) {
			console.error("Failed to reject request:", error);
			Swal.fire("Error", "Failed to reject request", "error");
		}
	};

	const handleAskDetails = async (request: ReplenishmentRequest) => {
		const result = await Swal.fire({
			title: "Request more details",
			input: "textarea",
			inputLabel: "Message to Inventory",
			inputPlaceholder: "Type the details you need...",
			inputValue: request.notes || "",
			showCancelButton: true,
			confirmButtonText: "Send request",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#2563eb",
			cancelButtonColor: "#6b7280",
			inputValidator: (value) => {
				if (!value || !value.trim()) {
					return "Please provide a message before sending.";
				}
				return undefined;
			},
		});

		if (!result.isConfirmed || !result.value) return;

		try {
			await replenishmentRequestApi.requestDetails(request.id, { notes: String(result.value) });
			await Swal.fire({
				title: "Sent",
				text: "Request for details has been sent to Inventory.",
				icon: "success",
				timer: 1500,
				showConfirmButton: false,
			});
			fetchRequests();
			if (viewingRequest && viewingRequest.id === request.id) {
				setViewingRequest(null);
			}
		} catch (error) {
			console.error("Failed to request details:", error);
			Swal.fire("Error", "Failed to send request", "error");
		}
	};

	const isAnyModalOpen = Boolean(viewingRequest);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Replenishment Requests - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Replenishment Requests</h1>
						<p className="text-gray-600 dark:text-gray-400">Review out-of-stock requests from Inventory and decide next action for sourcing</p>
					</div>
					<span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 w-fit">Inventory to Procurement</span>
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Requests Table</h2>
						<p className="text-sm text-gray-500">Track pending replenishment requests, urgency, and procurement actions</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by request no, product, SKU, requester, or status..."
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
							<p className="mt-2 text-gray-600 dark:text-gray-400">Loading requests...</p>
						</div>
					) : paginatedItems.length === 0 ? (
						<div className="text-center py-8 text-gray-500 dark:text-gray-400">
							{searchQuery ? "No requests found matching your search." : "No replenishment requests yet."}
						</div>
					) : (
						<>
							<div className="overflow-x-auto">
								<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
									<thead className="bg-gray-50 dark:bg-gray-800/50">
										<tr>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Request no</th>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Requested by</th>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Date</th>
											<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
											<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
										</tr>
									</thead>
									<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
										{paginatedItems.map((request) => (
											<tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
												<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{request.request_number}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
													<p className="font-medium text-gray-900 dark:text-white">{request.product_name}</p>
													<p className="text-xs text-gray-500 dark:text-gray-400">{request.sku_code}</p>
												</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.quantity_needed}</td>
												<td className="px-4 py-3">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[request.priority]}`}>
														{formatPriority(request.priority)}
													</span>
												</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.requester?.name || "—"}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{request.requested_date}</td>
												<td className="px-4 py-3">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
														{formatStatus(request.status)}
													</span>
												</td>
												<td className="px-4 py-3 text-center">
													<div className="flex items-center justify-center gap-2">
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
												</div>
											</td>
										</tr>
									))}
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
				</>
				)}
			</div>
		</div>

		{viewingRequest && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close request details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingRequest(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Replenishment Request Details</h2>
							<button
								onClick={() => setViewingRequest(null)}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
							>
								×
							</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Request No</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.request_number}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewingRequest.status]}`}>
										{formatStatus(viewingRequest.status)}
									</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.product_name}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">SKU: {viewingRequest.sku_code}</p>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity Needed</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.quantity_needed}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Priority</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[viewingRequest.priority]}`}>
										{formatPriority(viewingRequest.priority)}
									</span>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Requested Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.requested_date}</p>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Requested By</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.requester?.name || "—"}</p>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white whitespace-pre-wrap">{viewingRequest.notes || "—"}</p>
							</div>
						</div>

						<div className="flex flex-wrap gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button
								onClick={() => handleAccept(viewingRequest)}
								className="flex-1 min-w-35 px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors"
							>
								Accept
							</button>
							<button
								onClick={() => handleAskDetails(viewingRequest)}
								className="flex-1 min-w-35 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-medium transition-colors"
							>
								Ask Details
							</button>
							<button
								onClick={() => handleReject(viewingRequest)}
								className="flex-1 min-w-35 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-colors"
							>
								Reject
							</button>
							<button
								onClick={() => setViewingRequest(null)}
								className="flex-1 min-w-35 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Close
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
