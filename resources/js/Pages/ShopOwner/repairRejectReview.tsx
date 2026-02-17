import { Head, usePage, Link } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";

// Icons
const CheckIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
	</svg>
);

const XIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
	</svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
	</svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const ArrowUpIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
	</svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
	</svg>
);

const WrenchIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="currentColor" viewBox="0 0 24 24">
		<path d="M21 6.5a4.5 4.5 0 01-6.36 4.09l-6.8 6.8a2 2 0 11-2.83-2.83l6.8-6.8A4.5 4.5 0 1116.5 3a4.49 4.49 0 014.5 3.5z" />
	</svg>
);

const HistoryIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

interface RepairRejection {
	id: number;
	request_number: string;
	total: number;
	status: string;
	is_high_value: boolean;
	requires_owner_approval: boolean;
	owner_decision?: string;
	owner_approval_notes?: string;
	owner_reviewed_at?: string;
	created_at: string;
	user?: {
		id: number;
		first_name: string;
		last_name: string;
		email: string;
		phone_number: string;
	};
	services?: Array<{
		id: number;
		name: string;
		base_price: number;
	}>;
	repairer?: {
		id: number;
		first_name: string;
		last_name: string;
	};
	conversation?: any;
	owner_reviewed_by?: {
		id: number;
		first_name: string;
		last_name: string;
	};
}

interface RejectionHistoryEvent {
	id: number;
	event: "submitted" | "reviewed" | "approved" | "rejected" | "commented";
	description: string;
	changedBy: string;
	changedAt: string;
	status?: string;
	notes?: string;
}

type MetricColor = "success" | "warning" | "info";
type ChangeType = "increase" | "decrease";

interface MetricCardProps {
	title: string;
	value: number | string;
	change: number;
	changeType: ChangeType;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
	description: string;
}

const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }: MetricCardProps) => {
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
					<div
						className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
							changeType === "increase"
								? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
								: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
						}`}
					>
						{changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
						{Math.abs(change)}%
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

export default function RepairRejectReview() {
	const { auth } = usePage().props as any;
	
	// Get user role - handle both regular users and shop owners
	const userRole = auth?.user?.role || auth?.shop_owner?.role;
	const isShopOwner = !!auth?.shop_owner;

	const [rejections, setRejections] = useState<RepairRejection[]>([]);
	const [loading, setLoading] = useState(true);
	const [currentPage, setCurrentPage] = useState(1);
	const [viewModalOpen, setViewModalOpen] = useState(false);
	const [selectedRejection, setSelectedRejection] = useState<RepairRejection | null>(null);
	const [activeImage, setActiveImage] = useState<string | null>(null);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState("owner_approval_pending");
	const [rejectionPriceModalOpen, setRejectionPriceModalOpen] = useState(false);
	const [rejectionPriceThreshold, setRejectionPriceThreshold] = useState("");

	const fetchRepairs = async () => {
		try {
			setLoading(true);
			const response = await axios.get('/api/shop-owner/repairs/high-value-pending');
			if (response.data.success) {
				setRejections(response.data.repairs);
			}
		} catch (error: any) {
			console.error('Failed to fetch repairs:', error);
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: error.response?.data?.message || 'Failed to load repair requests',
				confirmButtonColor: '#2563eb',
			});
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		// Allow access if user is logged in as shop owner
		const isAuthenticated = auth?.shop_owner;
		
		if (!isAuthenticated) {
			Swal.fire({
				icon: "error",
				title: "Access Denied",
				text: "You must be logged in as a shop owner to access this page.",
				confirmButtonColor: "#000000",
			}).then(() => {
				window.history.back();
			});
		} else {
			fetchRepairs();
		}
	}, [auth]);

	const filteredData = useMemo(() => {
		return rejections.filter((item) => {
			const customerName = `${item.user?.first_name || ''} ${item.user?.last_name || ''}`;
			const repairerName = `${item.repairer?.first_name || ''} ${item.repairer?.last_name || ''}`;
			const serviceName = item.services?.map(s => s.name).join(', ') || '';
			
			const matchesSearch =
				item.request_number.toLowerCase().includes(searchQuery.toLowerCase()) ||
				serviceName.toLowerCase().includes(searchQuery.toLowerCase()) ||
				customerName.toLowerCase().includes(searchQuery.toLowerCase()) ||
				repairerName.toLowerCase().includes(searchQuery.toLowerCase());
			const matchesStatus = statusFilter === "All" || item.status === statusFilter;
			return matchesSearch && matchesStatus;
		});
	}, [rejections, searchQuery, statusFilter]);

	const itemsPerPage = 5;
	const totalPages = Math.ceil(filteredData.length / itemsPerPage) || 1;
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedRejections = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const pendingCount = rejections.filter((r) => r.status === "owner_approval_pending").length;
	const approvedCount = rejections.filter((r) => r.status === "owner_approved").length;
	const rejectedCount = rejections.filter((r) => r.status === "owner_rejected").length;

	const handleViewClick = (rejection: RepairRejection) => {
		setSelectedRejection(rejection);
		setViewModalOpen(true);
	};

	const handleCloseModal = () => {
		setViewModalOpen(false);
		setActiveImage(null);
	};

	const handleApproveRejection = async (rejection: RepairRejection) => {
		const customerName = `${rejection.user?.first_name || ''} ${rejection.user?.last_name || ''}`;
		const serviceName = rejection.services?.map(s => s.name).join(', ') || 'N/A';
		
		const { value: notes } = await Swal.fire({
			title: "Approve High-Value Repair?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Request:</strong> ${rejection.request_number}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Service:</strong> ${serviceName}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Customer:</strong> ${customerName}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Total:</strong> ₱${rejection.total.toFixed(2)}</p>
					<p style="margin-bottom: 1rem;"><strong>Repairer:</strong> ${rejection.repairer?.first_name || ''} ${rejection.repairer?.last_name || ''}</p>
					<textarea id="approval-notes" class="swal2-textarea" placeholder="Optional approval notes" style="width: 100%; min-height: 80px;"></textarea>
				</div>
			`,
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#10b981",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
			preConfirm: () => {
				const textarea = document.getElementById('approval-notes') as HTMLTextAreaElement;
				return textarea?.value || '';
			}
		});

		if (notes !== undefined) {
			try {
				const response = await axios.post(`/api/shop-owner/repairs/${rejection.id}/approve-high-value`, { notes });
				
				if (response.data.success) {
					await Swal.fire({
						title: "Approved!",
						text: "The high-value repair has been approved. Repairer can now start work.",
						icon: "success",
						confirmButtonColor: "#2563eb",
					});
					fetchRepairs();
					handleCloseModal();
				}
			} catch (error: any) {
				Swal.fire({
					icon: 'error',
					title: 'Approval Failed',
					text: error.response?.data?.message || 'Failed to approve repair',
					confirmButtonColor: '#2563eb',
				});
			}
		}
	};

	const handleRejectRejection = async (rejection: RepairRejection) => {
		const { value: notes } = await Swal.fire({
			title: "Reject High-Value Repair?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 1rem;">Please provide a reason for rejection (minimum 10 characters):</p>
					<textarea id="rejection-notes" class="swal2-textarea" placeholder="Enter rejection reason..." style="width: 100%; min-height: 100px;"></textarea>
				</div>
			`,
			icon: "warning",
			showCancelButton: true,
			confirmButtonColor: "#ef4444",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Reject",
			cancelButtonText: "Cancel",
			preConfirm: () => {
				const textarea = document.getElementById('rejection-notes') as HTMLTextAreaElement;
				const value = textarea?.value || '';
				if (value.length < 10) {
					Swal.showValidationMessage('Please provide at least 10 characters explaining the rejection');
					return null;
				}
				return value;
			}
		});

		if (notes) {
			try {
				const response = await axios.post(`/api/shop-owner/repairs/${rejection.id}/reject-high-value`, { notes });
				
				if (response.data.success) {
					await Swal.fire({
						title: "Rejected",
						text: "The high-value repair has been rejected. Customer will be notified.",
						icon: "success",
						confirmButtonColor: "#2563eb",
					});
					fetchRepairs();
					handleCloseModal();
				}
			} catch (error: any) {
				Swal.fire({
					icon: 'error',
					title: 'Rejection Failed',
					text: error.response?.data?.message || 'Failed to reject repair',
					confirmButtonColor: '#2563eb',
				});
			}
		}
	};

	const handleOpenRejectionPriceModal = () => {
		setRejectionPriceModalOpen(true);
	};

	const handleCloseRejectionPriceModal = () => {
		setRejectionPriceModalOpen(false);
	};

	const handleSaveRejectionPrice = () => {
		const parsedValue = Number(rejectionPriceThreshold);
		if (!rejectionPriceThreshold || Number.isNaN(parsedValue) || parsedValue <= 0) {
			Swal.fire({
				icon: "error",
				title: "Invalid Price",
				text: "Please enter a valid price greater than 0.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		Swal.fire({
			icon: "success",
			title: "Threshold Saved",
			text: "Rejection approval price threshold has been updated.",
			confirmButtonColor: "#2563eb",
		});
		setRejectionPriceModalOpen(false);
	};

	return (
		<AppLayoutShopOwner>
			<Head title="Repair Rejection Status - Solespace ERP" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1 text-gray-900 dark:text-white">High-Value Repair Approvals</h1>
						<p className="text-gray-600 dark:text-gray-400">Review and approve repair requests above your threshold price</p>
					</div>
					<div />
				</div>

				<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
					<MetricCard
						title="Pending Reviews"
						value={pendingCount}
						change={8}
						changeType="increase"
						icon={ClockIcon}
						color="warning"
						description="Requests awaiting decision"
					/>
					<MetricCard
						title="Approved"
						value={approvedCount}
						change={5}
						changeType="increase"
						icon={CheckIcon}
						color="success"
						description="Approved repairs"
					/>
					<MetricCard
						title="Rejected"
						value={rejectedCount}
						change={2}
						changeType="decrease"
						icon={XIcon}
						color="info"
						description="Rejected repairs"
					/>
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4 flex items-center justify-between">
						<div>
							<h2 className="text-lg font-semibold text-gray-900 dark:text-white">High-Value Repair Requests</h2>
							<p className="text-sm text-gray-500 dark:text-gray-400">Review repair requests that require your approval</p>
						</div>
						<div className="flex items-center gap-2">
							<button
								onClick={handleOpenRejectionPriceModal}
								className="px-3 py-2 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700"
								title="Set rejection approval price"
							>
								Set Rejection Price
							</button>
							<Link
								href="/shop-owner/history-rejection"
								className="flex items-center gap-2 px-3 py-2 text-sm rounded-md bg-blue-600 text-white hover:bg-blue-700"
								title="View rejection history"
							>
								<HistoryIcon className="w-4 h-4" />
								History
							</Link>
						</div>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by request number, service name, or customer..."
								value={searchQuery}
								onChange={(e) => {
									setSearchQuery(e.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
						<div className="sm:w-48">
							<select
								value={statusFilter}
								onChange={(e) => {
									setStatusFilter(e.target.value);
									setCurrentPage(1);
								}}
								title="Filter by status"
								aria-label="Filter by status"
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="All">All Status</option>
							<option value="owner_approval_pending">Pending</option>
							<option value="owner_approved">Approved</option>
							<option value="owner_rejected">Rejected</option>
							</select>
						</div>
					</div>

					<div className="overflow-x-auto">
						{loading ? (
							<div className="py-20 text-center text-gray-500 dark:text-gray-400">
								<div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
								<p className="mt-4">Loading repair requests...</p>
							</div>
						) : (
							<table className="w-full text-sm">
								<thead className="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
									<tr>
										<th className="pb-3 font-medium">Request</th>
										<th className="pb-3 font-medium">Services</th>
										<th className="pb-3 font-medium">Customer</th>
										<th className="pb-3 font-medium">Total</th>
										<th className="pb-3 font-medium">Status</th>
										<th className="pb-3 font-medium text-right">Action</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
									{paginatedRejections.map((rejection) => {
										const customerName = `${rejection.user?.first_name || ''} ${rejection.user?.last_name || ''}`;
										const serviceNames = rejection.services?.map(s => s.name).join(', ') || 'N/A';
										const statusLabel = rejection.status === 'owner_approval_pending' ? 'Pending' : 
														  rejection.status === 'owner_approved' ? 'Approved' : 'Rejected';
										
										return (
											<tr key={rejection.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
												<td className="py-4">
													<p className="font-medium text-gray-900 dark:text-white">{rejection.request_number}</p>
													<p className="text-xs text-gray-500 dark:text-gray-400">{new Date(rejection.created_at).toLocaleDateString()}</p>
												</td>
												<td className="py-4 text-gray-700 dark:text-gray-300">{serviceNames}</td>
												<td className="py-4">
													<p className="text-gray-900 dark:text-white">{customerName}</p>
													<p className="text-xs text-gray-500 dark:text-gray-400">{rejection.user?.email}</p>
												</td>
												<td className="py-4">
													<p className="font-semibold text-gray-900 dark:text-white">₱{rejection.total.toFixed(2)}</p>
												</td>
												<td className="py-4">
													<span
														className={`px-2 py-1 rounded-full text-xs font-semibold ${
															rejection.status === "owner_approval_pending"
																? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300"
																: rejection.status === "owner_approved"
																? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
																: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
														}`}
													>
														{statusLabel}
													</span>
												</td>
												<td className="py-4 text-right">
													<div className="inline-flex items-center gap-2">
														<button
															onClick={() => handleViewClick(rejection)}
															className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
															title="View Details"
														>
															<EyeIcon className="w-5 h-5" />
														</button>
													</div>
												</td>
											</tr>
										);
									})}
									{paginatedRejections.length === 0 && (
										<tr>
											<td colSpan={6} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
												{loading ? 'Loading...' : 'No high-value repair requests found.'}
											</td>
										</tr>
									)}
								</tbody>
							</table>
						)}
					</div>

					{filteredData.length > 0 && (
						<div className="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
							<div className="flex items-center justify-between">
								<div className="text-sm text-gray-700 dark:text-gray-300">
									Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
									<span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredData.length)}</span> of{" "}
									<span className="font-medium">{filteredData.length}</span>
								</div>
								<div className="flex items-center gap-2">
									<button
										onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
										disabled={currentPage === 1}
									title="Previous page"
									aria-label="Go to previous page"
									className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
									>
										<svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
										</svg>
									</button>

									{Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
										if (page === 1 || page === totalPages || (page >= currentPage - 1 && page <= currentPage + 1)) {
											return (
												<button
													key={page}
													onClick={() => setCurrentPage(page)}
													className={`min-w-10 h-10 px-3 rounded-lg font-medium transition-colors ${
														currentPage === page
															? "bg-blue-600 text-white"
															: "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
													}`}
												>
													{page}
												</button>
											);
										} else if (page === currentPage - 2 || page === currentPage + 2) {
											return (
												<span key={page} className="px-2 text-gray-500 dark:text-gray-400">
													...
												</span>
											);
										}
										return null;
									})}

									<button
										onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
										disabled={currentPage === totalPages}
											title="Next page"
											aria-label="Go to next page"
											className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
									>
										<svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
										</svg>
									</button>
								</div>
							</div>
						</div>
					)}
				</div>
			</div>

			{viewModalOpen && selectedRejection && (
				<div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={handleCloseModal} />
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-5xl max-h-[90vh] overflow-hidden">
						<div className="px-8 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
							<div className="flex items-center gap-3">
								<div className="flex items-center justify-center w-10 h-10 rounded-xl bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-200">
									<WrenchIcon className="size-5" />
								</div>
								<div>
								<h3 className="text-lg font-semibold text-gray-900 dark:text-white">High-Value Repair Details</h3>
								<p className="text-sm text-gray-500 dark:text-gray-400">{selectedRejection.request_number}</p>
							</div>
						</div>
						<button
							onClick={handleCloseModal}
							title="Close modal"
							aria-label="Close modal"
							className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
						>
							<XIcon className="size-5" />
						</button>
					</div>

					<div className="px-8 py-6 overflow-y-auto max-h-[70vh]">
						<div className="space-y-6">
							<div>
								<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Services & Pricing</p>
								<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 bg-gray-50 dark:bg-gray-800/40">
									{selectedRejection.services && selectedRejection.services.length > 0 ? (
										<div className="space-y-2">
											{selectedRejection.services.map((service, index) => (
												<div key={index} className="flex justify-between items-center">
													<span className="text-gray-900 dark:text-white">{service.name}</span>
													<span className="font-semibold text-gray-900 dark:text-white">₱{service.base_price.toFixed(2)}</span>
												</div>
											))}
											<div className="border-t border-gray-300 dark:border-gray-700 mt-3 pt-3 flex justify-between items-center">
												<span className="font-semibold text-gray-900 dark:text-white">Total</span>
												<span className="text-lg font-bold text-orange-600 dark:text-orange-400">₱{selectedRejection.total.toFixed(2)}</span>
											</div>
										</div>
									) : (
										<p className="text-gray-500 dark:text-gray-400">No services listed</p>
									)}
								</div>
							</div>

							<div>
								<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Customer & Request Information</p>
								<div className="grid grid-cols-2 gap-4">
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
										<p className="text-gray-600 dark:text-gray-400">Customer Name</p>
										<p className="font-semibold text-gray-900 dark:text-white">
											{selectedRejection.user?.first_name} {selectedRejection.user?.last_name}
										</p>
									</div>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
										<p className="text-gray-600 dark:text-gray-400">Email</p>
										<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.user?.email}</p>
									</div>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
										<p className="text-gray-600 dark:text-gray-400">Phone</p>
										<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.user?.phone_number || 'N/A'}</p>
									</div>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
										<p className="text-gray-600 dark:text-gray-400">Request Date</p>
										<p className="font-semibold text-gray-900 dark:text-white">
											{new Date(selectedRejection.created_at).toLocaleDateString()}
										</p>
									</div>
									{selectedRejection.repairer && (
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
											<p className="text-gray-600 dark:text-gray-400">Assigned Repairer</p>
											<p className="font-semibold text-gray-900 dark:text-white">
												{selectedRejection.repairer.first_name} {selectedRejection.repairer.last_name}
											</p>
										</div>
									)}
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
										<p className="text-gray-600 dark:text-gray-400">Status</p>
										<p className={`font-semibold ${
											selectedRejection.status === "owner_approval_pending"
												? "text-yellow-600 dark:text-yellow-400"
												: selectedRejection.status === "owner_approved"
												? "text-green-600 dark:text-green-400"
												: "text-red-600 dark:text-red-400"
										}`}>
											{selectedRejection.status === 'owner_approval_pending' ? 'Pending Your Approval' :
											 selectedRejection.status === 'owner_approved' ? 'Approved' : 'Rejected'}
										</p>
									</div>
								</div>
							</div>

							{selectedRejection.owner_approval_notes && (
								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										{selectedRejection.status === "owner_approved" ? "Approval Notes" : "Rejection Notes"}
									</p>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-800/40">
										{selectedRejection.owner_approval_notes}
									</div>
								</div>
							)}

							{selectedRejection.status === "owner_approved" && selectedRejection.owner_reviewed_by && (
								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Approval Details</p>
									<div className="grid grid-cols-2 gap-4">
										<div className="border border-green-200 dark:border-green-800 rounded-lg p-4 text-sm bg-green-50 dark:bg-green-900/20">
											<p className="text-green-700 dark:text-green-400">Approved By</p>
											<p className="font-semibold text-green-900 dark:text-green-100">
												{selectedRejection.owner_reviewed_by.first_name} {selectedRejection.owner_reviewed_by.last_name}
											</p>
										</div>
										<div className="border border-green-200 dark:border-green-800 rounded-lg p-4 text-sm bg-green-50 dark:bg-green-900/20">
											<p className="text-green-700 dark:text-green-400">Approved At</p>
											<p className="font-semibold text-green-900 dark:text-green-100">
												{selectedRejection.owner_reviewed_at ? new Date(selectedRejection.owner_reviewed_at).toLocaleString() : 'N/A'}
											</p>
										</div>
									</div>
								</div>
							)}

							{selectedRejection.status === "owner_rejected" && selectedRejection.owner_reviewed_by && (
								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rejection Details</p>
									<div className="grid grid-cols-2 gap-4">
										<div className="border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm bg-red-50 dark:bg-red-900/20">
											<p className="text-red-700 dark:text-red-400">Rejected By</p>
											<p className="font-semibold text-red-900 dark:text-red-100">
												{selectedRejection.owner_reviewed_by.first_name} {selectedRejection.owner_reviewed_by.last_name}
											</p>
										</div>
										<div className="border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm bg-red-50 dark:bg-red-900/20">
											<p className="text-red-700 dark:text-red-400">Rejected At</p>
											<p className="font-semibold text-red-900 dark:text-red-100">
												{selectedRejection.owner_reviewed_at ? new Date(selectedRejection.owner_reviewed_at).toLocaleString() : 'N/A'}
											</p>
										</div>
									</div>
								</div>
							)}
						</div>
					</div>

					<div className="px-8 py-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-3">
						<button
							onClick={handleCloseModal}
							className="px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
						>
							Close
						</button>
						<button
							onClick={() => handleApproveRejection(selectedRejection)}
							disabled={selectedRejection.status !== "owner_approval_pending"}
							className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
						>
							Approve
						</button>
						<button
							onClick={() => handleRejectRejection(selectedRejection)}
							disabled={selectedRejection.status !== "owner_approval_pending"}
							className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
						>
							Reject
						</button>
					</div>
					</div>
				</div>
			)}

			{rejectionPriceModalOpen && (
				<div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={handleCloseRejectionPriceModal} />
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg overflow-hidden">
						<div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
							<div>
								<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Set Rejection Price</h3>
								<p className="text-sm text-gray-500 dark:text-gray-400">Set the price threshold that requires your approval.</p>
							</div>
							<button
								onClick={handleCloseRejectionPriceModal}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
								title="Close"
							>
								<XIcon className="size-5" />
							</button>
						</div>

						<div className="px-6 py-5 space-y-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Approval Threshold Price</label>
								<input
									type="number"
									min="0"
									step="0.01"
									value={rejectionPriceThreshold}
									onChange={(e) => setRejectionPriceThreshold(e.target.value)}
									placeholder="Enter amount"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
								<p className="text-xs text-gray-500 dark:text-gray-400 mt-2">Requests above this price will require your approval.</p>
							</div>
						</div>

						<div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-3">
							<button
								onClick={handleCloseRejectionPriceModal}
								className="px-4 py-2 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
							>
								Cancel
							</button>
							<button
								onClick={handleSaveRejectionPrice}
								className="px-4 py-2 text-sm font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700"
							>
								Save
							</button>
						</div>
					</div>
				</div>
			)}

			{activeImage && (
				<div className="fixed inset-0 z-1000000 flex items-center justify-center bg-black/80 p-6" onClick={() => setActiveImage(null)}>
					<button
						className="absolute top-4 right-4 text-white/80 hover:text-white"
						title="Close"
					>
						<XIcon className="size-6" />
					</button>
					<img src={activeImage} alt="Rejection evidence" className="max-h-[85vh] max-w-[90vw] rounded-xl shadow-2xl" />
				</div>
			)}

			{activeImage && (
				<div className="fixed inset-0 z-1000000 flex items-center justify-center bg-black/80 p-6" onClick={() => setActiveImage(null)}>
					<button
						className="absolute top-4 right-4 text-white/80 hover:text-white"
						title="Close"
					>
						<XIcon className="size-6" />
					</button>
					<img src={activeImage} alt="Rejection evidence" className="max-h-[85vh] max-w-[90vw] rounded-xl shadow-2xl" />
				</div>
			)}
		</AppLayoutShopOwner>
	);
}
