import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { stockRequestApi } from "@/services/stockRequestApi";
import { inventoryItemAPI } from "@/services/inventoryAPI";
import { workflowFeedback } from "@/utils/workflowFeedback";
import { clearModalDraft, loadModalDraft, saveModalDraft, scopedModalDraftKey } from "@/utils/modalDraft";
import type { InventoryItem } from "@/types/inventory";
import type { StockRequestApproval } from "@/types/procurement";

type DisplayStatus = "Pending" | "Approved" | "Rejected" | "Needs Details";
type MetricColor = "success" | "warning" | "info";
const SIZE_SYSTEMS = ["US", "UK", "EU", "AU", "CN"] as const;

interface RequestFormState {
	inventoryItemId: string;
	requestSize: string;
	requestColor: string;
	quantityNeeded: string;
	priority: "high" | "medium" | "low";
	notes: string;
}

const initialFormState: RequestFormState = {
	inventoryItemId: "",
	requestSize: "",
	requestColor: "",
	quantityNeeded: "",
	priority: "medium",
	notes: "",
};

const STOCK_REQUEST_DRAFT_KEY = "erp.stock-request.create-modal.draft";

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

function hasSizeSystemPrefix(value: string): boolean {
	const normalized = value.trim().toUpperCase();
	return SIZE_SYSTEMS.some((system) => normalized.startsWith(`${system} `));
}

function formatRequestedSizeLabel(size: string, sizeSystem?: string): string {
	const rawSize = (size ?? "").trim();
	if (!rawSize) return "";
	if (hasSizeSystemPrefix(rawSize)) return rawSize;

	const normalizedSystem = (sizeSystem ?? "").trim().toUpperCase();
	if (SIZE_SYSTEMS.includes(normalizedSystem as typeof SIZE_SYSTEMS[number])) {
		return `${normalizedSystem} ${rawSize}`;
	}

	return rawSize;
}

function formatRequestedSizeDisplay(value: string): string {
	const trimmed = (value ?? "").trim();
	if (!trimmed) return "";
	if (hasSizeSystemPrefix(trimmed)) return trimmed;
	return `Size ${trimmed}`;
}

function getRequestedSizeLabel(value?: string | null): string {
	const trimmed = (value ?? "").trim();
	if (!trimmed) return "All Sizes";

	const normalized = trimmed.toLowerCase().replace(/[\s-]+/g, "_");
	if (["all", "all_sizes", "all_size", "any"].includes(normalized)) {
		return "All Sizes";
	}

	return formatRequestedSizeDisplay(trimmed);
}

function shouldShowRequestedSizeBlock(request: StockRequestApproval): boolean {
	const hasExplicitSize = (request.requested_size ?? "").trim() !== "";
	if (hasExplicitSize) return true;

	const hasRequestedColor = (request.requested_color ?? "").trim() !== "";
	if (hasRequestedColor) return true;

	return request.inventory_item?.category === "shoes";
}

function isAllSizesRequest(value?: string | null): boolean {
	const normalized = (value ?? "").trim().toLowerCase().replace(/[\s-]+/g, "_");
	if (!normalized) return true;
	return ["all", "all_sizes", "all_size", "any"].includes(normalized);
}

function formatDatetime(dateString: string): string {
	try {
		const date = new Date(dateString);
		if (Number.isNaN(date.getTime())) return dateString;
		return date.toLocaleString('en-US', {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			hour12: true,
		});
	} catch {
		return dateString;
	}
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
		const { auth, initialRequests, initialInventoryItems } = usePage().props as any;
		const draftKey = scopedModalDraftKey(STOCK_REQUEST_DRAFT_KEY, auth?.user?.shop_owner_id, auth?.user?.id);
	const [requests, setRequests] = useState<StockRequestApproval[]>(initialRequests?.data ?? []);
	const [inventoryItems, setInventoryItems] = useState<InventoryItem[]>(initialInventoryItems?.data ?? []);
	const [isLoading, setIsLoading] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState<"All" | StockRequestApproval["status"]>("All");
	const [priorityFilter, setPriorityFilter] = useState<"All" | "low" | "medium" | "high">("All");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [isSubmittingCreateRequest, setIsSubmittingCreateRequest] = useState(false);
	const [formData, setFormData] = useState<RequestFormState>(initialFormState);
	const [viewingRequest, setViewingRequest] = useState<StockRequestApproval | null>(null);
	useEffect(() => {
		const id = Number(new URLSearchParams(window.location.search).get("stock_request"));
		if (id > 0) setViewingRequest(requests.find((request) => request.id === id) ?? null);
	}, [requests]);
	const [isProductPickerOpen, setIsProductPickerOpen] = useState(false);
	const [productPickerSearch, setProductPickerSearch] = useState("");
	const isCreateFormDirty = useMemo(
		() =>
			formData.inventoryItemId !== initialFormState.inventoryItemId ||
			formData.requestSize !== initialFormState.requestSize ||
			formData.requestColor !== initialFormState.requestColor ||
			formData.quantityNeeded.trim() !== initialFormState.quantityNeeded ||
			formData.priority !== initialFormState.priority ||
			formData.notes.trim() !== initialFormState.notes,
		[formData],
	);

	useEffect(() => {
		if (!isCreateModalOpen) return;
		if (isCreateFormDirty) {
				saveModalDraft(draftKey, formData);
			return;
		}
			clearModalDraft(draftKey);
		}, [draftKey, formData, isCreateFormDirty, isCreateModalOpen]);

		useEffect(() => {
			clearModalDraft(STOCK_REQUEST_DRAFT_KEY);
		}, []);

	useEffect(() => {
		if (!isCreateModalOpen || !isCreateFormDirty) return;

		const handleBeforeUnload = (event: BeforeUnloadEvent) => {
			event.preventDefault();
			event.returnValue = "";
		};

		window.addEventListener("beforeunload", handleBeforeUnload);
		return () => window.removeEventListener("beforeunload", handleBeforeUnload);
	}, [isCreateFormDirty, isCreateModalOpen]);

	// Derive selected inventory item for stock preview
	const selectedItem = useMemo(
		() => inventoryItems.find((i) => String(i.id) === formData.inventoryItemId) ?? null,
		[inventoryItems, formData.inventoryItemId],
	);

	const hasColorVariants = useMemo(() => (selectedItem?.color_variants?.length ?? 0) > 0, [selectedItem]);
	const allowColorSelection = useMemo(() => {
		if (!selectedItem) return false;
		if (selectedItem.category === "repair_materials") return false;
		return hasColorVariants;
	}, [hasColorVariants, selectedItem]);

	const colorOptionsAll = useMemo(() => {
		if (!selectedItem) return [] as Array<{ colorName: string; quantity: number }>;

		const options: Array<{ colorName: string; quantity: number }> = [];
		for (const variant of selectedItem.color_variants ?? []) {
			let sizeQty = (variant.sizes ?? []).reduce((total: number, sizeOption: any) => total + Number(sizeOption.quantity ?? 0), 0);

			if (sizeQty <= 0) {
				sizeQty = (selectedItem.sizes ?? [])
					.filter((sizeOption: any) => Number(sizeOption.inventory_color_variant_id) === Number(variant.id))
					.reduce((total: number, sizeOption: any) => total + Number(sizeOption.quantity ?? 0), 0);
			}

			const qty = sizeQty > 0 ? sizeQty : Number(variant.quantity ?? 0);
			options.push({ colorName: String(variant.color_name), quantity: qty });
		}

		return options;
	}, [selectedItem]);

	const sizeOptionsForSelectedColor = useMemo(() => {
		if (!selectedItem) return [] as Array<{ label: string; quantity: number }>;
		if (!formData.requestColor) return [] as Array<{ label: string; quantity: number }>;

		const matchedVariant = (selectedItem.color_variants ?? []).find(
			(variant: any) => String(variant.color_name).toLowerCase() === formData.requestColor.toLowerCase(),
		);

		if (!matchedVariant) return [] as Array<{ label: string; quantity: number }>;

		const optionMap = new Map<string, number>();

		const scopedSizeRows = (matchedVariant.sizes ?? []).length > 0
			? (matchedVariant.sizes ?? [])
			: (selectedItem.sizes ?? []).filter((sizeOption: any) => Number(sizeOption.inventory_color_variant_id) === Number(matchedVariant.id));

		for (const sizeOption of scopedSizeRows) {
			const label = formatRequestedSizeLabel(String(sizeOption.size), sizeOption.size_system);
			const currentQty = optionMap.get(label) ?? 0;
			optionMap.set(label, currentQty + Number(sizeOption.quantity ?? 0));
		}

		return Array.from(optionMap.entries()).map(([label, quantity]) => ({ label, quantity }));
	}, [selectedItem, formData.requestColor]);

	const selectedColorOption = useMemo(() => {
		if (!formData.requestColor) return null;
		return colorOptionsAll.find((option) => option.colorName === formData.requestColor) ?? null;
	}, [colorOptionsAll, formData.requestColor]);

	const selectedSizeOption = useMemo(() => {
		if (!formData.requestSize) return null;
		return sizeOptionsForSelectedColor.find((option) => option.label === formData.requestSize) ?? null;
	}, [formData.requestSize, sizeOptionsForSelectedColor]);

	const getEffectiveQuantityNeeded = (request: StockRequestApproval): number => {
		return Number(request.quantity_needed ?? 0);
	};

	useEffect(() => {
		if (!formData.requestColor) return;
		const stillExists = colorOptionsAll.some((option) => option.colorName === formData.requestColor);
		if (!stillExists) {
			setFormData((prev) => ({ ...prev, requestColor: "", requestSize: "" }));
		}
	}, [colorOptionsAll, formData.requestColor]);

	useEffect(() => {
		if (allowColorSelection) return;
		if (!formData.requestColor) return;
		setFormData((prev) => ({ ...prev, requestColor: "" }));
	}, [allowColorSelection, formData.requestColor]);

	useEffect(() => {
		if (!formData.requestSize) return;
		const stillExists = sizeOptionsForSelectedColor.some((option) => option.label === formData.requestSize);
		if (!stillExists) {
			setFormData((prev) => ({ ...prev, requestSize: "" }));
		}
	}, [formData.requestSize, sizeOptionsForSelectedColor]);

	const refreshData = async () => {
		setIsLoading(true);
		try {
			const [requestsRes, itemsRes] = await Promise.all([
				stockRequestApi.getAllForInventory({ per_page: 200 }),
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

	useEffect(() => {
		void refreshData();
	}, []);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		let filtered = requests;

		// Apply status filter
		if (statusFilter !== "All") {
			filtered = filtered.filter((r) => r.status === statusFilter);
		}

		// Apply priority filter
		if (priorityFilter !== "All") {
			filtered = filtered.filter((r) => r.priority === priorityFilter);
		}

		// Apply search filter
		if (query) {
			filtered = filtered.filter((r) =>
				r.product_name.toLowerCase().includes(query) ||
				r.sku_code.toLowerCase().includes(query),
			);
		}

		return filtered;
	}, [searchQuery, statusFilter, priorityFilter, requests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalRequests  = requests.length;
	const pendingRequests  = requests.filter((r) => r.status === "pending").length;
	const approvedRequests = requests.filter((r) => r.status === "accepted").length;

	const handleCreateRequest = async () => {
		if (isSubmittingCreateRequest) return;

		if (!formData.inventoryItemId || !formData.quantityNeeded.trim() || !formData.notes.trim()) {
			await workflowFeedback.warning(
				"Missing fields",
				"Please select a product, enter the quantity needed, and add a note.",
			);
			return;
		}

		const parsedQty = Number(formData.quantityNeeded);
		if (Number.isNaN(parsedQty) || parsedQty <= 0) {
			await workflowFeedback.warning("Invalid quantity", "Quantity needed must be greater than 0.");
			return;
		}

		if (allowColorSelection && !formData.requestColor) {
			await workflowFeedback.warning(
				"Missing color",
				"Please select a color for the chosen size so replenishment does not mix same-size stocks across colors.",
			);
			return;
		}

		setIsSubmittingCreateRequest(true);
		try {
			const createdRequest = await stockRequestApi.createFromInventory({
				inventory_item_id: Number(formData.inventoryItemId),
				quantity_needed:   parsedQty,
				priority:          formData.priority,
				requested_size:    formData.requestSize || undefined,
				requested_color:   formData.requestColor || undefined,
				notes:             formData.notes || undefined,
			});

			setRequests((prev) => [
				createdRequest,
				...prev.filter((request) => request.id !== createdRequest.id),
			]);
			void refreshData();
			clearModalDraft(draftKey);
			setFormData(initialFormState);
			setIsCreateModalOpen(false);
			setCurrentPage(1);

			await workflowFeedback.success({
				title: "Request submitted",
				text: "Stock replenishment request has been submitted to Procurement.",
			});
		} catch {
			await workflowFeedback.error("Could not submit the stock request. Please try again.", "Submission failed");
		} finally {
			setIsSubmittingCreateRequest(false);
		}
	};

	const handleOpenCreateModal = async () => {
			const savedDraft = loadModalDraft<Partial<RequestFormState>>(draftKey);

		if (!savedDraft) {
			setFormData(initialFormState);
			setIsCreateModalOpen(true);
			return;
		}

		const shouldRestore = await workflowFeedback.confirm({
			title: "Restore draft?",
			text: "A saved stock request draft was found. Restore it before continuing?",
			confirmButtonText: "Restore draft",
			cancelButtonText: "Start fresh",
		});

		if (shouldRestore.isConfirmed) {
			setFormData({ ...initialFormState, ...savedDraft });
		} else {
				clearModalDraft(draftKey);
			setFormData(initialFormState);
		}

		setIsCreateModalOpen(true);
	};

	const requestCloseCreateModal = async () => {
		if (isSubmittingCreateRequest) return;

		if (!isCreateFormDirty) {
			setIsCreateModalOpen(false);
			setFormData(initialFormState);
				clearModalDraft(draftKey);
			return;
		}

		const confirmClose = await workflowFeedback.confirm({
			title: "Close with unsaved changes?",
			text: "Your changes are unsaved. They will be kept as a draft for next time.",
			confirmButtonText: "Close and keep draft",
			cancelButtonText: "Keep editing",
		});

		if (!confirmClose.isConfirmed) return;

			saveModalDraft(draftKey, formData);
		setIsCreateModalOpen(false);
		setFormData(initialFormState);
	};

	const handleOpenProductPicker = () => {
		setIsCreateModalOpen(false);
		setIsProductPickerOpen(true);
		setViewingRequest(null);
	};

	const handleOpenViewRequest = (request: StockRequestApproval) => {
		setIsCreateModalOpen(false);
		setIsProductPickerOpen(false);
		setViewingRequest(request);
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingRequest) || isProductPickerOpen;

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Stock Replenishment Request - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Stock Replenishment Request</h1>
						<p className="text-gray-600 dark:text-gray-400">Create and track replenishment requests to Procurement for low or out-of-stock items</p>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<button
							onClick={() => {
								void handleOpenCreateModal();
							}}
							className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
						>
							+ New Request
						</button>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests"    value={totalRequests}   description="All replenishment requests created"     icon={ClipboardIcon}  color="info" />
					<MetricCard title="Pending Approval"  value={pendingRequests}  description="Requests currently waiting review"    icon={ClockIcon}      color="warning" />
					<MetricCard title="Approved Requests" value={approvedRequests} description="Requests approved for procurement"    icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Replenishment Request Table</h2>
						<p className="text-sm text-gray-500">View replenishment request status and track updates from Procurement</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
							placeholder="Search by product or SKU..."
							value={searchQuery}
							onChange={(event) => {
								setSearchQuery(event.target.value);
								setCurrentPage(1);
							}}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						/>
					</div>
					<div className="sm:w-56">
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
					<div className="sm:w-56">
						<select
							title="Filter by priority"
							aria-label="Filter by priority"
							value={priorityFilter}
							onChange={(event) => {
								setPriorityFilter(event.target.value as "All" | "low" | "medium" | "high");
								setCurrentPage(1);
							}}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						>
							<option value="All">All Priorities</option>
							<option value="low">Low</option>
							<option value="medium">Medium</option>
							<option value="high">High</option>
						</select>
					</div>
				</div>

				<div className="overflow-x-auto">
					{isLoading ? (
						<div className="py-10 text-center text-sm text-gray-500">Loading requests…</div>
					) : (
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
								<thead className="bg-gray-50 dark:bg-gray-800/50">
									<tr>
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
													<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
														<p className="font-medium text-gray-900 dark:text-white">{request.product_name}</p>
													</td>
													<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{getEffectiveQuantityNeeded(request)}</td>
													<td className="px-4 py-3">
														<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[displayPriority] ?? ""}`}>
															{displayPriority}
														</span>
													</td>
													<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
													{formatDatetime(request.requested_date)}
													</td>
													<td className="px-4 py-3">
														<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[displayStatus]}`}>
															{displayStatus}
														</span>
													</td>
													<td className="px-4 py-3 text-center">
														<button
															onClick={() => handleOpenViewRequest(request)}
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
											<td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">No stock requests found.</td>
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
						disabled={isSubmittingCreateRequest}
						className="absolute inset-0 bg-black/50 disabled:cursor-not-allowed"
						onClick={() => {
							void requestCloseCreateModal();
						}}
					/>
				<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Create Stock Request</h2>
							<button
								onClick={() => {
									void requestCloseCreateModal();
								}}
								disabled={isSubmittingCreateRequest}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none disabled:opacity-50 disabled:cursor-not-allowed"
							>
								×
							</button>
						</div>

						<div className="p-6 space-y-4">
							{/* Inventory item dropdown */}
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Product (Inventory Item) *
								</label>
								<button
									type="button"
								onClick={() => handleOpenProductPicker()}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 text-left hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors flex items-center justify-between"
								>
									<span>
										{formData.inventoryItemId
											? inventoryItems.find((i) => String(i.id) === formData.inventoryItemId)?.name || "Select a product"
											: "— Select a product —"}
									</span>
									<svg className="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
									</svg>
								</button>

								{/* Current stock info card */}
								{selectedItem && (
									<div className="mt-2 flex items-center gap-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 px-3 py-2 text-sm">
										<svg className="h-4 w-4 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
											<circle cx="12" cy="12" r="9" />
											<path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4m0 4h.01" />
										</svg>
										<span className="text-blue-700 dark:text-blue-300">
											Current stock: <strong>{selectedItem.available_quantity ?? 0} {selectedItem.unit ?? "pcs"}</strong>

										</span>
									</div>
								)}
							</div>

						{selectedItem && allowColorSelection && (
							<div className="mt-3">
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Color *</label>
								<select
									title="Select color"
									aria-label="Select color"
									value={formData.requestColor}
									onChange={(event) => setFormData((prev) => ({ ...prev, requestColor: event.target.value, requestSize: "" }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">— Select color —</option>
									{colorOptionsAll.map((colorOption) => (
										<option key={colorOption.colorName} value={colorOption.colorName}>
											{colorOption.colorName} — {colorOption.quantity} in stock
										</option>
									))}
								</select>
								<p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Select color first, then choose from sizes available under that color.</p>
							</div>
						)}

						{selectedItem && allowColorSelection && formData.requestColor && sizeOptionsForSelectedColor.length > 0 && (
							<div className="mt-3">
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Specific Size <span className="text-gray-400 font-normal">(optional — leave blank to request all sizes for this color)</span>
								</label>
								<select
									title="Select specific size"
									aria-label="Select specific size"
									value={formData.requestSize}
									onChange={(event) => setFormData((prev) => ({ ...prev, requestSize: event.target.value }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">— All sizes for selected color —</option>
									{sizeOptionsForSelectedColor.map((sizeOption) => (
										<option key={sizeOption.label} value={sizeOption.label}>
											{formatRequestedSizeDisplay(sizeOption.label)} — {sizeOption.quantity} in stock
										</option>
									))}
								</select>
							</div>
						)}

						{selectedItem && !allowColorSelection && selectedItem.sizes && selectedItem.sizes.length > 0 && (
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
									{selectedItem.sizes.map((sizeOption: any) => {
										const label = formatRequestedSizeLabel(String(sizeOption.size), sizeOption.size_system);
										return (
											<option key={label} value={label}>
												{formatRequestedSizeDisplay(label)} — {sizeOption.quantity} in stock
											</option>
										);
									})}
								</select>
							</div>
						)}

						{selectedColorOption && (
							<div className="mt-2 flex items-center gap-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-sm">
								<svg className="h-4 w-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
									<circle cx="12" cy="12" r="9" />
									<path strokeLinecap="round" strokeLinejoin="round" d="M8 12h8" />
								</svg>
								<span className="text-emerald-700 dark:text-emerald-300">
									Variant stock: <strong>{selectedColorOption.colorName}{formData.requestSize ? ` / ${formatRequestedSizeDisplay(formData.requestSize)}` : ""} = {formData.requestSize ? (selectedSizeOption?.quantity ?? 0) : selectedColorOption.quantity} {selectedItem?.unit ?? "pcs"}</strong>
								</span>
							</div>
						)}

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
								onClick={() => {
									void requestCloseCreateModal();
								}}
								disabled={isSubmittingCreateRequest}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
							>
								Cancel
							</button>
							<button
								onClick={handleCreateRequest}
								disabled={isSubmittingCreateRequest}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors disabled:bg-blue-400 disabled:cursor-not-allowed"
							>
								{isSubmittingCreateRequest ? "Submitting..." : "Submit Request"}
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
							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[getDisplayStatus(viewingRequest.status)]}`}>
									{getDisplayStatus(viewingRequest.status)}
								</span>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.product_name}</p>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity Needed</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{getEffectiveQuantityNeeded(viewingRequest)}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Priority</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[getDisplayPriority(viewingRequest.priority)] ?? ""}`}>
										{getDisplayPriority(viewingRequest.priority)}
									</span>
								</div>
							</div>

							{shouldShowRequestedSizeBlock(viewingRequest) && (
								<div className="rounded-xl bg-indigo-50 dark:bg-indigo-900/20 p-4 border border-indigo-200 dark:border-indigo-800">
									<p className="text-sm font-medium text-indigo-600 dark:text-indigo-400 mb-1">Requested Size</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{getRequestedSizeLabel(viewingRequest.requested_size)}</p>
								</div>
							)}

							{viewingRequest.requested_color && (
								<div className="rounded-xl bg-purple-50 dark:bg-purple-900/20 p-4 border border-purple-200 dark:border-purple-800">
									<p className="text-sm font-medium text-purple-600 dark:text-purple-400 mb-1">Requested Color</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.requested_color}</p>
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

			{/* ── Product Picker Modal ── */}
			{isProductPickerOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button
						type="button"
						aria-label="Close product picker modal"
						disabled={isSubmittingCreateRequest}
						className="absolute inset-0 bg-black/50 disabled:cursor-not-allowed"
					onClick={() => { setIsProductPickerOpen(false); setIsCreateModalOpen(true); }}
				/>
				<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[80vh] overflow-hidden flex flex-col">
					{/* Header */}
					<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
						<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Select Product</h2>
						<button
							onClick={() => { setIsProductPickerOpen(false); setIsCreateModalOpen(true); }}
							className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
						>
							×
						</button>
					</div>

					{/* Search */}
					<div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800">
							<input
								type="text"
								placeholder="Search by product name..."
								value={productPickerSearch}
								onChange={(e) => setProductPickerSearch(e.target.value)}
								autoFocus
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>

						{/* Product List */}
						<div className="overflow-y-auto flex-1">
							{(() => {
								const filtered = inventoryItems.filter((item) =>
									item.name.toLowerCase().includes(productPickerSearch.toLowerCase())
								);

								if (filtered.length === 0) {
									return (
										<div className="p-8 text-center text-gray-500 dark:text-gray-400">
											<p className="text-sm">No products found matching "{productPickerSearch}"</p>
										</div>
									);
								}

								return (
									<div className="divide-y divide-gray-200 dark:divide-gray-800">
										{filtered.map((item) => (
											<button
												key={item.id}
												type="button"
												onClick={() => {
													setFormData((prev) => ({
														...prev,
														inventoryItemId: String(item.id),
														requestSize: "",
														requestColor: "",
													}));
													setIsProductPickerOpen(false);
													setIsCreateModalOpen(true);
													setProductPickerSearch("");
												}}
												className="w-full px-6 py-4 hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-all duration-200 hover:shadow-md hover:scale-[1.01] text-left transform"
											>
												<div className="flex items-start justify-between">
													<div className="flex-1">
														<p className="text-base font-semibold text-gray-900 dark:text-white">{item.name}</p>
														<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
															{item.available_quantity ?? 0} {item.unit ?? "pcs"} available
														</p>
													</div>
													<div className="ml-4 shrink-0">
														<div className="inline-flex items-center px-3 py-1 rounded-full bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
															<span className="text-xs font-semibold text-blue-700 dark:text-blue-300">
																{item.available_quantity ?? 0} {item.unit ?? "pcs"}
															</span>
														</div>
													</div>
												</div>
											</button>
										))}
									</div>
								);
							})()}
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
