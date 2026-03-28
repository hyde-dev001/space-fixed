import { Head, usePage } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useMemo, useState } from "react";
import { hasRole } from "../../../utils/permissions";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import axios from "axios";

// Sample initial data - replace with API data
const initialRepairRejections: RepairRejection[] = [
	{
		id: 101,
		requestNumber: "RR-2026-0045",
		serviceName: "Sole Replacement",
		category: "Structural Repair",
		customerName: "Maria Santos",
		orderedBy: "John Manager",
		requestedOn: "2026-02-08",
		reason: "Customer declined high repair cost",
		rejectionReason: "Cost exceeds 40% of shoe value",
		status: "Pending",
		media: [
			"/images/product/product-01.jpg",
			"/images/product/product-02.jpg",
		],
	},
	{
		id: 102,
		requestNumber: "RR-2026-0046",
		serviceName: "Stitching Repair",
		category: "Minor Repair",
		customerName: "Carlos Reyes",
		orderedBy: "Jane Supervisor",
		requestedOn: "2026-02-07",
		reason: "Customer preferred DIY solution",
		rejectionReason: "Low value repair, customer declined",
		status: "Pending",
		media: [
			"/images/product/product-03.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
		],
	},
	{
		id: 103,
		requestNumber: "RR-2026-0044",
		serviceName: "Heel Replacement",
		category: "Structural Repair",
		customerName: "Sofia Cruz",
		orderedBy: "Mike Manager",
		requestedOn: "2026-02-05",
		reason: "Shoes out of warranty",
		rejectionReason: "Out of warranty period (60+ days)",
		status: "Approved",
		approvedBy: "Finance Admin",
		approvedAt: "2026-02-06",
		media: [
			"/images/product/product-06.jpg",
			"/images/product/product-07.jpg",
		],
	},
	{
		id: 104,
		requestNumber: "RR-2026-0043",
		serviceName: "Insole Replacement",
		category: "Comfort Work",
		customerName: "Paolo Fernandez",
		orderedBy: "Sarah Lead",
		requestedOn: "2026-02-04",
		reason: "Normal wear and tear",
		rejectionReason: "Normal wear excluded from warranty",
		status: "Rejected",
		rejectedBy: "Finance Reviewer",
		rejectedAt: "2026-02-04",
		decisionReason: "Does not meet warranty criteria",
		media: [
			"/images/product/product-02.jpg",
			"/images/product/product-03.jpg",
		],
	},
	{
		id: 105,
		requestNumber: "RR-2026-0042",
		serviceName: "Waterproofing Treatment",
		category: "Preventive Treatment",
		customerName: "Elena Mercado",
		orderedBy: "John Manager",
		requestedOn: "2026-02-03",
		reason: "Not covered service",
		rejectionReason: "Preventive treatment not in standard warranty",
		status: "Pending",
		media: [
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-06.jpg",
		],
	},
];

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

interface RepairRejection {
	id: number;
	requestNumber: string;
	serviceName: string;
	category?: string;
	customerName: string;
	orderedBy: string;
	requestedOn: string;
	reason?: string;
	rejectionReason: string;
	status: "Pending" | "Approved" | "Rejected";
	approvedBy?: string;
	approvedAt?: string;
	rejectedBy?: string;
	rejectedAt?: string;
	decisionReason?: string;
	media?: string[];
	repairerName?: string;
	rawStatus?: string;
	workflowStage?: "manager_initial" | "manager_final" | "resolved";
}

interface AvailableRepairer {
	id: number;
	name: string;
	email?: string;
	active_repairs?: number;
}

type FeedbackTone = "success" | "error" | "warning";

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

	const [rejections, setRejections] = useState<RepairRejection[]>([]);
	const [currentPage, setCurrentPage] = useState(1);
	const [viewModalOpen, setViewModalOpen] = useState(false);
	const [selectedRejection, setSelectedRejection] = useState<RepairRejection | null>(null);
	const [activeImage, setActiveImage] = useState<string | null>(null);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState("Pending");
	const [isLoading, setIsLoading] = useState(true);
	const [accessDeniedOpen, setAccessDeniedOpen] = useState(false);
	const [approveModalOpen, setApproveModalOpen] = useState(false);
	const [approveNotes, setApproveNotes] = useState("");
	const [isApproving, setIsApproving] = useState(false);
	const [overrideModalOpen, setOverrideModalOpen] = useState(false);
	const [overrideNotes, setOverrideNotes] = useState("");
	const [overrideError, setOverrideError] = useState("");
	const [isOverriding, setIsOverriding] = useState(false);
	const [isLoadingRepairers, setIsLoadingRepairers] = useState(false);
	const [overrideTarget, setOverrideTarget] = useState<RepairRejection | null>(null);
	const [availableRepairers, setAvailableRepairers] = useState<AvailableRepairer[]>([]);
	const [selectedRepairerId, setSelectedRepairerId] = useState<number | null>(null);
	const [feedbackModal, setFeedbackModal] = useState<{
		open: boolean;
		title: string;
		message: string;
		tone: FeedbackTone;
	}>({
		open: false,
		title: "",
		message: "",
		tone: "success",
	});

	useEffect(() => {
		// Check if user has Manager role using Spatie
		const isManager = hasRole(auth, "Manager");

		if (!isManager) {
			setAccessDeniedOpen(true);
		} else {
			fetchRejections();
		}
	}, []);

	const fetchRejections = async () => {
		try {
			setIsLoading(true);
			const response = await axios.get('/api/manager/repairs/rejected');
			
			if (response.data.success) {
				const formattedData = response.data.data.map((item: any) => {
					const workflowStage: RepairRejection['workflowStage'] =
						item.status === 'repairer_rejected'
							? 'manager_initial'
							: item.status === 'manager_reviewing'
							? 'manager_final'
							: 'resolved';

					const mappedStatus: RepairRejection['status'] =
						item.status === 'repairer_rejected' || item.status === 'manager_reviewing'
							? 'Pending'
							: item.status === 'rejected' || item.manager_decision === 'approve_rejection'
							? 'Approved'
							: item.status === 'manager_rejected' || item.status === 'assigned_to_repairer' || item.manager_decision === 'override_accept'
							? 'Rejected'
							: 'Pending';

					return {
					id: item.id,
					requestNumber: item.request_id,
					serviceName: item.services?.map((s: any) => s.name).join(', ') || 'N/A',
					category: item.services?.[0]?.category || 'General',
					customerName: item.user?.name || item.customer_name,
					orderedBy: item.repairer_rejected_by?.name || 'Staff',
					requestedOn: new Date(item.created_at).toISOString().split('T')[0],
					reason: item.description || 'No description',
					rejectionReason: item.repairer_rejection_reason,
					status: mappedStatus,
					approvedBy: item.manager_reviewed_by?.name,
					approvedAt: item.manager_reviewed_at,
					rejectedBy: item.manager_reviewed_by?.name,
					rejectedAt: item.manager_reviewed_at,
					decisionReason: item.manager_review_notes,
					media: item.images ? JSON.parse(item.images) : [],
					repairerName: item.repairer?.name || 'Unassigned',
					rawStatus: item.status,
					workflowStage,
					};
				});
				setRejections(formattedData);
			}
		} catch (error) {
			console.error('Failed to fetch rejections:', error);
			setFeedbackModal({
				open: true,
				title: 'Failed to load rejections',
				message: 'Please try refreshing the page.',
				tone: 'error',
			});
		} finally {
			setIsLoading(false);
		}
	};

	const filteredData = useMemo(() => {
		return rejections.filter((item) => {
			const matchesSearch =
				item.requestNumber.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.serviceName.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.customerName.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.orderedBy.toLowerCase().includes(searchQuery.toLowerCase());
			const matchesStatus = statusFilter === "All" || item.status === statusFilter;
			return matchesSearch && matchesStatus;
		});
	}, [rejections, searchQuery, statusFilter]);

	const itemsPerPage = 5;
	const totalPages = Math.ceil(filteredData.length / itemsPerPage) || 1;
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedRejections = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const pendingCount = rejections.filter((r) => r.status === "Pending").length;
	const approvedCount = rejections.filter((r) => r.status === "Approved").length;
	const rejectedCount = rejections.filter((r) => r.status === "Rejected").length;

	const handleViewClick = (rejection: RepairRejection) => {
		setSelectedRejection(rejection);
		setViewModalOpen(true);
	};

	const handleCloseModal = () => {
		setViewModalOpen(false);
		setActiveImage(null);
		setApproveModalOpen(false);
		setOverrideModalOpen(false);
		setOverrideError("");
	};

	const handleApproveRejection = (rejection: RepairRejection) => {
		setSelectedRejection(rejection);
		setApproveNotes("");
		setApproveModalOpen(true);
	};

	const getApproveActionConfig = (rejection: RepairRejection) => {
		if (rejection.workflowStage === 'manager_final') {
			return {
				title: 'Finalize Rejection',
				button: 'Finalize Rejection',
				endpoint: `/api/manager/repairs/${rejection.id}/finalize-rejection`,
			};
		}

		return {
			title: 'Forward to Shop Owner',
			button: 'Forward to Shop Owner',
			endpoint: `/api/manager/repairs/${rejection.id}/approve-rejection`,
		};
	};

	const submitApproveRejection = async () => {
		if (!selectedRejection) return;

		try {
			setIsApproving(true);
			const action = getApproveActionConfig(selectedRejection);
			const response = await axios.post(action.endpoint, {
				notes: approveNotes,
			});

			if (response.data.success) {
				setApproveModalOpen(false);
				handleCloseModal();
				setFeedbackModal({
					open: true,
					title: selectedRejection.workflowStage === 'manager_final' ? 'Final Approval Complete' : 'Forwarded to Shop Owner',
					message: response.data.message || "Action completed successfully.",
					tone: "success",
				});
				fetchRejections();
			}
		} catch (error: any) {
			console.error('Failed to approve rejection:', error);
			setFeedbackModal({
				open: true,
				title: 'Failed to submit action',
				message: error.response?.data?.message || 'Please try again.',
				tone: 'error',
			});
		} finally {
			setIsApproving(false);
		}
	};

	const handleRejectRejection = async (rejection: RepairRejection) => {
		if (rejection.workflowStage !== 'manager_initial') {
			setFeedbackModal({
				open: true,
				title: 'Override unavailable',
				message: 'Override is only available during the first manager review stage.',
				tone: 'warning',
			});
			return;
		}

		try {
			setIsLoadingRepairers(true);
			setOverrideTarget(rejection);
			setOverrideNotes("");
			setOverrideError("");
			setSelectedRepairerId(null);
			setAvailableRepairers([]);

			// First, fetch available repairers
			const repairerResponse = await axios.get(`/api/manager/repairs/${rejection.id}/available-repairers`);
			
			// Check if override is even possible
			if (!repairerResponse.data.success || !repairerResponse.data.can_override) {
				setFeedbackModal({
					open: true,
					title: 'Cannot Override Rejection',
					message: repairerResponse.data.message || 'There are no other repairers available in this shop to reassign the repair to. The rejection must be approved instead.',
					tone: 'warning',
				});
				return;
			}

			const repairers: AvailableRepairer[] = repairerResponse.data.available_repairers || [];
			setAvailableRepairers(repairers);
			setSelectedRepairerId(null);
			setOverrideModalOpen(true);
		} catch (error: any) {
			console.error('Failed to fetch available repairers:', error);
			setFeedbackModal({
				open: true,
				title: 'Failed to load available repairers',
				message: error.response?.data?.message || error.message || 'Please try again.',
				tone: 'error',
			});
		} finally {
			setIsLoadingRepairers(false);
		}
	};

	const submitOverrideRejection = async () => {
		if (!overrideTarget) return;
		if (!selectedRepairerId) {
			setOverrideError('Please select a repairer before continuing.');
			return;
		}
		if (overrideNotes.trim().length < 10) {
			setOverrideError('Please provide a reason with at least 10 characters.');
			return;
		}

		try {
			setIsOverriding(true);
			setOverrideError("");
			const response = await axios.post(`/api/manager/repairs/${overrideTarget.id}/override-rejection`, {
				notes: overrideNotes,
				repairer_id: selectedRepairerId,
			});

			if (response.data.success) {
				setOverrideModalOpen(false);
				handleCloseModal();
				setFeedbackModal({
					open: true,
					title: 'Overridden',
					message: response.data.message || 'The repair has been reassigned to the selected repairer.',
					tone: 'success',
				});
				fetchRejections();
			}
		} catch (error: any) {
			console.error('Failed to override rejection:', error);
			setFeedbackModal({
				open: true,
				title: 'Failed to override',
				message: error.response?.data?.message || 'Please try again.',
				tone: 'error',
			});
		} finally {
			setIsOverriding(false);
		}
	};

	const feedbackToneClasses =
		feedbackModal.tone === "success"
			? "bg-green-50 border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800 dark:text-green-200"
			: feedbackModal.tone === "warning"
			? "bg-yellow-50 border-yellow-200 text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-800 dark:text-yellow-200"
			: "bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-200";

	return (
		<AppLayoutERP>
			<Head title="Repair Rejection Review - Solespace ERP" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1 text-gray-900 dark:text-white">Repair Rejected Review</h1>
						<p className="text-gray-600 dark:text-gray-400">Review and approve rejection of repair service requests</p>
					</div>
					<div className="flex flex-wrap items-center justify-end gap-3">
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
							Manager Access
						</span>
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200">
							Repair/Both Shops
						</span>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
					<MetricCard
						title="Pending Reviews"
						value={pendingCount}
						change={8}
						changeType="increase"
						icon={ClockIcon}
						color="warning"
						description="Awaiting your review"
					/>
					<MetricCard
						title="Approved"
						value={approvedCount}
						change={5}
						changeType="increase"
						icon={CheckIcon}
						color="success"
						description="This month"
					/>
					<MetricCard
						title="Rejected"
						value={rejectedCount}
						change={2}
						changeType="decrease"
						icon={XIcon}
						color="info"
						description="This month"
					/>
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold text-gray-900 dark:text-white">Rejection Requests</h2>
						<p className="text-sm text-gray-500 dark:text-gray-400">Review and take action on repair rejection requests</p>
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
								<option value="Pending">Pending</option>
								<option value="Approved">Approved</option>
								<option value="Rejected">Rejected</option>
							</select>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="w-full text-sm">
							<thead className="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
								<tr>
									<th className="pb-3 font-medium">Request</th>
									<th className="pb-3 font-medium">Service</th>
									<th className="pb-3 font-medium">Customer</th>
									<th className="pb-3 font-medium">Rected By</th>
									<th className="pb-3 font-medium">Status</th>
									<th className="pb-3 font-medium text-right">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
								{paginatedRejections.map((rejection) => (
									<tr key={rejection.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
										<td className="py-4">
											<p className="font-medium text-gray-900 dark:text-white">{rejection.requestNumber}</p>
										</td>
										<td className="py-4 text-gray-700 dark:text-gray-300">{rejection.serviceName}</td>
										<td className="py-4 text-gray-700 dark:text-gray-300">{rejection.customerName}</td>
										<td className="py-4 text-gray-700 dark:text-gray-300">{rejection.orderedBy}</td>
										<td className="py-4">
											<span
												className={`px-2 py-1 rounded-full text-xs font-semibold ${
													rejection.status === "Pending"
														? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300"
														: rejection.status === "Approved"
														? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
														: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
												}`}
											>
												{rejection.status}
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
								))}
								{paginatedRejections.length === 0 && (
									<tr>
										<td colSpan={6} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
											No rejection requests found.
										</td>
									</tr>
								)}
							</tbody>
						</table>
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
									<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Rejection Review Details</h3>
									<p className="text-sm text-gray-500 dark:text-gray-400">{selectedRejection.requestNumber}</p>
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
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Service Details</p>
									<div className="grid grid-cols-2 gap-4">
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-gray-50 dark:bg-gray-800/40">
											<p className="text-gray-600 dark:text-gray-400">Service Name</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.serviceName}</p>
										</div>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-gray-50 dark:bg-gray-800/40">
											<p className="text-gray-600 dark:text-gray-400">Category</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.category}</p>
										</div>
									</div>
								</div>

								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Customer & Request Information</p>
									<div className="grid grid-cols-2 gap-4">
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
											<p className="text-gray-600 dark:text-gray-400">Customer Name</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.customerName}</p>
										</div>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
											<p className="text-gray-600 dark:text-gray-400">Requested On</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.requestedOn}</p>
										</div>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
											<p className="text-gray-600 dark:text-gray-400">Rected By</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedRejection.orderedBy}</p>
										</div>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm bg-white dark:bg-gray-900">
											<p className="text-gray-600 dark:text-gray-400">Status</p>
											<p className={`font-semibold ${
												selectedRejection.status === "Pending"
													? "text-yellow-600 dark:text-yellow-400"
													: selectedRejection.status === "Approved"
													? "text-green-600 dark:text-green-400"
													: "text-red-600 dark:text-red-400"
											}`}>
												{selectedRejection.status}
											</p>
										</div>
									</div>
								</div>

								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rejection Reason</p>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-800/40">
										{selectedRejection.rejectionReason}
									</div>
								</div>

								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Initial Reason</p>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900">
										{selectedRejection.reason}
									</div>
								</div>

								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Supporting Photos</p>
									{selectedRejection.media && selectedRejection.media.length > 0 ? (
										<div className="grid grid-cols-5 gap-3">
											{selectedRejection.media.slice(0, 5).map((src, index) => (
												<button
													key={`${selectedRejection.id}-media-${index}`}
													onClick={() => setActiveImage(src)}
													className="relative aspect-square overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
													title="View image"
												>
													<img src={src} alt={`Rejection evidence ${index + 1}`} className="w-full h-full object-cover" />
												</button>
											))}
										</div>
									) : (
										<p className="text-sm text-gray-500 dark:text-gray-400">No media attached.</p>
									)}
								</div>

								{selectedRejection.status === "Approved" && (
									<div>
										<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Approval Details</p>
										<div className="grid grid-cols-2 gap-4">
											<div className="border border-green-200 dark:border-green-800 rounded-lg p-4 text-sm bg-green-50 dark:bg-green-900/20">
												<p className="text-green-700 dark:text-green-400">Approved By</p>
												<p className="font-semibold text-green-900 dark:text-green-100">{selectedRejection.approvedBy}</p>
											</div>
											<div className="border border-green-200 dark:border-green-800 rounded-lg p-4 text-sm bg-green-50 dark:bg-green-900/20">
												<p className="text-green-700 dark:text-green-400">Approved At</p>
												<p className="font-semibold text-green-900 dark:text-green-100">{selectedRejection.approvedAt}</p>
											</div>
										</div>
									</div>
								)}

								{selectedRejection.status === "Rejected" && (
									<div>
										<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rejection Details</p>
										<div className="grid grid-cols-2 gap-4">
											<div className="border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm bg-red-50 dark:bg-red-900/20">
												<p className="text-red-700 dark:text-red-400">Rejected By</p>
												<p className="font-semibold text-red-900 dark:text-red-100">{selectedRejection.rejectedBy}</p>
											</div>
											<div className="border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm bg-red-50 dark:bg-red-900/20">
												<p className="text-red-700 dark:text-red-400">Rejected At</p>
												<p className="font-semibold text-red-900 dark:text-red-100">{selectedRejection.rejectedAt}</p>
											</div>
										</div>
										<div className="border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm bg-red-50 dark:bg-red-900/20 mt-4">
											<p className="text-red-700 dark:text-red-400">Decision Reason</p>
											<p className="text-red-900 dark:text-red-100">{selectedRejection.decisionReason}</p>
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
								disabled={selectedRejection.status !== "Pending"}
								className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								{selectedRejection.workflowStage === 'manager_final' ? 'Finalize Rejection' : 'Forward to Shop Owner'}
							</button>
							<button
								onClick={() => handleRejectRejection(selectedRejection)}
								disabled={selectedRejection.status !== "Pending" || selectedRejection.workflowStage !== 'manager_initial' || isLoadingRepairers}
								className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								{isLoadingRepairers ? "Loading repairers..." : "Override & Reassign"}
							</button>
						</div>
					</div>
				</div>
			)}

			{accessDeniedOpen && (
				<div className="fixed inset-0 z-1000001 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" />
					<div className="relative w-full max-w-md rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-xl p-6">
						<div className="flex items-start gap-3">
							<div className="size-10 rounded-xl bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-300 flex items-center justify-center">
								<XIcon className="size-5" />
							</div>
							<div>
								<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Access Denied</h3>
								<p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
									You do not have permission to access repair rejection reviews. This page is restricted to Manager role only.
								</p>
							</div>
						</div>
						<div className="mt-6 flex justify-end">
							<button
								onClick={() => {
									setAccessDeniedOpen(false);
									window.history.back();
								}}
								className="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
							>
								Go Back
							</button>
						</div>
					</div>
				</div>
			)}

			{approveModalOpen && selectedRejection && (
				<div className="fixed inset-0 z-1000001 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={() => setApproveModalOpen(false)} />
					<div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-xl overflow-hidden">
						<div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
							<h3 className="text-lg font-semibold text-gray-900 dark:text-white">
								{selectedRejection.workflowStage === 'manager_final' ? 'Finalize Rejection' : 'Forward Rejection to Shop Owner'}
							</h3>
							<button
								onClick={() => setApproveModalOpen(false)}
								title="Close approve modal"
								aria-label="Close approve modal"
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
							>
								<XIcon className="size-5" />
							</button>
						</div>
						<div className="px-6 py-5 space-y-4">
							<div className="rounded-lg border border-green-200 bg-green-50 dark:bg-green-900/20 dark:border-green-800 p-4 text-sm">
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Request:</span> {selectedRejection.requestNumber}</p>
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Service:</span> {selectedRejection.serviceName}</p>
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Customer:</span> {selectedRejection.customerName}</p>
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Reason:</span> {selectedRejection.rejectionReason}</p>
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Rejected by:</span> {selectedRejection.orderedBy}</p>
							</div>
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes (optional)</label>
								<textarea
									value={approveNotes}
									onChange={(e) => setApproveNotes(e.target.value)}
									rows={4}
									placeholder="Add notes..."
									className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500"
								/>
							</div>
						</div>
						<div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-3">
							<button
								onClick={() => setApproveModalOpen(false)}
								className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-semibold"
							>
								Cancel
							</button>
							<button
								onClick={submitApproveRejection}
								disabled={isApproving}
								className="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
							>
								{isApproving
									? (selectedRejection.workflowStage === 'manager_final' ? 'Finalizing...' : 'Submitting...')
									: (selectedRejection.workflowStage === 'manager_final' ? 'Finalize Rejection' : 'Forward to Shop Owner')}
							</button>
						</div>
					</div>
				</div>
			)}

			{overrideModalOpen && overrideTarget && (
				<div className="fixed inset-0 z-1000001 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={() => setOverrideModalOpen(false)} />
					<div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-xl overflow-hidden">
						<div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
							<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Select Repairer for Reassignment</h3>
							<button
								onClick={() => setOverrideModalOpen(false)}
								title="Close override modal"
								aria-label="Close override modal"
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
							>
								<XIcon className="size-5" />
							</button>
						</div>
						<div className="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
							<div className="rounded-lg border border-green-200 bg-green-50 dark:bg-green-900/20 dark:border-green-800 p-4 text-sm">
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Repair:</span> {overrideTarget.requestNumber}</p>
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Customer:</span> {overrideTarget.customerName}</p>
								<p className="text-gray-800 dark:text-gray-200"><span className="font-semibold">Service:</span> {overrideTarget.serviceName}</p>
							</div>
							<div className="space-y-2">
								{availableRepairers.length === 0 ? (
									<div className="text-sm text-red-600 dark:text-red-400">No available repairers found.</div>
								) : (
									availableRepairers.map((repairer) => (
										<button
											key={repairer.id}
											type="button"
											onClick={() => {
												setSelectedRepairerId(repairer.id);
												setOverrideError("");
											}}
											className={`w-full text-left rounded-lg border p-3 transition-colors ${
												selectedRepairerId === repairer.id
													? "border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-900/20"
													: "border-gray-200 bg-gray-50 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800/50 dark:hover:bg-gray-800"
											}`}
										>
											<p className="font-semibold text-gray-900 dark:text-gray-100">{repairer.name}</p>
											<p className="text-xs text-gray-600 dark:text-gray-400">{repairer.email || "No email"} | {repairer.active_repairs ?? 0} active repairs</p>
											<p className="text-xs text-gray-500 dark:text-gray-400">Available for reassignment</p>
										</button>
									))
								)}
							</div>
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for override (required, min 10 characters)</label>
								<textarea
									value={overrideNotes}
									onChange={(e) => {
										setOverrideNotes(e.target.value);
										setOverrideError("");
									}}
									rows={4}
									placeholder="Enter reason for override..."
									className="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500"
								/>
							</div>
							{overrideError && (
								<div className="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-3 py-2 text-sm text-red-700 dark:text-red-300">
									{overrideError}
								</div>
							)}
						</div>
						<div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-3">
							<button
								onClick={() => setOverrideModalOpen(false)}
								className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-semibold"
							>
								Cancel
							</button>
							<button
								onClick={submitOverrideRejection}
								disabled={isOverriding}
								className="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
							>
								{isOverriding ? "Reassigning..." : "Override & Reassign"}
							</button>
						</div>
					</div>
				</div>
			)}

			{feedbackModal.open && (
				<div className="fixed inset-0 z-1000002 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={() => setFeedbackModal((prev) => ({ ...prev, open: false }))} />
					<div className="relative w-full max-w-md rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-xl overflow-hidden">
						<div className={`m-4 rounded-lg border p-4 ${feedbackToneClasses}`}>
							<h3 className="text-base font-semibold">{feedbackModal.title}</h3>
							<p className="mt-1 text-sm">{feedbackModal.message}</p>
						</div>
						<div className="px-4 pb-4 flex justify-end">
							<button
								onClick={() => setFeedbackModal((prev) => ({ ...prev, open: false }))}
								className="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm font-semibold hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
							>
								OK
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
		</AppLayoutERP>
	);
}
