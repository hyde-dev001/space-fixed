import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState, useEffect, useRef } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import { purchaseRequestApi } from "@/services/purchaseRequestApi";
import type { PurchaseOrder as PurchaseOrderType, PurchaseRequest } from "@/types/procurement";
import { hasPermission } from "@/utils/permissions";
import PurchaseOrderReceiptPanel from "./components/PurchaseOrderReceiptPanel";

type PurchaseOrderStatus = "draft" | "sent" | "confirmed" | "in_transit" | "partially_received" | "delivered" | "completed" | "cancelled";
type MetricColor = "success" | "warning" | "info";
const SIZE_SYSTEMS = ["US", "UK", "EU", "AU", "CN"] as const;

interface PurchaseOrderFormState {
	selectedPrId: number | null;
	additionalPrIds: number[];
	expectedDeliveryDate: string;
	paymentTerms: string;
	notes: string;
}

const formatStatus = (status: string): string => {
	const statusMap: Record<string, string> = {
		draft: "Draft",
		sent: "Sent",
		confirmed: "Confirmed",
		in_transit: "In Transit",
		partially_received: "Partially Received",
		delivered: "Delivered",
		completed: "Completed",
		cancelled: "Cancelled"
	};
	return statusMap[status] || status;
};

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

const isSizeBasedCategory = (category?: string | null): boolean => {
	const normalized = (category ?? "").trim().toLowerCase();
	return normalized === "shoes";
};

const isAllSizesRequest = (requestedSize?: string | null, category?: string | null): boolean => {
	if (!isSizeBasedCategory(category)) return false;

	const trimmed = (requestedSize ?? "").trim();
	if (!trimmed) return true;

	const normalized = trimmed.toLowerCase().replace(/[\s-]+/g, "_");
	return ["all", "all_sizes", "all_size", "any"].includes(normalized);
};

const getEffectiveQuantity = (
	quantity: number,
	_unitCost?: number,
	_totalCost?: number,
	_isAllSizes?: boolean,
): number => quantity;

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

const getAvailableSizeLabels = (
	inventoryItem: any,
	requestedColor?: string | null,
	requestedSize?: string | null,
): string[] => {
	if (!isAllSizesRequest(requestedSize, inventoryItem?.category)) return [];

	const allSizes = Array.isArray(inventoryItem?.sizes) ? inventoryItem.sizes : [];
	if (!allSizes.length) return [];

	const requestedColorNormalized = (requestedColor ?? "").trim().toLowerCase();
	const colorVariants = Array.isArray(inventoryItem?.color_variants) ? inventoryItem.color_variants : [];

	if (requestedColorNormalized) {
		const matchedVariant = colorVariants.find(
			(variant: any) => String(variant?.color_name ?? "").trim().toLowerCase() === requestedColorNormalized,
		);

		if (matchedVariant) {
			const variantSizes = Array.isArray(matchedVariant.sizes) ? matchedVariant.sizes : [];
			if (variantSizes.length) {
				return Array.from(new Set(
					variantSizes
						.map((sizeRow: any) => formatSizeRowLabel(sizeRow?.size, sizeRow?.size_system))
						.filter((label: string) => label.length > 0)
				));
			}

			const scopedByVariantId = allSizes.filter(
				(sizeRow: any) => Number(sizeRow?.inventory_color_variant_id) === Number(matchedVariant.id),
			);

			if (scopedByVariantId.length) {
				return Array.from(new Set(
					scopedByVariantId
						.map((sizeRow: any) => formatSizeRowLabel(sizeRow?.size, sizeRow?.size_system))
						.filter((label: string) => label.length > 0)
				));
			}
		}
	}

	return Array.from(new Set(
		allSizes
			.map((sizeRow: any) => formatSizeRowLabel(sizeRow?.size, sizeRow?.size_system))
			.filter((label: string) => label.length > 0)
	));
};

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const initialFormState: PurchaseOrderFormState = {
	selectedPrId: null,
	additionalPrIds: [],
	expectedDeliveryDate: "",
	paymentTerms: "Net 30",
	notes: "",
};

const statusBadgeClass: Record<string, string> = {
	draft: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	sent: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	confirmed: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	in_transit: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	partially_received: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
	delivered: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
	completed: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	cancelled: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
};

const ClipboardIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
		<rect x="9" y="3" width="6" height="4" rx="1" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6M9 16h4" />
	</svg>
);

const TruckIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 17h6m-6 0a2 2 0 11-4 0m4 0a2 2 0 104 0m0 0h2a2 2 0 002-2v-3l-3-3h-3V7a2 2 0 00-2-2H3" />
	</svg>
);

const CheckCircleIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<circle cx="12" cy="12" r="9" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4" />
	</svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
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

const CalendarIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<rect x="3" y="5" width="18" height="16" rx="2" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M16 3v4M8 3v4M3 10h18" />
	</svg>
);

const WEEKDAY_LABELS = ["SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT"];

const normalizeDate = (value: Date): Date => {
	const normalized = new Date(value);
	normalized.setHours(0, 0, 0, 0);
	return normalized;
};

const toDateInputValue = (value: Date): string => {
	const year = value.getFullYear();
	const month = String(value.getMonth() + 1).padStart(2, "0");
	const day = String(value.getDate()).padStart(2, "0");
	return `${year}-${month}-${day}`;
};

const toDisplayDate = (dateValue: string): string => {
	if (!dateValue) return "";
	const parsed = new Date(`${dateValue}T00:00:00`);
	if (Number.isNaN(parsed.getTime())) return "";
	return parsed.toLocaleDateString("en-US", {
		month: "2-digit",
		day: "2-digit",
		year: "numeric",
	});
};

const normalizeApiDateString = (value: string): string => {
	const trimmed = value.trim();
	if (!trimmed) return trimmed;

	let normalized = trimmed.includes("T") ? trimmed : `${trimmed}T00:00:00`;
	normalized = normalized.replace(" ", "T");

	// Some backend timestamps include 6-digit microseconds (e.g. .000000Z),
	// while JS Date expects milliseconds. Normalize to 3-digit ms.
	normalized = normalized.replace(/\.(\d{1,2})(?=(Z|[+-]\d{2}:\d{2})?$)/, (_, fraction: string) => `.${fraction.padEnd(3, "0")}`);
	normalized = normalized.replace(/\.(\d{3})\d+(?=(Z|[+-]\d{2}:\d{2})?$)/, ".$1");

	return normalized;
};

const formatReadableDate = (
	dateValue?: string | null,
	{ withTime = false }: { withTime?: boolean } = {},
): string => {
	if (!dateValue) return "—";
	const normalized = normalizeApiDateString(dateValue);
	const parsed = new Date(normalized);
	if (Number.isNaN(parsed.getTime())) return dateValue;

	return new Intl.DateTimeFormat("en-PH", withTime
		? {
			month: "short",
			day: "numeric",
			year: "numeric",
			hour: "numeric",
			minute: "2-digit",
		}
		: {
			month: "short",
			day: "numeric",
			year: "numeric",
		},
	).format(parsed);
};

const formatRelativeDate = (dateValue?: string | null): string | null => {
	if (!dateValue) return null;
	const normalized = normalizeApiDateString(dateValue);
	const parsed = new Date(normalized);
	if (Number.isNaN(parsed.getTime())) return null;

	const target = normalizeDate(parsed);
	const current = normalizeDate(new Date());
	const diffInDays = Math.round((target.getTime() - current.getTime()) / (1000 * 60 * 60 * 24));

	if (diffInDays === 0) return "Today";
	if (diffInDays > 0) return `In ${diffInDays} day${diffInDays === 1 ? "" : "s"}`;
	const daysAgo = Math.abs(diffInDays);
	return `${daysAgo} day${daysAgo === 1 ? "" : "s"} ago`;
};

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

const nextStatusMap: Partial<Record<PurchaseOrderStatus, PurchaseOrderStatus>> = {
	draft: "sent",
	sent: "confirmed",
	confirmed: "in_transit",
	delivered: "completed",
};

export default function PurchaseOrders() {
	const { initialData, initialApprovedPRs, auth } = usePage().props as any;
	const canCreate = hasPermission(auth, "procurement.create_purchase_orders");
	const canManage = hasPermission(auth, "procurement.manage_purchase_orders");
	const canComplete = hasPermission(auth, "procurement.complete_purchase_orders");
	const canCancel = hasPermission(auth, "procurement.cancel_purchase_orders");
	const canVoid = hasPermission(auth, "procurement.void_purchase_order_receipts");
	const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrderType[]>(initialData?.data ?? []);
	const [approvedPRs, setApprovedPRs] = useState<PurchaseRequest[]>(initialApprovedPRs ?? []);
	const [loading, setLoading] = useState(false);
	const [metrics, setMetrics] = useState({ total_purchase_orders: 0, active_orders: 0, completed_orders: 0 });
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState<"all" | PurchaseOrderStatus>("all");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [isCreatingPO, setIsCreatingPO] = useState(false);
	const [viewingOrder, setViewingOrder] = useState<PurchaseOrderType | null>(null);
	const [formData, setFormData] = useState<PurchaseOrderFormState>(initialFormState);
	const [isDeliveryCalendarOpen, setIsDeliveryCalendarOpen] = useState(false);
	const [deliveryCalendarMonth, setDeliveryCalendarMonth] = useState(() => {
		const now = new Date();
		return new Date(now.getFullYear(), now.getMonth(), 1);
	});
	const deliveryCalendarRef = useRef<HTMLDivElement | null>(null);
	const today = useMemo(() => normalizeDate(new Date()), []);

	const fetchPurchaseOrders = async () => {
		try {
			setLoading(true);
			const response = await purchaseOrderApi.getAll();
			setPurchaseOrders(response.data);
		} catch (error) {
			console.error("Failed to fetch purchase orders:", error);
			Swal.fire("Error", "Failed to load purchase orders", "error");
		} finally {
			setLoading(false);
		}
	};

	const fetchApprovedPRs = async () => {
		try {
			const response = await purchaseRequestApi.getApproved();
			setApprovedPRs(response);
		} catch (error) {
			console.error("Failed to fetch approved PRs:", error);
		}
	};

	const fetchMetrics = async () => {
		try {
			const data = await purchaseOrderApi.getMetrics();
			setMetrics(data);
		} catch (error) {
			console.error("Failed to fetch metrics:", error);
		}
	};

	const upsertOrderInState = (updatedOrder: PurchaseOrderType) => {
		setPurchaseOrders((prev) => prev.map((order) => (order.id === updatedOrder.id ? { ...order, ...updatedOrder } : order)));
		setViewingOrder((prev: PurchaseOrderType | null) => (prev && prev.id === updatedOrder.id ? { ...prev, ...updatedOrder } : prev));
	};

	useEffect(() => {
		fetchPurchaseOrders();
		fetchApprovedPRs();
		fetchMetrics();
	}, []);

	useEffect(() => {
		if (!isDeliveryCalendarOpen) return;

		const handleOutsideClick = (event: MouseEvent) => {
			const target = event.target as Node;
			if (deliveryCalendarRef.current && !deliveryCalendarRef.current.contains(target)) {
				setIsDeliveryCalendarOpen(false);
			}
		};

		document.addEventListener("mousedown", handleOutsideClick);
		return () => {
			document.removeEventListener("mousedown", handleOutsideClick);
		};
	}, [isDeliveryCalendarOpen]);

	const selectedPrOption = useMemo(
		() => approvedPRs.find((item) => item.id === formData.selectedPrId) ?? null,
		[formData.selectedPrId, approvedPRs]
	);
	const sameSupplierPrs = useMemo(() => selectedPrOption
		? approvedPRs.filter((item) => item.id !== selectedPrOption.id && item.supplier_id === selectedPrOption.supplier_id)
		: [], [approvedPRs, selectedPrOption]);

	const selectedPrEffectiveQuantity = useMemo(() => {
		if (!selectedPrOption) return null;

		return getEffectiveQuantity(
			selectedPrOption.quantity,
			selectedPrOption.unit_cost,
			selectedPrOption.total_cost,
			isAllSizesRequest(selectedPrOption.requested_size, selectedPrOption.inventory_item?.category),
		);
	}, [selectedPrOption]);

	const selectedPrAvailableSizeLabels = useMemo(() => {
		if (!selectedPrOption) return [];

		return getAvailableSizeLabels(
			selectedPrOption.inventory_item,
			selectedPrOption.requested_color,
			selectedPrOption.requested_size,
		);
	}, [selectedPrOption]);

	const viewingOrderAvailableSizeLabels = useMemo(() => {
		if (!viewingOrder) return [];

		return getAvailableSizeLabels(
			viewingOrder.inventory_item,
			viewingOrder.requested_color,
			viewingOrder.requested_size,
		);
	}, [viewingOrder]);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();

		return purchaseOrders.filter((order) => {
			const matchesStatus = statusFilter === "all" || order.status === statusFilter;
			if (!matchesStatus) return false;

			if (!query) return true;

			return (
				order.po_number.toLowerCase().includes(query) ||
				(order.purchase_request?.pr_number || "").toLowerCase().includes(query) ||
				order.product_name.toLowerCase().includes(query) ||
				(order.supplier?.name || "").toLowerCase().includes(query) ||
				order.status.toLowerCase().includes(query)
			);
		});
	}, [searchQuery, statusFilter, purchaseOrders]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalPoCount = metrics.total_purchase_orders;
	const activePoCount = metrics.active_orders;
	const completedPoCount = metrics.completed_orders;

	const closeCreateModal = () => {
		setIsCreateModalOpen(false);
		setIsCreatingPO(false);
		setIsDeliveryCalendarOpen(false);
		setDeliveryCalendarMonth(new Date(today.getFullYear(), today.getMonth(), 1));
		setFormData(initialFormState);
	};

	const selectedDeliveryDate = useMemo(() => {
		if (!formData.expectedDeliveryDate) return null;
		const parsed = new Date(`${formData.expectedDeliveryDate}T00:00:00`);
		if (Number.isNaN(parsed.getTime())) return null;
		return normalizeDate(parsed);
	}, [formData.expectedDeliveryDate]);

	const deliveryMonthLabel = useMemo(() => {
		return deliveryCalendarMonth.toLocaleDateString("en-US", {
			month: "long",
			year: "numeric",
		});
	}, [deliveryCalendarMonth]);

	const deliveryMonthYear = deliveryCalendarMonth.getFullYear();
	const deliveryMonthIndex = deliveryCalendarMonth.getMonth();
	const deliveryFirstWeekday = new Date(deliveryMonthYear, deliveryMonthIndex, 1).getDay();
	const deliveryTotalDays = new Date(deliveryMonthYear, deliveryMonthIndex + 1, 0).getDate();
	const deliveryCalendarCells: Array<number | null> = [
		...Array(deliveryFirstWeekday).fill(null),
		...Array.from({ length: deliveryTotalDays }, (_, idx) => idx + 1),
	];
	const currentMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
	const canGoToPreviousDeliveryMonth = deliveryCalendarMonth > currentMonthStart;

	const handleSelectDeliveryDate = (day: number) => {
		const selectedDate = normalizeDate(new Date(deliveryMonthYear, deliveryMonthIndex, day));
		if (selectedDate < today) return;

		setFormData((prev) => ({ ...prev, expectedDeliveryDate: toDateInputValue(selectedDate) }));
		setIsDeliveryCalendarOpen(false);
	};

	const toggleDeliveryCalendar = () => {
		if (!isDeliveryCalendarOpen && selectedDeliveryDate) {
			setDeliveryCalendarMonth(new Date(selectedDeliveryDate.getFullYear(), selectedDeliveryDate.getMonth(), 1));
		}
		setIsDeliveryCalendarOpen((prev) => !prev);
	};

	const handleCreatePO = async () => {
		if (isCreatingPO) return;

		if (!formData.selectedPrId || !formData.expectedDeliveryDate.trim() || !formData.paymentTerms.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Missing fields",
				text: "Please select approved PR, expected delivery date, and payment terms.",
				confirmButtonColor: "#111827",
			});
			return;
		}

		try {
			setIsCreatingPO(true);
			const createdPrIds = [formData.selectedPrId, ...formData.additionalPrIds];
			await purchaseOrderApi.create({
				purchase_request_ids: createdPrIds,
				expected_delivery_date: formData.expectedDeliveryDate,
				payment_terms: formData.paymentTerms,
				notes: formData.notes.trim() || undefined,
			});

			setApprovedPRs((prev) => prev.filter((pr) => !createdPrIds.includes(pr.id)));

			await Swal.fire({
				icon: "success",
				title: "Purchase Order Created",
				text: "PO is ready to be sent to supplier.",
				confirmButtonColor: "#111827",
				timer: 1500,
				showConfirmButton: false,
			});

			closeCreateModal();
			await Promise.all([fetchPurchaseOrders(), fetchApprovedPRs(), fetchMetrics()]);
		} catch (error) {
			console.error("Failed to create PO:", error);
			await Swal.fire({
				icon: "error",
				title: "Creation Failed",
				text: "Failed to create purchase order. Please try again.",
				confirmButtonColor: "#111827",
			});
		} finally {
			setIsCreatingPO(false);
		}
	};

	const handleProgressOrder = async (order: PurchaseOrderType) => {
		const nextStatus = nextStatusMap[order.status as PurchaseOrderStatus];
		if (!nextStatus || (nextStatus === "completed" ? !canComplete : !canManage)) return;

		const result = await Swal.fire({
			title: `Move to ${formatStatus(nextStatus)}?`,
			text: `${order.po_number} will be updated from ${formatStatus(order.status)} to ${formatStatus(nextStatus)}.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: `Yes, mark as ${formatStatus(nextStatus)}`,
			cancelButtonText: "Cancel",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		try {
			const updatedOrder = await purchaseOrderApi.updateStatus(order.id, { status: nextStatus });
			upsertOrderInState(updatedOrder);
			await Promise.all([fetchPurchaseOrders(), fetchMetrics()]);
			setViewingOrder(null);

			await Swal.fire({
				title: "Updated",
				text: `${order.po_number} is now ${formatStatus(nextStatus)}.`,
				icon: "success",
				timer: 1400,
				showConfirmButton: false,
			});
		} catch (error) {
			console.error("Failed to update status:", error);
			await Swal.fire({
				title: "Error",
				text: "Failed to update order status. Please try again.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		}
	};

	const handleCancelOrder = async (order: PurchaseOrderType) => {
		if (["partially_received", "delivered", "completed", "cancelled"].includes(order.status)) return;

		const { value: reason } = await Swal.fire({
			title: "Cancel this PO?",
			text: `${order.po_number} will be marked as Cancelled.`,
			input: "textarea",
			inputLabel: "Cancellation Reason",
			inputPlaceholder: "Enter reason for cancellation...",
			inputAttributes: {
				"aria-label": "Enter reason for cancellation"
			},
			showCancelButton: true,
			confirmButtonText: "Yes, cancel PO",
			cancelButtonText: "Back",
			confirmButtonColor: "#111827",
			cancelButtonColor: "#6b7280",
			inputValidator: (value) => {
				if (!value) {
					return "Please provide a reason for cancellation";
				}
				return null;
			}
		});

		if (!reason) return;

		try {
			const cancelledOrder = await purchaseOrderApi.cancel(order.id, { cancellation_reason: reason });
			upsertOrderInState(cancelledOrder);
			await Promise.all([fetchPurchaseOrders(), fetchMetrics()]);
			setViewingOrder(null);

			await Swal.fire({
				title: "Cancelled",
				text: `${order.po_number} has been cancelled.`,
				icon: "success",
				timer: 1400,
				showConfirmButton: false,
			});
		} catch (error) {
			console.error("Failed to cancel order:", error);
			await Swal.fire({
				title: "Error",
				text: "Failed to cancel order. Please try again.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		}
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingOrder);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Purchase Orders - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Purchase Orders</h1>
						<p className="text-gray-600 dark:text-gray-400">Create PO from approved PR, send to supplier, then track order progress end-to-end</p>
					</div>
					{canCreate && <button
						onClick={() => {
							setIsCreateModalOpen(true);
							setDeliveryCalendarMonth(new Date(today.getFullYear(), today.getMonth(), 1));
							void fetchApprovedPRs();
						}}
						className="px-4 py-2 bg-blue-600 hover:bg-blue-900 text-white rounded-lg font-medium transition-colors whitespace-nowrap"
					>
						+ New PO
					</button>}
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total PO" value={totalPoCount} description="Purchase orders created" icon={ClipboardIcon} color="info" />
					<MetricCard title="In Progress" value={activePoCount} description="Sent, confirmed, or in-transit orders" icon={TruckIcon} color="warning" />
					<MetricCard title="Completed" value={completedPoCount} description="Fully received and closed" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Purchase Order Table</h2>
						<p className="text-sm text-gray-500">Procurement actions: mark as Sent, Confirmed, In Transit, or Cancel. Inventory handles Delivered/Completed.</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by PO number, product, or supplier..."
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
								value={statusFilter}
								onChange={(event) => {
									setStatusFilter(event.target.value as "all" | PurchaseOrderStatus);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="all">All Status</option>
								<option value="draft">Draft</option>
								<option value="sent">Sent</option>
								<option value="confirmed">Confirmed</option>
								<option value="in_transit">In Transit</option>
								<option value="partially_received">Partially Received</option>
								<option value="delivered">Delivered</option>
								<option value="completed">Completed</option>
								<option value="cancelled">Cancelled</option>
							</select>
						</div>
					</div>

					{loading ? (
						<div className="text-center py-8">
							<div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-white"></div>
							<p className="mt-2 text-gray-600 dark:text-gray-400">Loading purchase orders...</p>
						</div>
					) : paginatedItems.length === 0 ? (
						<div className="text-center py-8 text-gray-500 dark:text-gray-400">
							{searchQuery || statusFilter !== "all" ? "No purchase orders found matching your filters." : "No purchase orders yet. Create your first PO."}
						</div>
					) : (
						<>
							<div className="overflow-x-auto">
								<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
									<thead className="bg-gray-50 dark:bg-gray-800/50">
										<tr>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Product</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Supplier</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Total Cost</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Status</th>
											<th className="px-4 py-3 text-left text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider">Actions</th>
										</tr>
									</thead>
									<tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
										{paginatedItems.map((order) => (
											<tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{order.product_name}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{order.supplier?.name || "—"}</td>
												<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{currency.format(order.total_cost)}</td>
												<td className="px-4 py-3 whitespace-nowrap">
													<span className={`px-2 py-1 text-xs font-semibold rounded-full ${statusBadgeClass[order.status]}`}>
														{formatStatus(order.status)}
													</span>
												</td>
												<td className="px-4 py-3 whitespace-nowrap text-sm">
													<div className="flex gap-2">
														<button
															onClick={() => setViewingOrder(order)}
															className="rounded-lg p-2 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/20"
															title="View details"
														>
															<EyeIcon className="h-5 w-5 text-blue-600 dark:text-blue-400" />
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
									Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} orders
								</p>
								<div className="flex gap-2">
									<button
										onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
										disabled={currentPage === 1}
										title="Previous page"
										aria-label="Go to previous page"
										className="px-3 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-800"
									>
										<ChevronLeftIcon className="w-5 h-5" />
									</button>
									<button
										onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
										disabled={currentPage === totalPages}
										title="Next page"
										aria-label="Go to next page"
										className="px-3 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-800"
									>
										<ChevronRightIcon className="w-5 h-5" />
									</button>
								</div>
							</div>
						</>
					)}
				</div>
			</div>

			{isCreateModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close create purchase order modal" className="absolute inset-0 bg-black/50" onClick={closeCreateModal} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Create Purchase Order</h2>
							<button onClick={closeCreateModal} title="Close create purchase order modal" aria-label="Close create purchase order modal" className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Select Approved PR *</label>
								<select
									title="Select approved purchase request"
									aria-label="Select approved purchase request"
									value={formData.selectedPrId || ""}
									onChange={(event) => setFormData((prev) => ({ ...prev, selectedPrId: event.target.value ? Number(event.target.value) : null, additionalPrIds: [] }))}
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								>
									<option value="">-- Choose an approved PR --</option>
									{approvedPRs.map((pr) => (
										<option key={pr.id} value={pr.id}>
											{pr.pr_number} - {pr.product_name} (Qty: {getEffectiveQuantity(
												pr.quantity,
												pr.unit_cost,
												pr.total_cost,
												isAllSizesRequest(pr.requested_size, pr.inventory_item?.category),
											)}{isAllSizesRequest(pr.requested_size, pr.inventory_item?.category)
												? " total units across All Sizes"
												: pr.requested_size ? `, ${formatRequestedSizeDisplay(pr.requested_size)}` : " units"}, {currency.format(pr.total_cost)})
										</option>
									))}
								</select>
								{approvedPRs.length === 0 && (
									<p className="mt-1 text-xs text-amber-600 dark:text-amber-400">&#9888; No approved PRs available. All approved PRs may already have purchase orders.</p>
								)}
							</div>

							{selectedPrOption && (
								<div className="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Supplier:</strong> {selectedPrOption.supplier?.name || "N/A"}</p>
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>{isAllSizesRequest(selectedPrOption.requested_size, selectedPrOption.inventory_item?.category) ? "Total Quantity Across All Sizes" : "Quantity"}:</strong> {selectedPrEffectiveQuantity} units</p>
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Unit Cost:</strong> {currency.format(selectedPrOption.unit_cost)}</p>
									<p className="text-sm text-blue-900 dark:text-blue-200"><strong>Total:</strong> {currency.format(selectedPrOption.total_cost)}</p>
									{selectedPrAvailableSizeLabels.length > 0 && (
										<p className="text-sm text-blue-900 dark:text-blue-200">
											<strong>Available Sizes{selectedPrOption.requested_color ? ` (${selectedPrOption.requested_color})` : ""}:</strong> {selectedPrAvailableSizeLabels.join(", ")}
										</p>
									)}
								</div>
							)}

							{sameSupplierPrs.length > 0 && (
								<fieldset className="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
									<legend className="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">Add approved requests from the same supplier</legend>
									<div className="mt-2 space-y-2">
										{sameSupplierPrs.map((pr) => <label key={pr.id} className="flex items-center gap-2 text-sm">
											<input type="checkbox" checked={formData.additionalPrIds.includes(pr.id)} onChange={(event) => setFormData((current) => ({ ...current, additionalPrIds: event.target.checked ? [...current.additionalPrIds, pr.id] : current.additionalPrIds.filter((id) => id !== pr.id) }))} />
											<span>{pr.pr_number} — {pr.product_name} ({currency.format(pr.total_cost)})</span>
										</label>)}
									</div>
								</fieldset>
							)}

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="relative" ref={deliveryCalendarRef}>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Expected Delivery Date *</label>
									<button
										type="button"
										title="Expected delivery date"
										aria-label="Expected delivery date"
										onClick={toggleDeliveryCalendar}
										className="w-full flex items-center justify-between px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-left focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									>
										<span className={formData.expectedDeliveryDate ? "text-gray-900 dark:text-white" : "text-gray-400 dark:text-gray-500"}>
											{formData.expectedDeliveryDate ? toDisplayDate(formData.expectedDeliveryDate) : "mm/dd/yyyy"}
										</span>
										<CalendarIcon className="w-5 h-5 text-gray-500 dark:text-gray-400" />
									</button>

									{isDeliveryCalendarOpen && (
										<div className="absolute left-0 mt-2 z-30 w-[320px] rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 shadow-xl">
											<div className="flex items-center justify-between mb-4">
												<button
													type="button"
													onClick={() => canGoToPreviousDeliveryMonth && setDeliveryCalendarMonth(new Date(deliveryMonthYear, deliveryMonthIndex - 1, 1))}
													disabled={!canGoToPreviousDeliveryMonth}
													className="w-9 h-9 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 disabled:opacity-40 disabled:cursor-not-allowed"
													aria-label="Previous month"
												>
													&lt;
												</button>
												<p className="font-semibold text-gray-900 dark:text-white">{deliveryMonthLabel}</p>
												<button
													type="button"
													onClick={() => setDeliveryCalendarMonth(new Date(deliveryMonthYear, deliveryMonthIndex + 1, 1))}
													className="w-9 h-9 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300"
													aria-label="Next month"
												>
													&gt;
												</button>
											</div>

											<div className="grid grid-cols-7 gap-2 mb-2">
												{WEEKDAY_LABELS.map((dayLabel) => (
													<div key={dayLabel} className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 text-center">
														{dayLabel}
													</div>
												))}
											</div>

											<div className="grid grid-cols-7 gap-2">
												{deliveryCalendarCells.map((day, index) => {
													if (day === null) {
														return <div key={`blank-${index}`} className="h-10" />;
													}

													const cellDate = normalizeDate(new Date(deliveryMonthYear, deliveryMonthIndex, day));
													const isPast = cellDate < today;
													const isSelected = selectedDeliveryDate ? selectedDeliveryDate.getTime() === cellDate.getTime() : false;

													return (
														<button
															type="button"
															key={`${deliveryMonthYear}-${deliveryMonthIndex}-${day}`}
															disabled={isPast}
															onClick={() => handleSelectDeliveryDate(day)}
															className={`h-10 rounded-xl text-sm font-medium transition-colors ${
																isSelected
																	? "bg-blue-600 text-white"
																	: isPast
																		? "bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500 cursor-not-allowed"
																		: "bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 hover:bg-blue-50 dark:hover:bg-blue-900/40"
															}`}
														>
															{day}
														</button>
													);
												})}
											</div>

											<div className="mt-4 flex items-center justify-between text-xs">
												<button
													type="button"
													onClick={() => {
														setFormData((prev) => ({ ...prev, expectedDeliveryDate: "" }));
														setIsDeliveryCalendarOpen(false);
													}}
													className="text-blue-600 dark:text-blue-400 hover:underline"
												>
													Clear
												</button>
												<button
													type="button"
													onClick={() => {
														setDeliveryCalendarMonth(new Date(today.getFullYear(), today.getMonth(), 1));
														setFormData((prev) => ({ ...prev, expectedDeliveryDate: toDateInputValue(today) }));
														setIsDeliveryCalendarOpen(false);
													}}
													className="text-blue-600 dark:text-blue-400 hover:underline"
												>
													Today
												</button>
											</div>
										</div>
									)}
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Payment Terms *</label>
									<select
										title="Payment terms"
										aria-label="Payment terms"
										value={formData.paymentTerms}
										onChange={(event) => setFormData((prev) => ({ ...prev, paymentTerms: event.target.value }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									>
										<option value="Net 30">Net 30</option>
										<option value="COD">COD</option>
										<option value="50% down, 50% on delivery">50% down, 50% on delivery</option>
										<option value="Net 15">Net 15</option>
										<option value="Net 60">Net 60</option>
									</select>
								</div>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Notes</label>
								<textarea
									rows={3}
									value={formData.notes}
									onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
									placeholder="Optional notes for supplier coordination"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button
								onClick={closeCreateModal}
								disabled={isCreatingPO}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
							>
								Cancel
							</button>
							<button
								onClick={handleCreatePO}
								disabled={isCreatingPO}
								className="flex-1 px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
							>
								{isCreatingPO ? "Creating..." : "Create PO"}
							</button>
						</div>
					</div>
				</div>
			)}

			{viewingOrder && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close purchase order details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingOrder(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Purchase Order Details</h2>
							<button onClick={() => setViewingOrder(null)} title="Close purchase order details modal" aria-label="Close purchase order details modal" className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-5">
							<div className="rounded-2xl border border-blue-100 dark:border-blue-900/50 bg-linear-to-br from-blue-50 via-white to-indigo-50 dark:from-blue-950/20 dark:via-gray-900 dark:to-indigo-950/20 p-4 sm:p-5">
								<div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
									<div>
										<p className="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Purchase Summary</p>
										<h3 className="mt-1 text-lg sm:text-xl font-semibold text-gray-900 dark:text-white">{viewingOrder.product_name}</h3>
										<p className="mt-1 text-sm text-gray-600 dark:text-gray-300">Supplier: {viewingOrder.supplier?.name || "—"}</p>
									</div>
									<span className={`inline-flex items-center self-start px-3 py-1 text-xs font-semibold rounded-full ${statusBadgeClass[viewingOrder.status]}`}>
										{formatStatus(viewingOrder.status)}
									</span>
								</div>
							</div>

							<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
								<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
									<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-3">Product Details</h3>
									<div className="space-y-2">
										<p className="text-sm text-gray-700 dark:text-gray-300"><strong>{isAllSizesRequest(viewingOrder.requested_size, viewingOrder.inventory_item?.category)
											? "Total Quantity Ordered Across All Sizes:"
											: "Quantity Ordered:"}</strong> {getEffectiveQuantity(
											viewingOrder.quantity,
											viewingOrder.unit_cost,
											viewingOrder.total_cost,
											isAllSizesRequest(viewingOrder.requested_size, viewingOrder.inventory_item?.category),
										)} units</p>
										{viewingOrder.received_quantity != null && (
											<p className="text-sm text-gray-700 dark:text-gray-300">
												<strong>Received:</strong> {viewingOrder.received_quantity} units
												{(viewingOrder.defective_quantity ?? 0) > 0 && (
													<span className="ml-2 text-amber-600 dark:text-amber-400">({viewingOrder.defective_quantity} defective - {viewingOrder.received_quantity - (viewingOrder.defective_quantity ?? 0)} accepted)</span>
												)}
											</p>
										)}
										{(viewingOrder.requested_size || isAllSizesRequest(viewingOrder.requested_size, viewingOrder.inventory_item?.category)) && (
											<p className="text-sm text-gray-700 dark:text-gray-300">
												<strong>Requested Size:</strong> <span className="text-indigo-600 dark:text-indigo-400">{getRequestedSizeLabel(viewingOrder.requested_size)}</span>
											</p>
										)}
										{viewingOrderAvailableSizeLabels.length > 0 && (
											<p className="text-sm text-gray-700 dark:text-gray-300">
												<strong>Available Sizes{viewingOrder.requested_color ? ` (${viewingOrder.requested_color})` : ""}:</strong> <span className="text-indigo-600 dark:text-indigo-400">{viewingOrderAvailableSizeLabels.join(", ")}</span>
											</p>
										)}
									</div>
								</div>

								<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
									<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-3">Cost Breakdown</h3>
									<div className="space-y-2">
										<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Unit Cost:</strong> {currency.format(viewingOrder.unit_cost)}</p>
										<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Total Cost:</strong> {currency.format(viewingOrder.total_cost)}</p>
										<p className="text-sm text-gray-700 dark:text-gray-300"><strong>Payment Terms:</strong> {viewingOrder.payment_terms}</p>
									</div>
								</div>
							</div>

							<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
								<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-3">Order Timeline</h3>
								<div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
									<div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3">
										<p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Expected Delivery</p>
										<p className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{formatReadableDate(viewingOrder.expected_delivery_date)}</p>
										{formatRelativeDate(viewingOrder.expected_delivery_date) && (
											<p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{formatRelativeDate(viewingOrder.expected_delivery_date)}</p>
										)}
									</div>
									<div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3">
										<p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Actual Delivery</p>
										<p className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{formatReadableDate(viewingOrder.actual_delivery_date)}</p>
										{formatRelativeDate(viewingOrder.actual_delivery_date) && (
											<p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{formatRelativeDate(viewingOrder.actual_delivery_date)}</p>
										)}
									</div>
									<div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3">
										<p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ordered Date</p>
										<p className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{formatReadableDate(viewingOrder.ordered_date, { withTime: true })}</p>
										{formatRelativeDate(viewingOrder.ordered_date) && (
											<p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{formatRelativeDate(viewingOrder.ordered_date)}</p>
										)}
									</div>
								</div>
								<p className="mt-3 text-sm text-gray-700 dark:text-gray-300"><strong>Ordered By:</strong> {viewingOrder.orderer?.name || "—"}</p>
							</div>

							{viewingOrder.notes && (
								<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
									<h3 className="text-sm font-semibold text-gray-900 dark:text-white mb-2">Notes</h3>
									<p className="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{viewingOrder.notes}</p>
								</div>
							)}

							<PurchaseOrderReceiptPanel
								order={viewingOrder}
								canReceive={false}
								canVoid={canVoid}
								onChanged={async () => {
									const refreshed = await purchaseOrderApi.getById(viewingOrder.id);
									setViewingOrder(refreshed);
									await Promise.all([fetchPurchaseOrders(), fetchMetrics()]);
								}}
							/>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingOrder(null)} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Close</button>
							{nextStatusMap[viewingOrder.status as PurchaseOrderStatus] && (viewingOrder.status === "delivered" ? canComplete : canManage) && (
								<button
									onClick={() => handleProgressOrder(viewingOrder)}
									className="flex-1 px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors"
								>
									Mark as {formatStatus(nextStatusMap[viewingOrder.status as PurchaseOrderStatus]!)}
								</button>
							)}
							{canCancel && !["partially_received", "delivered", "completed", "cancelled"].includes(viewingOrder.status) && (
								<button
									onClick={() => handleCancelOrder(viewingOrder)}
									className="flex-1 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-colors"
								>
									Cancel PO
								</button>
							)}
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
