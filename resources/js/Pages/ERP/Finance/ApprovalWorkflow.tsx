import React from "react";
import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type ApprovalStatus = "pending" | "approved" | "rejected" | "cancelled";
type ApprovalType = "expense" | "journal_entry" | "invoice" | "budget";

type ApprovalRequest = {
	id: string;
	type: ApprovalType;
	reference: string;
	description: string;
	amount: number;
	requested_by: string;
	requested_at: string;
	status: ApprovalStatus;
	current_level: number;
	total_levels: number;
	reviewed_by?: string;
	reviewed_at?: string;
	comments?: string;
	metadata?: any;
};

type ApprovalHistory = {
	id: string;
	approval_id: string;
	level: number;
	reviewer: string;
	action: "approved" | "rejected";
	comments: string;
	reviewed_at: string;
};

// Icons
const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const XCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const CheckIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
	</svg>
);

const XIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
	</svg>
);

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
	</svg>
);

const FilterIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
	</svg>
);

const DocumentIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
	</svg>
);

const ApprovalWorkflow: React.FC = () => {
	const api = useFinanceApi();

	// React Query hooks - automatically handle loading, caching, refetching
	const { data: approvalData = [], isLoading, refetch: refetchApprovals } = usePendingApprovals();
	const approveTransactionMutation = useApproveTransaction();
	
	// Ensure approvalRequests is always an array
	const approvalRequests = Array.isArray(approvalData) ? approvalData : [];
	
	const [selectedRequest, setSelectedRequest] = useState<ApprovalRequest | null>(null);
	const [approvalHistory, setApprovalHistory] = useState<ApprovalHistory[]>([]);
	const [activeTab, setActiveTab] = useState<"pending" | "history">("pending");
	const [filterType, setFilterType] = useState<ApprovalType | "all">("all");
	const [searchTerm, setSearchTerm] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const itemsPerPage = 10;

	// Refetch when tab changes
	useEffect(() => {
		refetchApprovals();
	}, [activeTab, refetchApprovals]);

	// Reset to page 1 when filters change
	useEffect(() => {
		setCurrentPage(1);
	}, [searchTerm, filterType, activeTab]);

	const loadApprovalHistory = async (approvalId: string) => {
		try {
			const response = await api.get(`/api/finance/approvals/${approvalId}/history`);
			
			if (response.ok) {
				setApprovalHistory(response.data?.history || []);
			}
		} catch (error) {
			// Silent fail - will show empty state
		}
	};

	const handleApprove = async (request: ApprovalRequest) => {
		const { value: comments } = await Swal.fire({
			title: "Approve Request",
			html: `
				<p class="text-sm text-gray-600 mb-4">You are about to approve:</p>
				<p class="font-semibold">${request.reference}</p>
				<p class="text-sm text-gray-600 mb-4">${request.description}</p>
			`,
			input: "textarea",
			inputLabel: "Comments (optional)",
			inputPlaceholder: "Add any comments about this approval...",
			showCancelButton: true,
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#10b981",
			customClass: {
				input: "text-sm"
			}
		});

		if (comments !== undefined) {
			try {
				const response = await api.post(`/api/finance/approvals/${request.id}/approve`, { comments });

				if (response.ok) {
					await Swal.fire({
						title: "Approved!",
						text: "The request has been approved successfully.",
						icon: "success",
						confirmButtonColor: "#2563eb"
					});
					// React Query will automatically refetch
					refetchApprovals();
					setSelectedRequest(null);
				} else {
					throw new Error(response.error || "Failed to approve request");
				}
			} catch (error) {
				await Swal.fire({
					title: "Error",
					text: error instanceof Error ? error.message : "Failed to approve the request. Please try again.",
					icon: "error",
					confirmButtonColor: "#d33"
				});
			}
		}
	};

	const handleReject = async (request: ApprovalRequest) => {
		const { value: comments } = await Swal.fire({
			title: "Reject Request",
			html: `
				<p class="text-sm text-gray-600 mb-4">You are about to reject:</p>
				<p class="font-semibold">${request.reference}</p>
				<p class="text-sm text-gray-600 mb-4">${request.description}</p>
			`,
			input: "textarea",
			inputLabel: "Reason for rejection (required)",
			inputPlaceholder: "Please provide a reason for rejection...",
			inputValidator: (value) => {
				if (!value) {
					return "You must provide a reason for rejection";
				}
			},
			showCancelButton: true,
			confirmButtonText: "Reject",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#ef4444",
			customClass: {
				input: "text-sm"
			}
		});

		if (comments) {
			try {
				const response = await api.post(`/api/finance/approvals/${request.id}/reject`, { comments });

				if (response.ok) {
					await Swal.fire({
						title: "Rejected",
						text: "The request has been rejected.",
						icon: "info",
						confirmButtonColor: "#2563eb"
					});
					// React Query will automatically refetch
					refetchApprovals();
					setSelectedRequest(null);
				} else {
					throw new Error(response.error || "Failed to reject request");
				}
			} catch (error) {
				await Swal.fire({
					title: "Error",
					text: error instanceof Error ? error.message : "Failed to reject the request. Please try again.",
					icon: "error",
					confirmButtonColor: "#d33"
				});
			}
		}
	};

	const handleViewDetails = async (request: ApprovalRequest) => {
		setSelectedRequest(request);
		await loadApprovalHistory(request.id);
	};

	const filteredRequests = useMemo(() => {
		return approvalRequests.filter((request: ApprovalRequest) => {
			const matchesType = filterType === "all" || request.type === filterType;
			const matchesSearch = !searchTerm || 
				request.reference.toLowerCase().includes(searchTerm.toLowerCase()) ||
				request.description.toLowerCase().includes(searchTerm.toLowerCase()) ||
				request.requested_by.toLowerCase().includes(searchTerm.toLowerCase());
			return matchesType && matchesSearch;
		});
	}, [approvalRequests, filterType, searchTerm]);

	// Pagination logic
	const totalPages = Math.ceil(filteredRequests.length / itemsPerPage);
	const startIndex = (currentPage - 1) * itemsPerPage;
	const endIndex = startIndex + itemsPerPage;
	const paginatedRequests = filteredRequests.slice(startIndex, endIndex);

	const stats = useMemo(() => {
		const pending = approvalRequests.filter((r: ApprovalRequest) => r.status === "pending").length;
		const approved = approvalRequests.filter((r: ApprovalRequest) => r.status === "approved").length;
		const rejected = approvalRequests.filter((r: ApprovalRequest) => r.status === "rejected").length;
		return { pending, approved, rejected };
	}, [approvalRequests]);

	const formatCurrency = (value: number) =>
		new Intl.NumberFormat("en-PH", {
			style: "currency",
			currency: "PHP",
			minimumFractionDigits: 2,
		}).format(value);

	const formatDate = (date: string) => {
		return new Date(date).toLocaleDateString("en-US", {
			year: "numeric",
			month: "short",
			day: "numeric",
			hour: "2-digit",
			minute: "2-digit"
		});
	};

	const getStatusBadge = (status: ApprovalStatus) => {
		const badges = {
			pending: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
			approved: "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
			rejected: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
			cancelled: "bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400"
		};
		return badges[status];
	};

	const getTypeBadge = (type: ApprovalType) => {
		const badges = {
			expense: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
			journal_entry: "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400",
			invoice: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400",
			budget: "bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400"
		};
		return badges[type];
	};

	return (
		<AppLayoutERP>
			<Head title="Approval Workflow - Solespace ERP" />
			<div className="space-y-6">
				{/* Header */}
				<div className="flex justify-between items-start">
					<div>
						<h1 className="text-3xl font-bold text-gray-900 dark:text-white">Approval Workflow</h1>
						<p className="text-gray-600 dark:text-gray-400 mt-2">
							Review and approve pending financial requests
						</p>
					</div>
				</div>

				{/* Statistics */}
				{activeTab === "pending" && (
				<div className="grid grid-cols-1 md:grid-cols-3 gap-4">
					<div className="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl shadow-sm border border-yellow-200 dark:border-yellow-800 p-4">
						<div className="flex items-center gap-3">
							<ClockIcon className="size-8 text-yellow-600 dark:text-yellow-400" />
							<div>
								<p className="text-sm text-yellow-700 dark:text-yellow-300">Pending Approval</p>
								<p className="text-2xl font-bold text-yellow-800 dark:text-yellow-200">{stats.pending}</p>
							</div>
						</div>
					</div>
					
					<div className="bg-green-50 dark:bg-green-900/20 rounded-xl shadow-sm border border-green-200 dark:border-green-800 p-4">
						<div className="flex items-center gap-3">
							<CheckCircleIcon className="size-8 text-green-600 dark:text-green-400" />
							<div>
								<p className="text-sm text-green-700 dark:text-green-300">Approved Today</p>
								<p className="text-2xl font-bold text-green-800 dark:text-green-200">{stats.approved}</p>
							</div>
						</div>
					</div>
					
					<div className="bg-red-50 dark:bg-red-900/20 rounded-xl shadow-sm border border-red-200 dark:border-red-800 p-4">
						<div className="flex items-center gap-3">
							<XCircleIcon className="size-8 text-red-600 dark:text-red-400" />
							<div>
								<p className="text-sm text-red-700 dark:text-red-300">Rejected Today</p>
								<p className="text-2xl font-bold text-red-800 dark:text-red-200">{stats.rejected}</p>
							</div>
						</div>
					</div>
				</div>
			)}

			{/* Tabs and Filters */}
			<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					{/* Tabs */}
					<div className="flex gap-2">
						<button
							onClick={() => setActiveTab("pending")}
							className={`px-4 py-2 rounded-lg font-medium transition-colors ${
								activeTab === "pending"
									? "bg-blue-600 text-white"
									: "bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600"
							}`}
						>
							Pending Requests
						</button>
						<button
							onClick={() => setActiveTab("history")}
							className={`px-4 py-2 rounded-lg font-medium transition-colors ${
								activeTab === "history"
									? "bg-blue-600 text-white"
									: "bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600"
							}`}
						>
							History
						</button>
					</div>

					{/* Filters */}
					<div className="flex flex-col sm:flex-row gap-3">
						<div className="relative">
							<input
								type="text"
								placeholder="Search requests..."
								value={searchTerm}
								onChange={(e) => setSearchTerm(e.target.value)}
								className="pl-4 pr-10 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm w-full sm:w-64"
							/>
						</div>

						<div className="flex items-center gap-2">
							<FilterIcon className="size-5 text-gray-500" />
							<select
								value={filterType}
								onChange={(e) => setFilterType(e.target.value as ApprovalType | "all")}
								className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm"
							>
								<option value="all">All Types</option>
								<option value="expense">Expense</option>
								<option value="journal_entry">Journal Entry</option>
								<option value="invoice">Invoice</option>
								<option value="budget">Budget</option>
							</select>
						</div>
					</div>
				</div>
			</div>

			{/* Loading State */}
			{isLoading && (
				<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
					<div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
					<p className="text-gray-600 dark:text-gray-400 mt-4">Loading approval requests...</p>
				</div>
			)}

			{/* Approval Requests List */}
			{!isLoading && filteredRequests.length > 0 && (
				<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
					<div className="overflow-x-auto">
						<table className="w-full">
							<thead className="bg-gray-50 dark:bg-gray-900/40">
								<tr>
									<th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Type</th>
									<th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Reference</th>
									<th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Description</th>
									<th className="px-6 py-4 text-right text-sm font-semibold text-gray-700 dark:text-gray-300">Amount</th>
									<th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Requested By</th>
									<th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Date</th>
									<th className="px-6 py-4 text-center text-sm font-semibold text-gray-700 dark:text-gray-300">Status</th>
									<th className="px-6 py-4 text-center text-sm font-semibold text-gray-700 dark:text-gray-300">Level</th>
									<th className="px-6 py-4 text-center text-sm font-semibold text-gray-700 dark:text-gray-300">Actions</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
							{paginatedRequests.map((request: ApprovalRequest) => (
									<tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-900/40">
										<td className="px-6 py-4">
											<span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${getTypeBadge(request.type)}`}>
												{request.type.replace("_", " ")}
											</span>
										</td>
										<td className="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">
											{request.reference}
										</td>
										<td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400 max-w-xs truncate">
											{request.description}
										</td>
										<td className="px-6 py-4 text-sm text-right font-medium text-gray-900 dark:text-white">
											{formatCurrency(request.amount)}
										</td>
										<td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
											{request.requested_by}
										</td>
										<td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">
											{formatDate(request.requested_at)}
										</td>
										<td className="px-6 py-4 text-center">
											<span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${getStatusBadge(request.status)}`}>
												{request.status}
											</span>
										</td>
										<td className="px-6 py-4 text-center text-sm text-gray-600 dark:text-gray-400">
											{request.current_level} / {request.total_levels}
										</td>
										<td className="px-6 py-4">
											<div className="flex items-center justify-center gap-2">
												<button
													onClick={() => handleViewDetails(request)}
													className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
													title="View Details"
												>
													<EyeIcon className="size-5" />
												</button>
											</div>
										</td>
									</tr>
								))}
							</tbody>
						</table>

						{/* Pagination */}
						{filteredRequests.length > 0 && (
							<div className="flex items-center justify-between px-6 py-4 border-t border-gray-200 dark:border-gray-800">
								<div className="text-sm text-gray-600 dark:text-gray-400">
									Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
									<span className="font-medium">{Math.min(endIndex, filteredRequests.length)}</span> of{" "}
									<span className="font-medium">{filteredRequests.length}</span>
								</div>
								<div className="flex items-center gap-2">
									<button
										onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
										disabled={currentPage === 1}
										className="px-3 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600"
									>
										Previous
									</button>
									<div className="flex items-center gap-1">
										{Array.from({ length: totalPages }, (_, i) => i + 1)
											.filter((page) => {
												if (totalPages <= 7) return true;
												if (page === 1 || page === totalPages) return true;
												if (page >= currentPage - 1 && page <= currentPage + 1) return true;
												return false;
											})
											.map((page, idx, arr) => (
												<React.Fragment key={page}>
													{idx > 0 && arr[idx - 1] !== page - 1 && (
														<span className="px-2 text-gray-500">...</span>
													)}
													<button
														onClick={() => setCurrentPage(page)}
														className={`min-w-[40px] px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
															currentPage === page
																? "bg-blue-600 text-white"
																: "bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600"
														}`}
													>
														{page}
													</button>
												</React.Fragment>
											))}
									</div>
									<button
										onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
										disabled={currentPage === totalPages}
										className="px-3 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600"
									>
										Next
									</button>
								</div>
							</div>
						)}
					</div>
				</div>
			)}

			{/* Empty State */}
			{!isLoading && filteredRequests.length === 0 && (
				<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
					<DocumentIcon className="size-16 mx-auto text-gray-400 dark:text-gray-600 mb-4" />
					<h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-2">
						No approval requests found
					</h3>
					<p className="text-gray-600 dark:text-gray-400">
						{activeTab === "pending" 
							? "There are no pending approval requests at this time."
							: "No approval history available yet."}
					</p>
				</div>
			)}

			{/* Detail Modal */}
			{selectedRequest && (
				<div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
					<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
						<div className="p-6 border-b border-gray-200 dark:border-gray-700">
							<div className="flex justify-between items-start">
								<div>
									<h2 className="text-2xl font-bold text-gray-900 dark:text-white">Request Details</h2>
									<p className="text-gray-600 dark:text-gray-400 mt-1">{selectedRequest.reference}</p>
								</div>
								<button
									onClick={() => setSelectedRequest(null)}
									className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
								>
									<XIcon className="size-6" />
								</button>
							</div>
						</div>

						<div className="p-6 space-y-6">
							{/* Basic Info */}
							<div className="grid grid-cols-2 gap-4">
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Type</p>
									<span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full mt-1 ${getTypeBadge(selectedRequest.type)}`}>
										{selectedRequest.type.replace("_", " ")}
									</span>
								</div>
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Status</p>
									<span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full mt-1 ${getStatusBadge(selectedRequest.status)}`}>
										{selectedRequest.status}
									</span>
								</div>
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Amount</p>
									<p className="text-lg font-semibold text-gray-900 dark:text-white mt-1">
										{formatCurrency(selectedRequest.amount)}
									</p>
								</div>
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Approval Level</p>
									<p className="text-lg font-semibold text-gray-900 dark:text-white mt-1">
										{selectedRequest.current_level} / {selectedRequest.total_levels}
									</p>
								</div>
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Requested By</p>
									<p className="text-gray-900 dark:text-white mt-1">{selectedRequest.requested_by}</p>
								</div>
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Requested At</p>
									<p className="text-gray-900 dark:text-white mt-1">{formatDate(selectedRequest.requested_at)}</p>
								</div>
							</div>

							{/* Description */}
							<div>
								<p className="text-sm text-gray-600 dark:text-gray-400 mb-2">Description</p>
								<p className="text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-900/40 p-4 rounded-lg">
									{selectedRequest.description}
								</p>
							</div>

							{/* Approval History */}
							{approvalHistory.length > 0 && (
								<div>
									<h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-4">Approval History</h3>
									<div className="space-y-3">
										{approvalHistory.map((history) => (
											<div key={history.id} className="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
												<div className="flex items-start justify-between mb-2">
													<div className="flex items-center gap-2">
														{history.action === "approved" ? (
															<CheckCircleIcon className="size-5 text-green-600" />
														) : (
															<XCircleIcon className="size-5 text-red-600" />
														)}
														<span className={`font-medium ${
															history.action === "approved" ? "text-green-600" : "text-red-600"
														}`}>
															{history.action === "approved" ? "Approved" : "Rejected"}
														</span>
													</div>
													<span className="text-xs text-gray-500">{formatDate(history.reviewed_at)}</span>
												</div>
												<p className="text-sm text-gray-600 dark:text-gray-400 mb-1">
													By: <span className="font-medium">{history.reviewer}</span> (Level {history.level})
												</p>
												{history.comments && (
													<p className="text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 p-2 rounded mt-2">
														{history.comments}
													</p>
												)}
											</div>
										))}
									</div>
								</div>
							)}

							{/* Actions */}
							{selectedRequest.status === "pending" && (
								<div className="flex gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
									<button
										onClick={() => handleApprove(selectedRequest)}
										className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center justify-center gap-2"
									>
										<CheckIcon className="size-5" />
										Approve
									</button>
									<button
										onClick={() => handleReject(selectedRequest)}
										className="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center justify-center gap-2"
									>
										<XIcon className="size-5" />
										Reject
									</button>
								</div>
							)}
						</div>
					</div>
				</div>
			)}
			</div>
		</AppLayoutERP>
	);
};

const ApprovalWorkflowRemoved: React.FC = () => {
	return (
		<AppLayoutERP>
			<Head title="Approval Workflow" />
			<div className="p-8">
				<h1 className="text-2xl font-semibold">Approval Workflow removed</h1>
				<p className="mt-2 text-gray-600">This page has been removed from the codebase. If you need it restored, add the page back under resources/js/Pages/ERP/Finance.</p>
			</div>
		</AppLayoutERP>
	);
};

export default ApprovalWorkflowRemoved;
