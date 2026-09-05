import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { stockRequestApi } from "@/services/stockRequestApi";
import { workflowFeedback } from "@/utils/workflowFeedback";
import type { StockRequestApproval, StockRequestMetrics } from "@/types/procurement";

type MetricColor = "success" | "warning" | "info";
const SIZE_SYSTEMS = ["US", "UK", "EU", "AU", "CN"] as const;
const PH_TIME_ZONE = "Asia/Manila";
const PH_LOCALE = "en-PH";

const hasSizeSystemPrefix = (value: string): boolean => {
	const normalized = value.trim().toUpperCase();
	return SIZE_SYSTEMS.some((system) => normalized.startsWith(`${system} `));
};

const formatRequestedSizeDisplay = (value: string): string => {
	const trimmed = (value ?? "").trim();
	if (!trimmed) return "";
	if (hasSizeSystemPrefix(trimmed)) return trimmed;
	return `Size ${trimmed}`;
};

const getRequestedSizeLabel = (value?: string | null): string => {
	const trimmed = (value ?? "").trim();
	if (!trimmed) return "All Sizes";

	const normalized = trimmed.toLowerCase().replace(/[\s-]+/g, "_");
	if (["all", "all_sizes", "all_size", "any"].includes(normalized)) {
		return "All Sizes";
	}

	return formatRequestedSizeDisplay(trimmed);
};

const isAllSizesRequest = (value?: string | null): boolean => {
	const normalized = (value ?? "").trim().toLowerCase().replace(/[\s-]+/g, "_");
	if (!normalized) return true;
	return ["all", "all_sizes", "all_size", "any"].includes(normalized);
};

const formatSizeRowLabel = (size?: string | null, sizeSystem?: string | null): string => {
	const rawSize = (size ?? "").trim();
	if (!rawSize) return "";
	if (hasSizeSystemPrefix(rawSize)) return rawSize;

	const normalizedSystem = (sizeSystem ?? "").trim().toUpperCase();
	if (SIZE_SYSTEMS.includes(normalizedSystem as typeof SIZE_SYSTEMS[number])) {
		return `${normalizedSystem} ${rawSize}`;
	}

	return `Size ${rawSize}`;
};

const getAvailableSizeLabelsForRequest = (request: StockRequestApproval): string[] => {
	if (!isAllSizesRequest(request.requested_size)) return [];
	if (request.inventory_item?.category !== "shoes") return [];

	const requestedColor = (request.requested_color ?? "").trim().toLowerCase();
	const allSizes = request.inventory_item?.sizes ?? [];
	if (!allSizes.length) return [];

	let scopedSizes = allSizes;
	if (requestedColor) {
		const matchedVariant = (request.inventory_item?.color_variants ?? []).find(
			(variant) => String(variant.color_name).trim().toLowerCase() === requestedColor,
		);

		if (matchedVariant) {
			scopedSizes = allSizes.filter(
				(sizeRow) => Number(sizeRow.inventory_color_variant_id) === Number(matchedVariant.id),
			);
		}
	}

	const labels = scopedSizes
		.map((sizeRow) => formatSizeRowLabel(sizeRow.size, sizeRow.size_system))
		.filter((label) => label.length > 0);

	return Array.from(new Set(labels));
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

export default function StockRequest() {
	const { auth, initialData } = usePage().props as any;
	const ownerMode = auth?.erpActor?.ownerMode === true;
	const sanitizeRequests = (items: StockRequestApproval[] = []): StockRequestApproval[] => {
		return items.map((request) => ({ ...request, sku_code: "" }));
	};
	const seededRequests = sanitizeRequests(initialData?.data ?? []);
	const [requests, setRequests] = useState<StockRequestApproval[]>(seededRequests);
	const [loading, setLoading] = useState(!ownerMode && !initialData);
	const [metrics, setMetrics] = useState<StockRequestMetrics>(() => ({
		total: seededRequests.length,
		pending: seededRequests.filter((request) => request.status === "pending").length,
		accepted: seededRequests.filter((request) => request.status === "accepted").length,
		rejected: seededRequests.filter((request) => request.status === "rejected").length,
	}));
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState<"All" | StockRequestApproval["status"]>("All");
	const [priorityFilter, setPriorityFilter] = useState<"All" | StockRequestApproval["priority"]>("All");
	const [currentPage, setCurrentPage] = useState(1);
	const [viewingRequest, setViewingRequest] = useState<StockRequestApproval | null>(null);
	const [isActionProcessing, setIsActionProcessing] = useState(false);

	const fetchRequests = async () => {
		if (ownerMode || initialData) return;

		try {
			setLoading(true);
			const response = await stockRequestApi.getAll({ per_page: 100 });
			const data = (response as any).data || response || [];
			setRequests(sanitizeRequests(Array.isArray(data) ? data : []));
		} catch (error) {
			console.error("Failed to fetch stock requests:", error);
			const shouldRetry = await workflowFeedback.errorWithRetry("Failed to load stock requests");
			if (shouldRetry) {
				await fetchRequests();
			}
		} finally {
			setLoading(false);
		}
	};

	const fetchMetrics = async () => {
		if (ownerMode || initialData) return;

		try {
			const data: any = await stockRequestApi.getMetrics();
			setMetrics({
				total: data?.total ?? data?.total_stock_requests ?? 0,
				pending: data?.pending ?? data?.pending_requests ?? 0,
				accepted: data?.accepted ?? data?.accepted_requests ?? 0,
				rejected: data?.rejected ?? data?.rejected_requests ?? 0,
			});
		} catch (error) {
			console.error("Failed to fetch metrics:", error);
		}
	};

	useEffect(() => {
		void fetchRequests();
		void fetchMetrics();
	}, [ownerMode, initialData]);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		let filtered = requests;

		if (statusFilter !== "All") {
			filtered = filtered.filter((request) => request.status === statusFilter);
		}

		if (priorityFilter !== "All") {
			filtered = filtered.filter((request) => request.priority === priorityFilter);
		}

		if (!query) return filtered;

		return filtered.filter((request) =>
			request.request_number.toLowerCase().includes(query) ||
			request.product_name.toLowerCase().includes(query) ||
			request.sku_code.toLowerCase().includes(query) ||
			(request.requester?.name || "").toLowerCase().includes(query) ||
			(request.request_source || "manual").toLowerCase().includes(query) ||
			String(request.repair_request_id || "").toLowerCase().includes(query) ||
			request.status.toLowerCase().includes(query)
		);
	}, [searchQuery, statusFilter, priorityFilter, requests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const formatPriority = (priority: string) => {
		const map: Record<string, string> = { high: "High", medium: "Medium", low: "Low" };
		return map[priority] || priority;
	};

	const formatStatus = (status: string) => {
		const map: Record<string, string> = {
			pending: "Pending",
			accepted: "Approved",
			rejected: "Rejected",
			needs_details: "Needs Details",
		};
		return map[status] || status;
	};

	const parseBackendDate = (value: string) => {
		const normalized = (value || "")
			.trim()
			// Normalize microseconds (6 digits) to milliseconds (3 digits) so Date can parse reliably.
			.replace(/\.(\d{3})\d+Z$/i, ".$1Z")
			.replace(/\.(\d{3})\d+$/i, ".$1");

		const parsed = new Date(normalized);
		return Number.isNaN(parsed.getTime()) ? null : parsed;
	};

	const formatTableDate = (value: string) => {
		const parsed = parseBackendDate(value);
		if (!parsed) return value;
		return new Intl.DateTimeFormat(PH_LOCALE, {
			timeZone: PH_TIME_ZONE,
			month: "short",
			day: "2-digit",
			year: "numeric",
		}).format(parsed);
	};

	const formatTableTime = (value: string) => {
		const parsed = parseBackendDate(value);
		if (!parsed) return "";
		return new Intl.DateTimeFormat(PH_LOCALE, {
			timeZone: PH_TIME_ZONE,
			hour: "numeric",
			minute: "2-digit",
			hour12: true,
		}).format(parsed);
	};

	const formatDateTime = (value: string) => {
		const parsed = parseBackendDate(value);
		if (!parsed) return value;
		return new Intl.DateTimeFormat(PH_LOCALE, {
			timeZone: PH_TIME_ZONE,
			month: "short",
			day: "2-digit",
			year: "numeric",
			hour: "numeric",
			minute: "2-digit",
			hour12: true,
		}).format(parsed);
	};

	const formatQuantity = (value: number) => {
		return new Intl.NumberFormat().format(value ?? 0);
	};

	const getRequestSourceMeta = (source?: string) => {
		if ((source || "manual") === "repair") {
			return {
				label: "Repair Job",
				className: "border border-purple-200 bg-purple-100 text-purple-700 dark:border-purple-800 dark:bg-purple-900/30 dark:text-purple-300",
			};
		}

		return {
			label: "Manual Entry",
			className: "border border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200",
		};
	};

	const getRequesterRoleLabel = (request: StockRequestApproval) => {
		return (request.request_source || "manual") === "repair" ? "Repairer" : "Staff";
	};

	const shouldShowVariantRequestDetails = (request: StockRequestApproval) => {
		const category = String(request.inventory_item?.category || "").trim().toLowerCase();
		return category !== "repair_materials";
	};

	const getInventoryApprovalMeta = (request: StockRequestApproval) => {
		const fallbackStatus = request.request_source !== "repair"
			? "not_required"
			: request.inventory_approved_date
				? "approved"
				: request.status === "rejected"
					? "rejected"
					: "pending";
		const status = String(request.inventory_approval_status || fallbackStatus).toLowerCase();
		const labels: Record<string, string> = {
			not_required: "Not Required",
			pending: "Pending",
			approved: "Approved",
			rejected: "Rejected",
		};
		const classes: Record<string, string> = {
			not_required: "border border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200",
			pending: "border border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300",
			approved: "border border-green-200 bg-green-100 text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300",
			rejected: "border border-red-200 bg-red-100 text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300",
		};

		return {
			status,
			label: request.inventory_approval_status_label || labels[status] || "Pending",
			className: classes[status] || classes.pending,
		};
	};

	const handleAccept = async (request: StockRequestApproval) => {
		if (ownerMode) return;

		if (request.request_source === "repair" && getInventoryApprovalMeta(request).status !== "approved") {
			await workflowFeedback.warning("Waiting for Inventory", "Repair material requests can be processed after Inventory approval.");
			return;
		}

		if (!["pending", "needs_details"].includes(request.status)) {
			await workflowFeedback.warning("Cannot approve", "Only pending or needs-details requests can be approved.");
			return;
		}

		const result = await workflowFeedback.confirm({
			title: "Approve request?",
			text: `Proceed with supplier sourcing for ${request.product_name}?`,
			confirmButtonText: "Yes, approve",
		});

		if (!result.isConfirmed) return;

		setIsActionProcessing(true);
		try {
			await stockRequestApi.approve(request.id);
			await workflowFeedback.success({
				title: "Approved",
				text: "Request approved and ready for purchase request creation.",
			});
			setViewingRequest(null);
			fetchRequests();
			fetchMetrics();
		} catch (error: any) {
			console.error("Failed to accept request:", error);
			await workflowFeedback.error(error?.response?.data?.message || "Failed to accept request");
		} finally {
			setIsActionProcessing(false);
		}
	};

	const handleReject = async (request: StockRequestApproval) => {
		if (ownerMode) return;

		if (request.request_source === "repair" && getInventoryApprovalMeta(request).status !== "approved") {
			await workflowFeedback.warning("Waiting for Inventory", "Repair material requests can be processed after Inventory approval.");
			return;
		}

		if (!["pending", "needs_details"].includes(request.status)) {
			await workflowFeedback.warning("Cannot reject", "Only pending or needs-details requests can be rejected.");
			return;
		}

		const result = await workflowFeedback.alert({
			title: "Reject request?",
			text: `Reject stock request ${request.request_number}?`,
			input: "textarea",
			inputLabel: "Rejection Reason",
			inputPlaceholder: "Enter reason for rejection...",
			icon: "warning",
			showCancelButton: true,
			confirmButtonText: "Yes, reject",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#dc2626",
			cancelButtonColor: "#6b7280",
			inputValidator: (value: string) => {
				if (!value || !value.trim()) return "Please provide a rejection reason.";
				return undefined;
			},
		});

		if (!result.isConfirmed || !result.value) return;

		setIsActionProcessing(true);
		try {
			await stockRequestApi.reject(request.id, { rejection_reason: result.value });
			await workflowFeedback.success({
				title: "Rejected",
				text: "Request has been rejected.",
				timer: 1500,
				showConfirmButton: false,
			});
			setViewingRequest(null);
			fetchRequests();
			fetchMetrics();
		} catch (error: any) {
			console.error("Failed to reject request:", error);
			await workflowFeedback.error(error?.response?.data?.message || "Failed to reject request");
		} finally {
			setIsActionProcessing(false);
		}
	};

	const isAnyModalOpen = Boolean(viewingRequest);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Stock Replenishment Approval - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Stock Replenishment Approval</h1>
						<p className="text-gray-600 dark:text-gray-400">Review Inventory replenishment requests and decide next sourcing action</p>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests" value={metrics.total} description="All stock requests received" icon={ClipboardIcon} color="info" />
					<MetricCard title="Pending Review" value={metrics.pending} description="Requests awaiting procurement action" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved Requests" value={metrics.accepted} description="Requests ready for supplier sourcing" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Requests Table</h2>
						<p className="text-sm text-gray-500">Track pending stock requests, urgency, and procurement actions</p>
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
						<div className="sm:w-52">
							<select
								title="Filter by status"
								aria-label="Filter by status"
								value={statusFilter}
								onChange={(event) => {
									setStatusFilter(event.target.value as "All" | StockRequestApproval["status"]);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="All">All Status</option>
								<option value="pending">Pending</option>
								<option value="accepted">Approved</option>
								<option value="rejected">Rejected</option>
								<option value="needs_details">Needs Details</option>
							</select>
						</div>
						<div className="sm:w-44">
							<select
								title="Filter by priority"
								aria-label="Filter by priority"
								value={priorityFilter}
								onChange={(event) => {
									setPriorityFilter(event.target.value as "All" | StockRequestApproval["priority"]);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="All">All Priority</option>
								<option value="high">High</option>
								<option value="medium">Medium</option>
								<option value="low">Low</option>
							</select>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Requested by</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Source</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Date</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{loading ? (
									<tr>
										<td colSpan={8} className="px-4 py-10 text-center text-sm text-gray-500">
											Loading...
										</td>
									</tr>
								) : paginatedItems.length > 0 ? (
									paginatedItems.map((request) => (
										<tr key={request.id} className="odd:bg-white even:bg-gray-50/40 hover:bg-blue-50/40 dark:odd:bg-transparent dark:even:bg-gray-800/20 dark:hover:bg-gray-800/40 transition-colors">
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{request.product_name}</p>
											</td>
											<td className="px-4 py-3 text-sm font-medium tabular-nums text-gray-700 dark:text-gray-300">{formatQuantity(request.quantity_needed)}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[request.priority] || ""}`}>
													{formatPriority(request.priority)}
												</span>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<div className="flex flex-col gap-1">
													<span>{request.requester?.name || "—"}</span>
													<span className="text-xs text-gray-500 dark:text-gray-400">{getRequesterRoleLabel(request)}</span>
												</div>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<div className="flex flex-col gap-1">
													<span className={`inline-flex w-fit min-w-24 items-center justify-center whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold leading-none ${getRequestSourceMeta(request.request_source).className}`}>
														{getRequestSourceMeta(request.request_source).label}
													</span>
													{request.repair_request_id && (
														<span className="text-xs text-gray-500 dark:text-gray-400">Repair #{request.repair_request_id}</span>
													)}
													{request.request_source === "repair" && (
														<span className={`inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getInventoryApprovalMeta(request).className}`}>
															Inventory Approval: {getInventoryApprovalMeta(request).label}
														</span>
													)}
												</div>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
												<div className="flex flex-col leading-tight">
													<span className="font-medium tabular-nums">{formatTableDate(request.requested_date)}</span>
													<span className="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{formatTableTime(request.requested_date)}</span>
												</div>
											</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status] || ""}`}>
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
									))
								) : (
									<tr>
										<td colSpan={9} className="px-4 py-10 text-center text-sm text-gray-500">
											No stock requests found for the current filters.
										</td>
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
				<div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
					<button type="button" aria-label="Close request details modal" className="absolute inset-0 bg-black/45 backdrop-blur-[2px]" onClick={() => setViewingRequest(null)} />
					<div className="relative w-full max-w-3xl overflow-hidden rounded-3xl border border-gray-200/90 bg-white shadow-2xl dark:border-gray-800/90 dark:bg-gray-900 max-h-[92vh]">
						<div className="sticky top-0 z-10 border-b border-gray-200 bg-white/95 px-5 py-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 sm:px-6">
							<div className="flex items-start justify-between gap-4">
								<div>
									<p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Request Review</p>
									<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Stock Request Details</h2>
								</div>
								<div className="flex items-center gap-3">
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewingRequest.status] || ""}`}>
										{formatStatus(viewingRequest.status)}
									</span>
									<button
										type="button"
										onClick={() => setViewingRequest(null)}
										className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
										aria-label="Close request details modal"
									>
										<span className="text-lg leading-none">x</span>
									</button>
								</div>
							</div>
						</div>

						<div className="overflow-y-auto max-h-[calc(92vh-154px)] px-5 py-5 sm:px-6 sm:py-6">
							<div className="space-y-5">
								<div className="rounded-2xl border border-gray-200 bg-linear-to-br from-gray-50 to-white p-4 dark:border-gray-800 dark:from-gray-900 dark:to-gray-900/60">
									<p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Product</p>
									<p className="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{viewingRequest.product_name}</p>
								</div>

								<div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
									<div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
										<p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
											{viewingRequest.inventory_item?.category === "shoes" && isAllSizesRequest(viewingRequest.requested_size)
												? "Total Quantity Across All Sizes"
												: "Quantity Needed"}
										</p>
										<p className="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-white">
											{formatQuantity(viewingRequest.quantity_needed)}{viewingRequest.inventory_item?.category === "shoes" && isAllSizesRequest(viewingRequest.requested_size) ? " units" : ""}
										</p>
									</div>
									<div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
										<p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Priority</p>
										<div className="mt-2">
											<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[viewingRequest.priority] || ""}`}>
												{formatPriority(viewingRequest.priority)}
											</span>
										</div>
									</div>
									<div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
										<p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Requested Date</p>
										<p className="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{formatDateTime(viewingRequest.requested_date)}</p>
									</div>
								</div>

								{shouldShowVariantRequestDetails(viewingRequest) && (
									<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
										<div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
											<p className="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Requested Size</p>
											<p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{getRequestedSizeLabel(viewingRequest.requested_size)}</p>
										</div>
										{(viewingRequest as any).requested_color ? (
											<div className="rounded-xl border border-purple-200 bg-purple-50 p-4 dark:border-purple-800 dark:bg-purple-900/20">
												<p className="text-xs font-semibold uppercase tracking-wide text-purple-600 dark:text-purple-400">Requested Color</p>
												<p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{(viewingRequest as any).requested_color}</p>
											</div>
										) : (
											<div className="rounded-xl border border-purple-200 bg-purple-50 p-4 dark:border-purple-800 dark:bg-purple-900/20">
												<p className="text-xs font-semibold uppercase tracking-wide text-purple-600 dark:text-purple-400">Requested Color</p>
												<p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">Not specified</p>
											</div>
										)}
									</div>
								)}

								{isAllSizesRequest(viewingRequest.requested_size) && getAvailableSizeLabelsForRequest(viewingRequest).length > 0 && (
									<div className="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
										<p className="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">
											Available Sizes {viewingRequest.requested_color ? `for ${viewingRequest.requested_color}` : ""}
										</p>
										<p className="mt-1 text-sm font-medium text-gray-900 dark:text-white">
											{getAvailableSizeLabelsForRequest(viewingRequest).join(", ")}
										</p>
									</div>
								)}

								<div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Request Source</p>
									<p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{getRequestSourceMeta(viewingRequest.request_source).label}</p>
									<div className="mt-2 grid grid-cols-1 gap-1 text-sm text-gray-500 dark:text-gray-400 sm:grid-cols-2">
										<p>Requester Role: {getRequesterRoleLabel(viewingRequest)}</p>
										{viewingRequest.repair_request_id ? <p>Repair Request ID: {viewingRequest.repair_request_id}</p> : <p>Repair Request ID: N/A</p>}
									</div>
									{viewingRequest.request_source === "repair" && (
										<div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
											<span>Inventory Approval:</span>
											<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getInventoryApprovalMeta(viewingRequest).className}`}>
												{getInventoryApprovalMeta(viewingRequest).label}
											</span>
											{viewingRequest.inventory_approver?.name && <span>by {viewingRequest.inventory_approver.name}</span>}
										</div>
									)}
								</div>

								{viewingRequest.rejection_reason && (
									<div className="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
										<p className="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">Rejection Reason</p>
										<p className="mt-1 text-sm font-medium whitespace-pre-wrap text-red-900 dark:text-red-300">{viewingRequest.rejection_reason}</p>
									</div>
								)}

								<div className="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
									<p className="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">Inventory Notes</p>
									<p className="mt-1 text-sm font-medium whitespace-pre-wrap text-gray-900 dark:text-white">{viewingRequest.notes || "No notes provided."}</p>
								</div>
							</div>
						</div>

						<div className="sticky bottom-0 border-t border-gray-200 bg-white/95 px-5 py-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 sm:px-6">
							<div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
								<button
									onClick={() => setViewingRequest(null)}
									disabled={isActionProcessing}
									className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 font-medium text-gray-700 transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 sm:w-auto"
								>
									Close
								</button>
								{!ownerMode && <div className="flex w-full gap-3 sm:w-auto">
									<button
										onClick={() => handleReject(viewingRequest)}
										disabled={!(["pending", "needs_details"] as const).includes(viewingRequest.status) || isActionProcessing || (viewingRequest.request_source === "repair" && getInventoryApprovalMeta(viewingRequest).status !== "approved")}
										className="flex-1 rounded-lg bg-red-600 px-4 py-2 font-medium text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-32"
									>
										Reject
									</button>
									<button
										onClick={() => handleAccept(viewingRequest)}
										disabled={!(["pending", "needs_details"] as const).includes(viewingRequest.status) || isActionProcessing || (viewingRequest.request_source === "repair" && getInventoryApprovalMeta(viewingRequest).status !== "approved")}
										className="flex-1 rounded-lg bg-green-600 px-4 py-2 font-medium text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-32"
									>
										Approve
									</button>
								</div>}
							</div>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
