import { Head, router, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { supplierOrderAPI } from "@/services/inventoryAPI";
import type { SupplierOrder as ApiSupplierOrder } from "@/types/inventory";
import Swal from "sweetalert2";

type OrderStatus = "Sent" | "Confirmed" | "In Transit" | "Delivered" | "Completed" | "Cancelled";
type MetricColor = "success" | "warning" | "info";

interface SupplierOrderItem {
	id: number;
	poNo: string;
	supplierName: string;
	productName: string;
	requestedSize: string | null;
	requestedColor: string | null;
	isAllSizesRequest: boolean;
	inventoryCategory: string | null;
	applicableSizeRows: number;
	applicableSizeLabels: string[];
	applicableSizes: Array<{
		id: number;
		label: string;
		size: string;
		sizeSystem: string;
	}>;
	quantity: number;
	receivedQuantity: number | null;
	defectiveQuantity: number | null;
	orderedDate: string;
	expectedDeliveryDate: string;
	status: OrderStatus;
	remarks: string; 
}

interface ReceivingFormState {
	receivedQuantity: string;
	defectiveQuantity: string;
	actualDeliveryDate: string;
	notes: string;
}

interface SizeReceiptInput {
	receivedQuantity: string;
	defectiveQuantity: string;
}

interface MonitoringMetrics {
	active_orders: number;
	due_today: number;
	overdue: number;
	arriving_soon: number;
}

const apiStatusToDisplay: Record<string, OrderStatus> = {
	sent: "Sent",
	confirmed: "Confirmed",
	in_transit: "In Transit",
	delivered: "Delivered",
	completed: "Completed",
	cancelled: "Cancelled",
	draft: "Sent",
};

const isAllSizesValue = (value?: string | null): boolean => {
	const normalized = (value ?? "").trim().toLowerCase().replace(/[\s-]+/g, "_");
	if (!normalized) return true;
	return ["all", "all_sizes", "all_size", "any"].includes(normalized);
};

const getRequestedSizeLabel = (order: SupplierOrderItem): string => {
	if (order.isAllSizesRequest && order.applicableSizeRows > 0) {
		return "All Sizes";
	}

	return formatRequestedSize(order.requestedSize);
};

const getAllSizeMultiplier = (order: SupplierOrderItem): number => {
	if (!order.isAllSizesRequest) return 1;

	if (order.applicableSizes.length > 0) {
		return order.applicableSizes.length;
	}

	if (order.applicableSizeRows > 0) {
		return order.applicableSizeRows;
	}

	return 1;
};

const getEffectiveOrderedQuantity = (order: SupplierOrderItem): number => {
	return order.quantity * getAllSizeMultiplier(order);
};

const mapApiOrder = (order: ApiSupplierOrder): SupplierOrderItem => {
	const requestedSizeRaw = (order as any).requested_size ?? (order as any).purchase_request?.requested_size ?? null;
	const requestedColorRaw = (order as any).requested_color ?? (order as any).purchase_request?.requested_color ?? null;
	const inventoryCategoryRaw =
		(order as any).inventory_category ??
		(order as any).purchase_request?.inventory_item?.category ??
		(order as any).inventory_item?.category ??
		null;

	const requestedSize = requestedSizeRaw == null ? null : String(requestedSizeRaw).trim() || null;
	const requestedColor = requestedColorRaw == null ? null : String(requestedColorRaw).trim() || null;

	return {
		id: order.id,
		poNo: order.po_number,
		supplierName: order.supplier?.name ?? "Unknown Supplier",
		productName: order.product_name ?? "—",
		requestedSize,
		requestedColor,
		isAllSizesRequest: Boolean((order as any).is_all_sizes_request ?? isAllSizesValue(requestedSizeRaw)),
		inventoryCategory: inventoryCategoryRaw,
		applicableSizeRows: Number((order as any).applicable_size_rows ?? 0),
		applicableSizeLabels: Array.isArray((order as any).applicable_size_labels)
			? ((order as any).applicable_size_labels as unknown[])
				.map((label) => String(label).trim())
				.filter((label) => label.length > 0)
			: [],
		applicableSizes: Array.isArray((order as any).applicable_sizes)
			? ((order as any).applicable_sizes as unknown[])
				.map((row) => ({
					id: Number((row as any)?.id ?? 0),
					label: String((row as any)?.label ?? "").trim(),
					size: String((row as any)?.size ?? "").trim(),
					sizeSystem: String((row as any)?.size_system ?? "US").trim().toUpperCase(),
				}))
				.filter((row) => row.id > 0 && row.label.length > 0)
			: [],
		quantity: order.quantity ?? 0,
		receivedQuantity: typeof (order as any).received_quantity === "number" ? (order as any).received_quantity : null,
		defectiveQuantity: typeof (order as any).defective_quantity === "number" ? (order as any).defective_quantity : null,
		orderedDate: order.ordered_date,
		expectedDeliveryDate: order.expected_delivery_date ?? "",
		status: apiStatusToDisplay[order.status] ?? "Sent",
		remarks: order.remarks ?? "",
	};
};

const formatRequestedSize = (value: string | null): string => {
	if (!value) return "—";
	const trimmed = value.trim();
	if (!trimmed) return "—";
	if (/^(US|UK|EU|AU|CN)\s+/i.test(trimmed)) return trimmed;
	return `Size ${trimmed}`;
};

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const statusBadgeClass: Record<OrderStatus, string> = {
	Sent: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Confirmed: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	"In Transit": "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600",
	Delivered: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	Completed: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	Cancelled: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
};

const STATUS_FILTER_OPTIONS: Array<"all" | OrderStatus> = [
	"all",
	"Sent",
	"Confirmed",
	"In Transit",
	"Delivered",
	"Cancelled",
];

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

const AlertIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
	</svg>
);

const TruckIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 17h6m-6 0a2 2 0 11-4 0m4 0a2 2 0 104 0m0 0h2a2 2 0 002-2v-3l-3-3h-3V7a2 2 0 00-2-2H3" />
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

const getDaysToDelivery = (expectedDate: string) => {
	const today = new Date();
	today.setHours(0, 0, 0, 0);

	const expected = new Date(expectedDate);
	expected.setHours(0, 0, 0, 0);

	return Math.round((expected.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
};

const parseApiDate = (value?: string | null) => {
	if (!value) return null;
	const normalized = value
		.trim()
		.replace(/\.(\d{3})\d+(Z|[+-]\d{2}:?\d{2})?$/, ".$1$2")
		.replace(/\.(\d{1,2})(Z|[+-]\d{2}:?\d{2})?$/, ".$100$2");

	const parsed = new Date(normalized);
	if (Number.isNaN(parsed.getTime())) return null;
	return parsed;
};

const fmtDate = (value?: string | null) => {
	if (!value) return "—";
	const parsed = parseApiDate(value);
	if (!parsed) return value;
	return parsed.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
};

const fmtDateTime = (value?: string | null) => {
	if (!value) return "—";
	const parsed = parseApiDate(value);
	if (!parsed) return value;
	return parsed.toLocaleString("en-PH", {
		year: "numeric",
		month: "short",
		day: "numeric",
		hour: "numeric",
		minute: "2-digit",
	});
};

const getSlaBadge = (order: SupplierOrderItem) => {
	if (order.status === "Delivered" || order.status === "Completed") {
		return { label: "Closed", className: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300" };
	}

	if (order.status === "Cancelled") {
		return { label: "Cancelled", className: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" };
	}

	const days = getDaysToDelivery(order.expectedDeliveryDate);

	if (days < 0) {
		return { label: `${Math.abs(days)} days overdue`, className: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" };
	}

	if (days === 0) {
		return { label: "Due today", className: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600" };
	}

	return { label: `${days} days left`, className: "bg-white text-black border border-gray-300 dark:bg-gray-900 dark:text-white dark:border-gray-600" };
};

const getStatusBadge = (order: SupplierOrderItem) => {
	if (order.status === "In Transit" && getDaysToDelivery(order.expectedDeliveryDate) < 0) {
		return "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300";
	}

	return statusBadgeClass[order.status];
};

export default function SupplierOrderMonitoring() {
	const supplierParam = new URLSearchParams(window.location.search).get("supplier") ?? "";
	const { initialData } = usePage().props as any;
	const [orders, setOrders] = useState<SupplierOrderItem[]>(
		() => (initialData?.data ?? []).map(mapApiOrder)
	);
	const [loading, setLoading] = useState(false);
	const [loadError, setLoadError] = useState<string | null>(null);
	const [searchQuery, setSearchQuery] = useState(supplierParam);
	const [statusFilter, setStatusFilter] = useState<"all" | OrderStatus>("all");
	const [currentPage, setCurrentPage] = useState(1);
	const [viewingOrder, setViewingOrder] = useState<SupplierOrderItem | null>(null);
	const [receivingOrder, setReceivingOrder] = useState<SupplierOrderItem | null>(null);
	const [metrics, setMetrics] = useState<MonitoringMetrics | null>(null);
	const [receivingData, setReceivingData] = useState<ReceivingFormState>({
		receivedQuantity: "",
		defectiveQuantity: "0",
		actualDeliveryDate: new Date().toISOString().split("T")[0],
		notes: "",
	});
	const [sizeReceivingData, setSizeReceivingData] = useState<Record<number, SizeReceiptInput>>({});

	const shouldShowPerSizeReceiving = Boolean(
		receivingOrder &&
		receivingOrder.isAllSizesRequest &&
		receivingOrder.applicableSizes.length > 0,
	);

	const perSizeRows = shouldShowPerSizeReceiving && receivingOrder
		? receivingOrder.applicableSizes.map((sizeRow) => {
			const values = sizeReceivingData[sizeRow.id] ?? { receivedQuantity: "0", defectiveQuantity: "0" };
			const received = Number(values.receivedQuantity || "0");
			const defective = Number(values.defectiveQuantity || "0");
			const safeReceived = Number.isNaN(received) ? 0 : received;
			const safeDefective = Number.isNaN(defective) ? 0 : defective;
			const net = Math.max(0, safeReceived - safeDefective);

			return {
				...sizeRow,
				received: safeReceived,
				defective: safeDefective,
				net,
			};
		})
		: [];

	const receivedInput = shouldShowPerSizeReceiving
		? perSizeRows.reduce((sum, row) => sum + row.received, 0)
		: Number(receivingData.receivedQuantity || "0");
	const defectiveInput = shouldShowPerSizeReceiving
		? perSizeRows.reduce((sum, row) => sum + row.defective, 0)
		: Number(receivingData.defectiveQuantity || "0");
	const netAddedToInventory = shouldShowPerSizeReceiving
		? perSizeRows.reduce((sum, row) => sum + row.net, 0)
		: Math.max(0, receivedInput - defectiveInput);
	const shouldShowPerSizeBreakdown = shouldShowPerSizeReceiving && perSizeRows.length > 1;

	const loadOrders = async () => {
		setLoading(true);
		setLoadError(null);
		try {
			const response = await supplierOrderAPI.getMonitoring({ per_page: 200 });
			setOrders((response.data ?? []).map(mapApiOrder));
		} catch {
			setLoadError("Could not refresh supplier orders.");
		} finally {
			setLoading(false);
		}
	};

	const loadMetrics = async () => {
		try {
			const data = await supplierOrderAPI.getMonitoringMetrics();
			setMetrics(data);
		} catch {
			// Keep UI usable by falling back to local computed metrics.
			setMetrics(null);
		}
	};

	const handleReceiveOrder = async (order: SupplierOrderItem) => {
		setReceivingOrder(order);
		setReceivingData({
			receivedQuantity: String(getEffectiveOrderedQuantity(order)),
			defectiveQuantity: "0",
			actualDeliveryDate: new Date().toISOString().split("T")[0],
			notes: "",
		});

		if (order.isAllSizesRequest && order.applicableSizes.length > 0) {
			const initialPerSize: Record<number, SizeReceiptInput> = {};
			order.applicableSizes.forEach((sizeRow) => {
				initialPerSize[sizeRow.id] = {
					receivedQuantity: String(order.quantity),
					defectiveQuantity: "0",
				};
			});
			setSizeReceivingData(initialPerSize);
		} else {
			setSizeReceivingData({});
		}
	};

	const handleConfirmReceiving = async () => {
		if (!receivingOrder) return;

		const received = receivedInput;
		const defective = defectiveInput;

		if (Number.isNaN(received) || received < 0) {
			await Swal.fire({ icon: "warning", title: "Invalid", text: "Received quantity must be 0 or more.", confirmButtonColor: "#111827" });
			return;
		}
		if (Number.isNaN(defective) || defective < 0) {
			await Swal.fire({ icon: "warning", title: "Invalid", text: "Defective quantity must be 0 or more.", confirmButtonColor: "#111827" });
			return;
		}
		if (defective > received) {
			await Swal.fire({ icon: "warning", title: "Invalid", text: "Defective quantity cannot exceed received quantity.", confirmButtonColor: "#111827" });
			return;
		}

		if (shouldShowPerSizeReceiving) {
			for (const row of perSizeRows) {
				if (row.received < 0 || row.defective < 0) {
					await Swal.fire({ icon: "warning", title: "Invalid", text: "Per-size quantities must be 0 or more.", confirmButtonColor: "#111827" });
					return;
				}
				if (row.defective > row.received) {
					await Swal.fire({ icon: "warning", title: "Invalid", text: `Defective cannot exceed received for ${row.label}.`, confirmButtonColor: "#111827" });
					return;
				}
			}
		}

		const expectedReceived = getEffectiveOrderedQuantity(receivingOrder);
		const hasShortOrDefective = received < expectedReceived || defective > 0;
		if (hasShortOrDefective && !receivingData.notes.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Notes required",
				text: "Please add notes when delivery is short or has defective items.",
				confirmButtonColor: "#111827",
			});
			return;
		}

		try {
			await supplierOrderAPI.receiveMonitoringOrder(receivingOrder.id, {
				actual_delivery_date: receivingData.actualDeliveryDate || new Date().toISOString().split("T")[0],
				received_quantity: received,
				defective_quantity: defective,
				size_receipts: shouldShowPerSizeReceiving
					? perSizeRows.map((row) => ({
						inventory_size_id: row.id,
						received_quantity: row.received,
						defective_quantity: row.defective,
					}))
					: undefined,
				notes: receivingData.notes.trim() || undefined,
			});

			const netAccepted = received - defective;
			const totalAdded = shouldShowPerSizeReceiving ? netAddedToInventory : netAccepted;
			await Swal.fire({
				title: "Goods Received",
				html: defective > 0
					? `<p>${receivingOrder.poNo} marked as delivered.</p><p class="mt-1 text-sm text-gray-500">Received: ${received} &nbsp;|&nbsp; Defective: ${defective} &nbsp;|&nbsp; <strong>Added to inventory: ${totalAdded}</strong></p>`
					: `<p>${receivingOrder.poNo} marked as delivered.</p><p class="mt-1 text-sm text-gray-500">${totalAdded} units added to inventory.</p>`,
				icon: "success",
				confirmButtonColor: "#111827",
				timer: 2500,
				showConfirmButton: false,
			});

			setReceivingOrder(null);
			void loadOrders();
			void loadMetrics();
		} catch (error) {
			console.error("Failed to confirm receipt:", error);
			await Swal.fire({ icon: "error", title: "Error", text: "Failed to confirm goods receipt. Please try again.", confirmButtonColor: "#111827" });
		}
	};

	useEffect(() => {
		void loadOrders();
		void loadMetrics();
	}, []);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();

		return orders.filter((order) => {
			const matchesStatus = statusFilter === "all" || order.status === statusFilter;
			if (!matchesStatus) return false;

			if (!query) return true;

			return (
				order.poNo.toLowerCase().includes(query) ||
				order.supplierName.toLowerCase().includes(query) ||
				order.productName.toLowerCase().includes(query) ||
				order.status.toLowerCase().includes(query)
			);
		});
	}, [orders, searchQuery, statusFilter]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const activeOrders = orders.filter((order) => !["Delivered", "Completed", "Cancelled"].includes(order.status));
	const totalActiveCount = activeOrders.length;
	const dueTodayCount = activeOrders.filter((order) => getDaysToDelivery(order.expectedDeliveryDate) === 0).length;
	const overdueCount = activeOrders.filter((order) => getDaysToDelivery(order.expectedDeliveryDate) < 0).length;
	const arrivingSoonCount = activeOrders.filter((order) => {
		const days = getDaysToDelivery(order.expectedDeliveryDate);
		return days > 0 && days <= 3;
	}).length;

	const activeMetricCount = metrics?.active_orders ?? totalActiveCount;
	const dueTodayMetricCount = metrics?.due_today ?? dueTodayCount;
	const overdueMetricCount = metrics?.overdue ?? overdueCount;
	const arrivingSoonMetricCount = metrics?.arriving_soon ?? arrivingSoonCount;

	const isAnyModalOpen = Boolean(viewingOrder) || Boolean(receivingOrder);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Supplier Order Monitoring - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Supplier Order Monitoring</h1>
						<p className="text-gray-600 dark:text-gray-400">Track PO delivery timelines and monitor remaining days before expected arrival</p>
					</div>
					<button
						type="button"
						onClick={() => {
							void loadOrders();
							void loadMetrics();
						}}
						className="px-3 py-1 text-xs font-semibold rounded-full border border-gray-300 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800"
					>
						Refresh
					</button>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
					<MetricCard title="Active Orders" value={activeMetricCount} description="PO still in progress" icon={ClipboardIcon} color="info" />
					<MetricCard title="Due Today" value={dueTodayMetricCount} description="Require receiving coordination" icon={ClockIcon} color="warning" />
					<MetricCard title="Overdue" value={overdueMetricCount} description="Need supplier follow-up" icon={AlertIcon} color="warning" />
					<MetricCard title="Arriving Within 3 Days" value={arrivingSoonMetricCount} description="Prepare warehouse receiving" icon={TruckIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Supplier Delivery Timeline</h2>
						<p className="text-sm text-gray-500">Monitor expected delivery dates and remaining days per purchase order</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by PO no, supplier, product, or status..."
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
									setStatusFilter(event.target.value as "all" | OrderStatus);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								{STATUS_FILTER_OPTIONS.map((statusOption) => (
									<option key={statusOption} value={statusOption}>
										{statusOption === "all" ? "All Status" : statusOption}
									</option>
								))}
							</select>
						</div>
					</div>
					{supplierParam && searchQuery === supplierParam && (
						<div className="mb-4 flex items-center gap-2">
							<span className="text-sm text-gray-500 dark:text-gray-400">Filtered by supplier:</span>
							<span className="inline-flex items-center gap-1.5 rounded-full bg-blue-100 dark:bg-blue-900/30 px-3 py-1 text-sm font-medium text-blue-700 dark:text-blue-300">
								{supplierParam}
								<button
									type="button"
									onClick={() => { setSearchQuery(""); window.history.replaceState({}, "", window.location.pathname); }}
									className="ml-0.5 text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-200 text-base leading-none"
									title="Clear supplier filter"
								>
									×
								</button>
							</span>
							<button
								type="button"
								onClick={() => router.visit("/erp/procurement/suppliers-management")}
								className="text-sm text-blue-600 dark:text-blue-400 hover:underline"
							>
								← Back to Suppliers
							</button>
						</div>
					)}

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>

									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Supplier / Product</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Expected delivery</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Days monitor</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
						{loading ? (
								<tr><td colSpan={5} className="px-4 py-10 text-center text-sm text-gray-500">Loading supplier orders...</td></tr>
						) : loadError ? (
							<tr>
									<td colSpan={5} className="px-4 py-10 text-center text-sm text-red-500">
									<p>{loadError}</p>
									<button
										type="button"
										onClick={() => {
											void loadOrders();
										}}
										className="mt-3 px-3 py-1 rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/20"
									>
										Retry
									</button>
								</td>
							</tr>
						) : paginatedItems.length > 0 ? (
									paginatedItems.map((order) => {
										const sla = getSlaBadge(order);

										return (
											<tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">

												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
													<p className="font-medium text-gray-900 dark:text-white">{order.supplierName}</p>
													<p className="text-xs text-gray-500 dark:text-gray-400">{order.productName} · Qty {getEffectiveOrderedQuantity(order)}</p>
												</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{fmtDate(order.expectedDeliveryDate)}</td>
												<td className="px-4 py-3">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${sla.className}`}>{sla.label}</span>
												</td>
												<td className="px-4 py-3">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadge(order)}`}>{order.status}</span>
												</td>
												<td className="px-4 py-3 text-center">
													<div className="flex items-center justify-center gap-2">
														<button
															onClick={() => setViewingOrder(order)}
															className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
															title="View supplier order details"
														>
															<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
																<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
																<circle cx="12" cy="12" r="3" />
															</svg>
														</button>
														{order.status === "In Transit" && (
															<button
																onClick={() => handleReceiveOrder(order)}
																className="p-2 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors"
																title="Receive and verify goods"
															>
																<svg className="h-5 w-5 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
																	<path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
																</svg>
															</button>
														)}
													</div>
												</td>
											</tr>
										);
									})
								) : (
									<tr>
										<td colSpan={5} className="px-4 py-10 text-center text-sm text-gray-500">No supplier orders found.</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {filteredData.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} orders
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

			{viewingOrder && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close supplier order details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingOrder(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Supplier Order Details</h2>
							<button onClick={() => setViewingOrder(null)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">PO No</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.poNo}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getStatusBadge(viewingOrder)}`}>{viewingOrder.status}</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Supplier and Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingOrder.supplierName}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">{viewingOrder.productName} · Qty {getEffectiveOrderedQuantity(viewingOrder)}</p>
								{(viewingOrder.requestedSize || viewingOrder.requestedColor) && (
									<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
										Variant: {getRequestedSizeLabel(viewingOrder)}{viewingOrder.requestedColor ? ` · ${viewingOrder.requestedColor}` : ""}
									</p>
								)}
								{viewingOrder.isAllSizesRequest && viewingOrder.applicableSizeRows > 1 && viewingOrder.applicableSizeLabels.length > 0 && (
									<div className="mt-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 px-3 py-2">
										<p className="text-xs font-medium text-indigo-700 dark:text-indigo-300 mb-1">Applicable Sizes ({viewingOrder.applicableSizeRows})</p>
										<p className="text-xs text-indigo-800 dark:text-indigo-200">{viewingOrder.applicableSizeLabels.join(", ")}</p>
									</div>
								)}
								{viewingOrder.receivedQuantity !== null && (
									<>
										<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Received Qty: {viewingOrder.receivedQuantity}</p>
										<p className="text-sm text-gray-500 dark:text-gray-400">Defective Qty: {viewingOrder.defectiveQuantity ?? 0}</p>
										<p className="text-sm text-green-700 dark:text-green-300">
											Accepted to Stock: {Math.max(0, (viewingOrder.receivedQuantity ?? 0) - (viewingOrder.defectiveQuantity ?? 0))}
											{viewingOrder.isAllSizesRequest && getAllSizeMultiplier(viewingOrder) > 1
												? ` across ${getAllSizeMultiplier(viewingOrder)} sizes`
												: ""}
										</p>
									</>
								)}
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Ordered Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{fmtDateTime(viewingOrder.orderedDate)}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Expected Delivery Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{fmtDate(viewingOrder.expectedDeliveryDate)}</p>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Days to Delivery</p>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getSlaBadge(viewingOrder).className}`}>{getSlaBadge(viewingOrder).label}</span>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingOrder(null)} className="w-full px-4 py-2 rounded-lg bg-black hover:bg-gray-900 text-white font-medium transition-colors">Close</button>
						</div>
					</div>
				</div>
			)}

			{receivingOrder && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close goods receipt modal" className="absolute inset-0 bg-black/50" onClick={() => setReceivingOrder(null)} />
					<div className="relative w-[min(1100px,95vw)] max-h-[90vh] rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl overflow-hidden flex flex-col">
						<div className="flex items-center justify-between p-5 border-b border-gray-200 dark:border-gray-800 shrink-0">
							<div>
								<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Goods Receipt Verification</h2>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">{receivingOrder.poNo} — {receivingOrder.productName}</p>
							</div>
							<button onClick={() => setReceivingOrder(null)} title="Close goods receipt modal" aria-label="Close goods receipt modal" className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-5 overflow-y-auto flex-1">
							<div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
								<div className="lg:col-span-4 space-y-4">
									<div className="flex items-center gap-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 px-4 py-3">
										<svg className="h-5 w-5 text-blue-600 dark:text-blue-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
											<circle cx="12" cy="12" r="10" />
											<path d="M12 6v6l4 2" />
										</svg>
										<div className="text-sm">
											<p className="font-medium text-blue-900 dark:text-blue-100">Ordered quantity: <strong>{getEffectiveOrderedQuantity(receivingOrder)} units</strong></p>
											{receivingOrder.isAllSizesRequest && getAllSizeMultiplier(receivingOrder) > 1 && (
												<p className="text-blue-700 dark:text-blue-300 text-xs mt-0.5">
													Base per-size qty: {receivingOrder.quantity} x {getAllSizeMultiplier(receivingOrder)} sizes
												</p>
											)}
											<p className="text-blue-700 dark:text-blue-300 text-xs mt-0.5">Verify what's actually received</p>
										</div>
									</div>

									{(receivingOrder.requestedSize || receivingOrder.requestedColor || receivingOrder.isAllSizesRequest) && (
										<div className="grid grid-cols-1 gap-3">
											<div className="rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 px-4 py-3">
												<p className="text-xs font-medium text-indigo-700 dark:text-indigo-300">Requested Size</p>
												<p className="text-sm font-semibold text-indigo-900 dark:text-indigo-100 mt-0.5">{getRequestedSizeLabel(receivingOrder)}</p>
											</div>
											<div className="rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 px-4 py-3">
												<p className="text-xs font-medium text-purple-700 dark:text-purple-300">Requested Color</p>
												<p className="text-sm font-semibold text-purple-900 dark:text-purple-100 mt-0.5">{receivingOrder.requestedColor || "—"}</p>
											</div>
										</div>
									)}

									{receivedInput > 0 && (
										<div className="rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 px-4 py-3">
											<p className="text-sm font-medium text-green-900 dark:text-green-100">
												✓ Net added to inventory: <strong>{netAddedToInventory}</strong> units
												{receivingOrder && shouldShowPerSizeReceiving
													? ` (${receivedInput} received - ${defectiveInput} defective across ${receivingOrder.applicableSizes.length} sizes)`
													: ""}
											</p>
										</div>
									)}
								</div>

								<div className="lg:col-span-8 space-y-4">
									{shouldShowPerSizeBreakdown && receivingOrder && (
										<div className="rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 px-4 py-3">
											<p className="text-xs font-medium text-indigo-700 dark:text-indigo-300 mb-1">Per-size receiving impact ({receivingOrder.applicableSizeRows} sizes)</p>
											<div className="flex flex-wrap gap-2">
												{perSizeRows.map((row) => (
													<span
														key={row.id}
														className="inline-flex items-center rounded-full border border-indigo-300 dark:border-indigo-700 px-2 py-1 text-xs font-semibold text-indigo-800 dark:text-indigo-200"
													>
														{row.label} +{row.net}
													</span>
												))}
											</div>
										</div>
									)}

									{shouldShowPerSizeReceiving ? (
										<div className="space-y-3">
											<p className="text-xs font-medium text-gray-700 dark:text-gray-300">Receive per size (for accurate stock saving)</p>
											<div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
												{receivingOrder.applicableSizes.map((sizeRow) => {
													const rowState = sizeReceivingData[sizeRow.id] ?? { receivedQuantity: "0", defectiveQuantity: "0" };

													return (
														<div key={sizeRow.id} className="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
															<p className="text-sm font-semibold text-gray-900 dark:text-white mb-2">{sizeRow.label}</p>
															<div className="grid grid-cols-2 gap-3">
																<div>
																	<label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Received <span className="text-red-500">*</span></label>
																	<input
																		type="number"
																		title={`Received quantity for ${sizeRow.label}`}
																		aria-label={`Received quantity for ${sizeRow.label}`}
																		value={rowState.receivedQuantity}
																		onChange={(e) =>
																			setSizeReceivingData((prev) => ({
																				...prev,
																				[sizeRow.id]: {
																					receivedQuantity: e.target.value,
																					defectiveQuantity: prev[sizeRow.id]?.defectiveQuantity ?? "0",
																				},
																			}))
																		}
																		className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
																		min="0"
																	/>
																</div>
																<div>
																	<label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Defective</label>
																	<input
																		type="number"
																		title={`Defective quantity for ${sizeRow.label}`}
																		aria-label={`Defective quantity for ${sizeRow.label}`}
																		value={rowState.defectiveQuantity}
																		onChange={(e) =>
																			setSizeReceivingData((prev) => ({
																				...prev,
																				[sizeRow.id]: {
																					receivedQuantity: prev[sizeRow.id]?.receivedQuantity ?? "0",
																					defectiveQuantity: e.target.value,
																				},
																			}))
																		}
																		className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
																		min="0"
																	/>
																</div>
															</div>
														</div>
													);
												})}
											</div>
										</div>
									) : (
										<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
											<div>
												<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Received Quantity <span className="text-red-500">*</span></label>
												<input
													type="number"
													title="Received quantity"
													aria-label="Received quantity"
													value={receivingData.receivedQuantity}
													onChange={(e) => setReceivingData((prev) => ({ ...prev, receivedQuantity: e.target.value }))}
													className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
													min="0"
												/>
												<p className="text-xs text-gray-500 dark:text-gray-400 mt-1">How many units were received?</p>
											</div>
											<div>
												<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Defective / Damaged</label>
												<input
													type="number"
													title="Defective or damaged quantity"
													aria-label="Defective or damaged quantity"
													value={receivingData.defectiveQuantity}
													onChange={(e) => setReceivingData((prev) => ({ ...prev, defectiveQuantity: e.target.value }))}
													className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
													min="0"
												/>
												<p className="text-xs text-gray-500 dark:text-gray-400 mt-1">(if any)</p>
											</div>
										</div>
									)}

									<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
										<div>
											<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Actual Delivery Date <span className="text-red-500">*</span></label>
											<input
												type="date"
												title="Actual delivery date"
												aria-label="Actual delivery date"
												value={receivingData.actualDeliveryDate}
												onChange={(e) => setReceivingData((prev) => ({ ...prev, actualDeliveryDate: e.target.value }))}
												className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
											/>
										</div>

										<div>
											<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Notes <span className="text-xs text-gray-500">(required if short or defective)</span></label>
											<textarea
												rows={3}
												value={receivingData.notes}
												onChange={(e) => setReceivingData((prev) => ({ ...prev, notes: e.target.value }))}
												placeholder="Describe any missing items, damage, or supplier issues..."
												className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
											/>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div className="flex gap-3 px-5 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 shrink-0">
							<button onClick={() => setReceivingOrder(null)} className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">Cancel</button>
							<button onClick={handleConfirmReceiving} className="flex-1 px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium transition-colors">
								Confirm Receipt &amp; Update Inventory
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
