import { Head } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useMemo, useState, useEffect } from "react";
import Swal from "sweetalert2";

interface PayslipLineItem {
	label: string;
	amount: number;
	type: string;
}

interface PayslipApprovalRequest {
	id: number;
	employee_name: string;
	employee_id: string;
	department: string;
	role: string;
	pay_period: string;
	generated_date: string;
	generated_by: string;
	gross_pay: number;
	deductions: number;
	net_pay: number;
	status: "pending" | "approved" | "rejected";
	notes: string;
	approval_notes?: string;
	line_items: PayslipLineItem[];
}

const statusLabels: Record<string, string> = {
	pending: "Pending",
	approved: "Approved",
	rejected: "Rejected",
};

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
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
		/>
	</svg>
);

const DocumentIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
		/>
	</svg>
);

const CalendarIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			strokeWidth={2}
			d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z"
		/>
	</svg>
);

const UserIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
	</svg>
);

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
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
			<div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
			<div className="relative">
				<div className="flex items-center justify-between mb-4">
					<div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
						<Icon className="text-white size-7 drop-shadow-sm" />
					</div>
					<div
						className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
							changeType === "increase"
								? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
								: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
						}`}
					>
						{changeType === "increase" ? <CheckIcon className="size-3" /> : <XIcon className="size-3" />}
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

const statusPillClasses: Record<string, string> = {
	pending: "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
	approved: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300",
	rejected: "bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300",
};

const formatCurrency = (amount: number) => {
	return `₱${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

export default function PayslipApproval() {
	const [requests, setRequests] = useState<PayslipApprovalRequest[]>([]);
	const [currentPage, setCurrentPage] = useState(1);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState<"All" | "pending" | "approved" | "rejected">("pending");
	const [viewModalOpen, setViewModalOpen] = useState(false);
	const [selectedRequest, setSelectedRequest] = useState<PayslipApprovalRequest | null>(null);
	const [loading, setLoading] = useState(true);
	const [isApproving, setIsApproving] = useState(false);

	// Batch approval states
	const [showPreviewModal, setShowPreviewModal] = useState(false);
	const [previewData, setPreviewData] = useState<any>(null);
	const [isLoadingPreview, setIsLoadingPreview] = useState(false);
	const [isBatchApproving, setIsBatchApproving] = useState(false);
	const [approvalProgress, setApprovalProgress] = useState({ current: 0, total: 0 });

	// Load payslips from API
	useEffect(() => {
		loadPayslips();
	}, []);

	const loadPayslips = async () => {
		setLoading(true);
		try {
			const response = await fetch('/api/hr/payslip-approvals', {
				headers: {
					'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
			});

			if (!response.ok) {
				if (response.status === 403) {
					await Swal.fire('Access Denied', 'You do not have permission to view payslip approvals.', 'error');
				}
				throw new Error('Failed to load payslips');
			}

			const data = await response.json();
			setRequests(data.data || []);
		} catch (error) {
			console.error('Error loading payslips:', error);
			await Swal.fire('Error', 'Failed to load payslips', 'error');
		} finally {
			setLoading(false);
		}
	};

	const filteredData = useMemo(() => {
		return requests.filter((item) => {
			const matchesSearch =
				item.employee_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.employee_id.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.department.toLowerCase().includes(searchQuery.toLowerCase());
			const matchesStatus = statusFilter === "All" || item.status === statusFilter;
			return matchesSearch && matchesStatus;
		});
	}, [requests, searchQuery, statusFilter]);

	const itemsPerPage = 6;
	const totalPages = Math.ceil(filteredData.length / itemsPerPage) || 1;
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedRequests = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const pendingCount = requests.filter((r) => r.status === "pending").length;
	const approvedCount = requests.filter((r) => r.status === "approved").length;
	const rejectedCount = requests.filter((r) => r.status === "rejected").length;

	const handleView = async (request: PayslipApprovalRequest) => {
		// Fetch full payslip details
		try {
			const response = await fetch(`/api/hr/payslip-approvals/${request.id}`, {
				headers: {
					'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
			});

			if (!response.ok) throw new Error('Failed to load payslip details');

			const data = await response.json();
			setSelectedRequest(data);
			setViewModalOpen(true);
		} catch (error) {
			await Swal.fire('Error', 'Failed to load payslip details', 'error');
		}
	};

	const handleApprove = async (request: PayslipApprovalRequest) => {
		const { value: notes } = await Swal.fire({
			title: "Approve payslip?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Employee:</strong> ${request.employee_name}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Period:</strong> ${request.pay_period}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Net Pay:</strong> ${formatCurrency(request.net_pay)}</p>
				</div>
			`,
			input: "textarea",
			inputLabel: "Finance Notes (Optional)",
			inputPlaceholder: "Add notes for HR or payroll team...",
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#10b981",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
		});

		if (notes !== undefined) {
			setIsApproving(true);
			try {
				const response = await fetch(`/api/hr/payslip-approvals/${request.id}/approve`, {
					method: 'POST',
					headers: {
						'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
						'Accept': 'application/json',
						'Content-Type': 'application/json',
					},
					credentials: 'include',
					body: JSON.stringify({ notes: notes || 'Approved by Finance' }),
				});

				if (!response.ok) throw new Error('Failed to approve payslip');

				await Swal.fire({
					title: "Approved",
					text: "Payslip approved and ready for HR release.",
					icon: "success",
					confirmButtonColor: "#111827",
				});

				setViewModalOpen(false);
				loadPayslips();
			} catch (error) {
				await Swal.fire('Error', 'Failed to approve payslip', 'error');
			} finally {
				setIsApproving(false);
			}
		}
	};

	const handleReject = async (request: PayslipApprovalRequest) => {
		const { value: reason } = await Swal.fire({
			title: "Reject payslip",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Employee:</strong> ${request.employee_name}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Period:</strong> ${request.pay_period}</p>
				</div>
			`,
			input: "textarea",
			inputLabel: "Rejection Reason",
			inputPlaceholder: "Explain the correction needed...",
			inputAttributes: { "aria-label": "Rejection reason" },
			showCancelButton: true,
			confirmButtonColor: "#ef4444",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Reject",
			cancelButtonText: "Cancel",
			inputValidator: (value) => {
				if (!value) {
					return "Please provide a reason";
				}
			},
		});

		if (reason) {
			setIsApproving(true);
			try {
				const response = await fetch(`/api/hr/payslip-approvals/${request.id}/reject`, {
					method: 'POST',
					headers: {
						'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
						'Accept': 'application/json',
						'Content-Type': 'application/json',
					},
					credentials: 'include',
					body: JSON.stringify({ notes: reason }),
				});

				if (!response.ok) throw new Error('Failed to reject payslip');

				await Swal.fire({
					title: "Rejected",
					text: "Payslip sent back to HR for correction.",
					icon: "info",
					confirmButtonColor: "#111827",
				});

				setViewModalOpen(false);
				loadPayslips();
			} catch (error) {
				await Swal.fire('Error', 'Failed to reject payslip', 'error');
			} finally {
				setIsApproving(false);
			}
		}
	};

	// Batch approval functions
	const handleApproveAll = async () => {
		const pendingRequests = requests.filter(r => r.status === 'pending');
		
		if (pendingRequests.length === 0) {
			await Swal.fire({
				icon: "info",
				title: "No Pending Payslips",
				text: "All payslips have already been approved or rejected.",
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		// Step 1: Load preview
		setIsLoadingPreview(true);
		setShowPreviewModal(true);
		
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch('/api/hr/payslip-approvals/batch/preview', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
			});

			if (!response.ok) {
				throw new Error('Failed to load preview');
			}

			const preview = await response.json();
			setPreviewData(preview);
			setIsLoadingPreview(false);
		} catch (error) {
			console.error('Error loading preview:', error);
			setIsLoadingPreview(false);
			setShowPreviewModal(false);
			await Swal.fire({
				icon: "error",
				title: "Preview Failed",
				text: "Could not generate approval preview. Please try again.",
				confirmButtonColor: "#dc2626",
			});
		}
	};

	const handleConfirmBatchApproval = async () => {
		if (!previewData) return;

		const result = await Swal.fire({
			icon: "question",
			title: "Approve All Payslips?",
			html: `
				<div class="text-left space-y-3">
					<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
						<h4 class="font-semibold text-blue-900 mb-2">📊 Summary</h4>
						<ul class="text-sm text-blue-800 space-y-1">
							<li><strong>${previewData.summary.count}</strong> payslips will be approved</li>
							<li><strong>Total Gross:</strong> ${formatCurrency(previewData.summary.total_gross)}</li>
							<li><strong>Total Net:</strong> ${formatCurrency(previewData.summary.total_net)}</li>
						</ul>
					</div>
					
					<div class="bg-green-50 border border-green-200 rounded-lg p-4">
						<label class="flex items-center gap-2 text-sm cursor-pointer">
							<input type="checkbox" id="addBatchNotes" class="rounded text-green-600" />
							<span class="text-green-800">📝 Add approval notes</span>
						</label>
						<textarea 
							id="batchNotes" 
							class="mt-2 w-full rounded border-green-300 text-sm" 
							placeholder="Optional notes for all payslips..."
							rows="2"
						></textarea>
					</div>
					
					<p class="text-xs text-gray-600">
						This will approve all pending payslips and notify HR for release.
					</p>
				</div>
			`,
			showCancelButton: true,
			confirmButtonText: "Approve All",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#16a34a",
			cancelButtonColor: "#6b7280",
			preConfirm: () => {
				return {
					notes: (document.getElementById('batchNotes') as HTMLTextAreaElement)?.value || 'Batch approved by Finance'
				};
			}
		});

		if (!result.isConfirmed) {
			setShowPreviewModal(false);
			return;
		}

		// Approve all payslips
		setIsBatchApproving(true);
		setApprovalProgress({ current: 0, total: previewData.previews.length });
		setShowPreviewModal(false);

		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch('/api/hr/payslip-approvals/batch/approve', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payslip_ids: previewData.previews.map((p: any) => p.id),
					notes: result.value.notes,
				}),
			});

			if (!response.ok) {
				throw new Error('Batch approval failed');
			}

			const batchResult = await response.json();
			
			setApprovalProgress({ current: batchResult.approved, total: batchResult.approved });
			setIsBatchApproving(false);

			// Show results
			const hasErrors = batchResult.failed > 0;
			
			await Swal.fire({
				icon: hasErrors ? "warning" : "success",
				title: hasErrors ? "Partially Completed" : "All Approved!",
				html: `
					<div class="text-left space-y-2">
						<div class="bg-green-50 border border-green-200 rounded-lg p-3">
							<p class="text-green-800"><strong>✅ Approved:</strong> ${batchResult.approved} payslip(s)</p>
						</div>
						${hasErrors ? `
							<div class="bg-red-50 border border-red-200 rounded-lg p-3">
								<p class="text-red-800"><strong>❌ Failed:</strong> ${batchResult.failed} payslip(s)</p>
								${batchResult.errors.length > 0 ? `
									<ul class="mt-2 text-xs text-red-700 space-y-1">
										${batchResult.errors.slice(0, 5).map((err: string) => `<li>• ${err}</li>`).join('')}
										${batchResult.errors.length > 5 ? `<li>• ... and ${batchResult.errors.length - 5} more</li>` : ''}
									</ul>
								` : ''}
							</div>
						` : ''}
					</div>
				`,
				confirmButtonColor: "#111827",
			});

			loadPayslips();

		} catch (error: any) {
			setIsBatchApproving(false);
			await Swal.fire({
				icon: "error",
				title: "Batch Approval Failed",
				text: error.message || "An error occurred during batch approval.",
				confirmButtonColor: "#dc2626",
			});
		}
	};

	if (loading) {
		return (
			<>
				<Head title="Payslip Approval - Solespace ERP" />
				<div className="flex items-center justify-center h-screen">
					<div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
				</div>
			</>
		);
	}

	return (
		<>
			<Head title="Payslip Approval - Solespace ERP" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1 text-gray-900 dark:text-white">Payslip Approvals</h1>
						<p className="text-gray-600 dark:text-gray-400">Review HR-generated payslips before employee release.</p>
					</div>
					<div className="flex flex-wrap items-center justify-end gap-3">
						{pendingCount > 0 && (
							<button
								onClick={handleApproveAll}
								disabled={isBatchApproving}
								className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
							>
								<CheckIcon className="size-4" />
								Approve All ({pendingCount})
							</button>
						)}
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
							Finance Review
						</span>
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">
							HR Generated
						</span>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
					<MetricCard
						title="Pending Review"
						value={pendingCount}
						change={0}
						changeType="increase"
						icon={DocumentIcon}
						color="warning"
						description="Awaiting finance approval"
					/>
					<MetricCard
						title="Approved"
						value={approvedCount}
						change={0}
						changeType="increase"
						icon={CheckIcon}
						color="success"
						description="Ready for HR release"
					/>
					<MetricCard
						title="Rejected"
						value={rejectedCount}
						change={0}
						changeType="decrease"
						icon={XIcon}
						color="info"
						description="Sent back for correction"
					/>
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4 flex flex-col gap-2">
						<h2 className="text-lg font-semibold text-gray-900 dark:text-white">Payslip Approval Queue</h2>
						<p className="text-sm text-gray-500 dark:text-gray-400">Verify amounts, deductions, and attachments before approval.</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search employee, ID, or department..."
								value={searchQuery}
								onChange={(event) => {
									setSearchQuery(event.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
						<div className="sm:w-48">
							<select
								value={statusFilter}
								onChange={(event) => {
									setStatusFilter(event.target.value as any);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="All">All Status</option>
								<option value="pending">Pending</option>
								<option value="approved">Approved</option>
								<option value="rejected">Rejected</option>
							</select>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="w-full text-sm">
							<thead className="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
								<tr>
									<th className="pb-3 font-medium">Employee</th>
									<th className="pb-3 font-medium">Pay Period</th>
									<th className="pb-3 font-medium">Net Pay</th>
									<th className="pb-3 font-medium">Date</th>
									<th className="pb-3 font-medium">Status</th>
									<th className="pb-3 font-medium text-right">Actions</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
								{paginatedRequests.length > 0 ? (
									paginatedRequests.map((request) => (
										<tr key={request.id} className="text-gray-700 dark:text-gray-200">
											<td className="py-4">
												<div className="flex items-center gap-3">
													<div className="flex items-center justify-center size-10 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
														<UserIcon className="size-5" />
													</div>
													<div>
														<p className="font-semibold text-gray-900 dark:text-white">{request.employee_name}</p>
														<p className="text-xs text-gray-500">{request.employee_id} · {request.department}</p>
													</div>
												</div>
											</td>
											<td className="py-4">
												<div className="flex items-center gap-2 text-gray-600 dark:text-gray-300">
													<CalendarIcon className="size-4" />
													{request.pay_period}
												</div>
											</td>
											<td className="py-4 font-semibold text-gray-900 dark:text-white">{formatCurrency(request.net_pay)}</td>
											<td className="py-4 text-gray-600 dark:text-gray-300">{request.generated_date}</td>
											<td className="py-4">
												<span className={`px-3 py-1 rounded-full text-xs font-semibold ${statusPillClasses[request.status]}`}>
													{statusLabels[request.status]}
												</span>
											</td>
											<td className="py-4 text-right">
												<div className="flex items-center justify-end gap-2">
													<button
														onClick={() => handleView(request)}
														title="View payslip"
														aria-label="View payslip"
														className="inline-flex items-center justify-center p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
													>
														<EyeIcon className="size-5 text-blue-600 dark:text-blue-400" />
													</button>
												</div>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={6} className="py-8 text-center text-gray-500 dark:text-gray-400">
											No payslips found
										</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-6 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
						<p>
							Showing {paginatedRequests.length} of {filteredData.length} approvals
						</p>
						<div className="flex items-center gap-2">
							<button
								onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
								disabled={currentPage === 1}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Previous page"
							>
								<svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
								</svg>
							</button>

							{Array.from({ length: totalPages }, (_, i) => i + 1).map((p) => {
								if (p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)) {
									return (
										<button
											key={p}
											onClick={() => setCurrentPage(p)}
											className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
												currentPage === p
													? "bg-blue-600 text-white"
													: "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
											}`}
										>
											{p}
										</button>
									);
								} else if (p === currentPage - 2 || p === currentPage + 2) {
									return (
										<span key={p} className="px-2 text-gray-500 dark:text-gray-400">
											...
										</span>
									);
								}
								return null;
							})}

							<button
								onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
								disabled={currentPage === totalPages}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Next page"
							>
								<svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
								</svg>
							</button>
						</div>
					</div>
				</div>
			</div>

			{viewModalOpen && selectedRequest && (
				<div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="w-full max-w-3xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-xl">
						<div className="flex items-start justify-between mb-4 px-6 py-4">
							<div>
								<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Payslip Details</h3>
								<p className="text-sm text-gray-500 dark:text-gray-400">{selectedRequest.employee_name} · {selectedRequest.employee_id}</p>
							</div>
							<button
								onClick={() => setViewModalOpen(false)}
								className="text-gray-500 hover:text-gray-600 text-xl"
								title="Close"
								aria-label="Close"
							>
								×
							</button>
						</div>

						<div className="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-4">
							<div className="space-y-2">
								<p className="text-xs uppercase text-gray-400">Role & Department</p>
								<p className="text-sm text-gray-700 dark:text-gray-200">{selectedRequest.role}</p>
								<p className="text-sm text-gray-500">{selectedRequest.department}</p>
							</div>
							<div className="space-y-2">
								<p className="text-xs uppercase text-gray-400">Pay Period</p>
								<p className="text-sm text-gray-700 dark:text-gray-200">{selectedRequest.pay_period}</p>
								<p className="text-sm text-gray-500">Generated {selectedRequest.generated_date} by {selectedRequest.generated_by}</p>
							</div>
							<div className="space-y-2">
								<p className="text-xs uppercase text-gray-400">Amounts</p>
								<p className="text-sm text-gray-700 dark:text-gray-200">Gross: {formatCurrency(selectedRequest.gross_pay)}</p>
								<p className="text-sm text-gray-700 dark:text-gray-200">Deductions: {formatCurrency(selectedRequest.deductions)}</p>
								<p className="text-sm font-semibold text-gray-900 dark:text-white">Net: {formatCurrency(selectedRequest.net_pay)}</p>
							</div>
							<div className="space-y-2">
								<p className="text-xs uppercase text-gray-400">Status</p>
								<span className={`inline-flex px-3 py-1 rounded-full text-xs font-semibold ${statusPillClasses[selectedRequest.status]}`}>
									{statusLabels[selectedRequest.status]}
								</span>
								{selectedRequest.approval_notes && (
									<p className="text-sm text-gray-500">Notes: {selectedRequest.approval_notes}</p>
								)}
							</div>
						</div>

						<div className="px-6 pb-4">
							<div className="rounded-xl border border-gray-200 dark:border-gray-800 p-4">
								<div className="flex items-center justify-between mb-3">
									<p className="font-semibold text-gray-900 dark:text-white">Payslip Breakdown</p>
								</div>
								<div className="space-y-2">
									{selectedRequest.line_items.map((item, idx) => (
										<div key={idx} className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
											<span>{item.label}</span>
											<span className="font-medium text-gray-900 dark:text-white">{formatCurrency(item.amount)}</span>
										</div>
									))}
								</div>
								{selectedRequest.notes && (
									<div className="mt-4 text-sm text-gray-500 dark:text-gray-400">
										HR Notes: {selectedRequest.notes}
									</div>
								)}
							</div>
						</div>

						<div className="flex items-center justify-end gap-3 border-t border-gray-200 dark:border-gray-800 px-6 py-4">
							<button
								onClick={() => setViewModalOpen(false)}
								className="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
							>
								Close
							</button>
							{selectedRequest.status === "pending" && (
								<>
									<button
										onClick={() => handleReject(selectedRequest)}
										disabled={isApproving}
										className="px-4 py-2 rounded-lg bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50"
									>
										Reject
									</button>
									<button
										onClick={() => handleApprove(selectedRequest)}
										disabled={isApproving}
										className="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50"
									>
										Approve
									</button>
								</>
							)}
						</div>
					</div>
				</div>
			)}

			{/* Batch Approval Preview Modal */}
			{showPreviewModal && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
					<div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
						<div className="px-6 py-5 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">
								Batch Approval Preview
							</h2>
							<p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
								Review payslips before approving all
							</p>
						</div>

						<div className="p-6 overflow-y-auto max-h-[calc(90vh-200px)]">
							{isLoadingPreview ? (
								<div className="flex items-center justify-center py-12">
									<div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
								</div>
							) : previewData ? (
								<div className="space-y-4">
									{/* Summary Card */}
									<div className="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
										<h3 className="font-semibold text-blue-900 dark:text-blue-100 mb-3">📊 Approval Summary</h3>
										<div className="grid grid-cols-3 gap-4">
											<div>
												<p className="text-sm text-blue-600 dark:text-blue-300">Payslips</p>
												<p className="text-2xl font-bold text-blue-900 dark:text-blue-50">{previewData.summary.count}</p>
											</div>
											<div>
												<p className="text-sm text-blue-600 dark:text-blue-300">Total Gross</p>
												<p className="text-2xl font-bold text-blue-900 dark:text-blue-50">{formatCurrency(previewData.summary.total_gross)}</p>
											</div>
											<div>
												<p className="text-sm text-blue-600 dark:text-blue-300">Total Net</p>
												<p className="text-2xl font-bold text-blue-900 dark:text-blue-50">{formatCurrency(previewData.summary.total_net)}</p>
											</div>
										</div>
									</div>

									{/* Payslip List */}
									<div className="space-y-2">
										<h4 className="font-medium text-gray-900 dark:text-white">Payslips to Approve ({previewData.previews.length})</h4>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden">
											<table className="w-full">
												<thead className="bg-gray-50 dark:bg-gray-800">
													<tr>
														<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300">Employee</th>
														<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300">Department</th>
														<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300">Period</th>
														<th className="px-4 py-3 text-right text-xs font-medium text-gray-700 dark:text-gray-300">Net Pay</th>
													</tr>
												</thead>
												<tbody className="divide-y divide-gray-200 dark:divide-gray-800">
													{previewData.previews.map((payslip: any) => (
														<tr key={payslip.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
															<td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{payslip.employee_name}</td>
															<td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{payslip.department}</td>
															<td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{payslip.pay_period}</td>
															<td className="px-4 py-3 text-sm text-right font-medium text-gray-900 dark:text-white">{formatCurrency(payslip.net_pay)}</td>
														</tr>
													))}
												</tbody>
											</table>
										</div>
									</div>
								</div>
							) : null}
						</div>

						<div className="flex items-center justify-end gap-3 border-t border-gray-200 dark:border-gray-800 px-6 py-4">
							<button
								onClick={() => setShowPreviewModal(false)}
								className="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
							>
								Cancel
							</button>
							<button
								onClick={handleConfirmBatchApproval}
								disabled={isLoadingPreview || !previewData}
								className="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
							>
								<CheckIcon className="size-4" />
								Confirm Approval
							</button>
						</div>
					</div>
				</div>
			)}

			{/* Batch Approval Progress Modal */}
			{isBatchApproving && approvalProgress.total > 0 && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
					<div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-md w-full p-8">
						<div className="text-center">
							<div className="mx-auto w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mb-4">
								<div className="animate-spin rounded-full h-10 w-10 border-b-2 border-green-600"></div>
							</div>
							<h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-2">
								Approving Payslips...
							</h3>
							<p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
								{approvalProgress.current} of {approvalProgress.total} approved
							</p>
							<div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
								<div
									className="bg-green-600 h-2 rounded-full transition-all duration-300"
									style={{ width: `${(approvalProgress.current / approvalProgress.total) * 100}%` }}
								></div>
							</div>
						</div>
					</div>
				</div>
			)}
		</>
	);
}
