import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import axios from "axios";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";
import Swal from "sweetalert2";
import { computeCanPay, getPhoneDisplayForReceipt } from "../../../Repairs/posPaymentValidation";
import { PosMode, resolveAllowedModes } from "../../../ERP/cashier/posModeResolver";
import { buildRepairBreakdown } from "../../../../utils/repairPricing";
import { repairPosHistoryApi } from "../../../../services/repairPosHistoryApi";

type PaymentMethod = "cash" | "gcash" | "card";
type PosDueType = "deposit" | "balance" | "full";
type ManualPaymentPolicy = "deposit_50" | "full_upfront";

type RepairOrderOption = {
	id: string;
	customer: string;
	customerId?: number | null;
	paymentPolicy?: "deposit_50" | "full_upfront";
	paymentStatus?: string;
	status?: string;
	returnDeliveryMethod?: "walk_in" | "customer_pickup" | "shop_delivery" | string;
	dueTypeToCollect?: PosDueType | null;
	service: string;
	amount: number;
	requestedServices: string[];
};

type RepairServiceOption = {
	id: string;
	name: string;
	category: string;
	price: number;
	duration: string;
};

type ServicePackageOption = {
	id: string;
	name: string;
	description: string;
	includedServices: string[];
	serviceIds: number[];
	price: number;
	saveText: string;
};

type POSItem = {
	id: string;
	label: string;
	qty: number;
	unitPrice: number;
	orderItemId?: number;
	source: "manual" | "repair-order" | "service-catalog" | "package" | "package-add-on";
	manualRepairPackageId?: number | null;
	manualServiceIds?: number[];
};

type RetailProductVariant = {
	id: number;
	size: string;
	color: string;
	stock: number;
	image?: string | null;
};

type RetailCatalogProduct = {
	id: number;
	name: string;
	price: number;
	stock: number;
	image?: string | null;
	variants: RetailProductVariant[];
};

type RetailCartItem = {
	lineId: string;
	productId: number;
	name: string;
	unitPrice: number;
	qty: number;
	stock: number;
	image?: string | null;
	variantId?: number | null;
	size?: string | null;
	color?: string | null;
};

type ReceiptRefundEntryItem = {
	orderItemId: number;
	requestedQty: number;
	approvedQty: number;
};

type ReceiptRefundEntry = {
	status: string;
	approvedAmount: number;
	items?: ReceiptRefundEntryItem[];
};

type ReceiptSnapshot = {
	moduleType?: "repair" | "retail";
	transactionId?: number;
	repairRequestId?: number;
	repairStatus?: string | null;
	customerType?: "registered" | "walk_in";
	dueType?: PosDueType | null;
	paidAmount: number;
	refundEntries: ReceiptRefundEntry[];
	latestRefund?: {
		id: number;
		status: string;
	};
	receiptNo: string;
	createdAtISO: string;
	dateLabel: string;
	cashierName: string;
	customerName: string;
	customerPhone: string;
	paymentReference?: string | null;
	paymentMethod: PaymentMethod;
	notes: string;
	cashReceived: number;
	subtotal: number;
	discount: number;
	vatRate: number;
	vatAmount: number;
	totalDue: number;
	change: number;
	items: POSItem[];
};

type RefundQueueItem = {
	id: number;
	status: string;
	finance_status?: string;
	shop_owner_status?: string;
	requested_amount: number;
	approved_amount?: number | null;
	requested_at?: string | null;
	reason_code?: string;
	failure_reason?: string | null;
	repairRequest?: {
		request_id?: string;
		customer_name?: string;
	};
};

type ManualQueueStatus = "pending" | "received" | "in_progress" | "ready_for_pickup" | "picked_up";

type ManualQueueRow = {
	id: number;
	request_id: string;
	customer_name: string;
	phone: string;
	status: ManualQueueStatus;
	payment_policy: "deposit_50" | "full_upfront";
	total: number;
	paid: number;
	remaining_balance: number;
	next_due_type: PosDueType | null;
	receipt_no?: string | null;
};

type RetailRefundDisposition = "resellable" | "damaged";

type RetailRefundSelectableItem = {
	orderItemId: number;
	label: string;
	purchasedQty: number;
	committedQty: number;
	remainingQty: number;
	unitPrice: number;
};

type RetailRefundDraft = {
	requestedQty: number;
	inspectionDisposition: RetailRefundDisposition;
};

const hasOpenOrCompletedRefund = (receipt: ReceiptSnapshot): boolean => {
	const status = String(receipt.latestRefund?.status || "").toLowerCase();
	return ["requested", "approved", "processing", "succeeded"].includes(status);
};

const WARRANTY_ELIGIBLE_REPAIR_STATUSES = new Set(["picked_up", "received"]);

const isWarrantyEligibleRepairStatus = (status: unknown): boolean => {
	const normalized = String(status ?? "")
		.trim()
		.toLowerCase()
		.replace(/-/g, "_");

	return WARRANTY_ELIGIBLE_REPAIR_STATUSES.has(normalized);
};

const parseDueType = (value: unknown): PosDueType | null => {
	const normalized = String(value ?? "").toLowerCase();
	if (normalized === "deposit" || normalized === "balance" || normalized === "full") {
		return normalized;
	}

	return null;
};

const getDueTypeLabel = (dueType: PosDueType | null | undefined): string => {
	if (dueType === "deposit") return "DEPOSIT";
	if (dueType === "balance") return "BALANCE";
	if (dueType === "full") return "FULL";
	return "PAYMENT";
};

const SERVICES_PER_PAGE = 6;
const VAT_RATE = 12;

const resolveBusinessTypeForPos = (props: any): "retail" | "repair" | "both" => {
	const rawBusinessType = String(
		props?.auth?.shop_owner?.business_type
		?? props?.auth?.user?.shop_owner?.business_type
		?? props?.shop_owner?.business_type
		?? props?.auth?.business_type
		?? props?.auth?.user?.business_type
		?? "retail",
	)
		.toLowerCase()
		.trim();

	if (rawBusinessType.includes("both")) return "both";

	const hasRetailSignal = rawBusinessType.includes("retail");
	const hasRepairSignal = rawBusinessType.includes("repair") || rawBusinessType.includes("service");

	if (hasRetailSignal && hasRepairSignal) return "both";
	if (hasRepairSignal) return "repair";
	if (hasRetailSignal) return "retail";

	return "retail";
};

const normalizeDueType = (value: string | null): PosDueType => {
	if (value === "deposit" || value === "balance" || value === "full") {
		return value;
	}

	return "full";
};

const normalizePaymentPolicy = (value: unknown): "deposit_50" | "full_upfront" => {
	return value === "full_upfront" ? "full_upfront" : "deposit_50";
};

const POS_ATTACHABLE_WORKFLOW_STATUSES = new Set([
	"repairer_accepted",
	"waiting_customer_confirmation",
	"owner_approval_pending",
	"owner_approved",
	"confirmed",
	"pending",
	"received",
	"in_progress",
	"in-progress",
	"awaiting_parts",
	"ready_for_pickup",
	"ready-for-pickup",
]);

const isPosAttachEligibleStatus = (status: string): boolean => {
	return POS_ATTACHABLE_WORKFLOW_STATUSES.has(status);
};

const resolveDueTypeForPolicy = (policy: "deposit_50" | "full_upfront", requestedDueType: PosDueType): PosDueType => {
	if (policy === "full_upfront") return "full";
	if (requestedDueType === "deposit" || requestedDueType === "balance") return requestedDueType;
	return "deposit";
};

const resolveOutstandingDueType = (order: Pick<RepairOrderOption, "paymentPolicy" | "paymentStatus" | "status" | "returnDeliveryMethod">): PosDueType | null => {
	const policy = order.paymentPolicy ?? "deposit_50";
	const paymentStatus = String(order.paymentStatus ?? "").toLowerCase();
	const workflowStatus = String(order.status ?? "").toLowerCase();
	const returnMethod = String(order.returnDeliveryMethod ?? "").toLowerCase();
	const isDepositSettled = paymentStatus === "paid" || paymentStatus === "partially_paid";

	if (!isPosAttachEligibleStatus(workflowStatus)) {
		return null;
	}

	if (policy === "full_upfront") {
		return paymentStatus === "paid" || paymentStatus === "completed" || paymentStatus === "partially_paid" ? null : "full";
	}

	if (paymentStatus === "completed") return null;
	if (isDepositSettled) {
		if (returnMethod === "shop_delivery") return null;

		if (workflowStatus === "ready-for-pickup" || workflowStatus === "ready_for_pickup") {
			return "balance";
		}

		return null;
	}

	return "deposit";
};

const computeDueAmountForOrder = (order: RepairOrderOption, dueType: PosDueType): number => {
	if (dueType === "full") {
		return Number(order.amount || 0);
	}

	return Math.round((Number(order.amount || 0) / 2) * 100) / 100;
};

const mapTenderType = (method: PaymentMethod): "cash" | "paymongo_card" | "paymongo_wallet" => {
	if (method === "card") return "paymongo_card";
	if (method === "gcash") return "paymongo_wallet";
	return "cash";
};

const formatPeso = (value: number): string => {
	return new Intl.NumberFormat("en-PH", {
		style: "currency",
		currency: "PHP",
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	}).format(Number.isFinite(value) ? value : 0);
};

const createRetailLineId = (): string => `retail-line-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

const normalizeVariantToken = (value: unknown): string => {
	return String(value ?? "")
		.trim()
		.replace(/\s*\+\s*/g, "+")
		.replace(/\s+/g, " ")
		.toLowerCase();
};

const getRetailVariantIdentity = (
	productId: number,
	variantId: number | null | undefined,
	size?: string | null,
	color?: string | null,
): string => {
	const parsedVariantId = Number(variantId ?? 0);
	if (parsedVariantId > 0) {
		return `${productId}::variant:${parsedVariantId}`;
	}

	const normalizedSize = normalizeVariantToken(size);
	const normalizedColor = normalizeVariantToken(color);
	return `${productId}::option:${normalizedSize}|${normalizedColor}`;
};

const MANUAL_QUEUE_NEXT_STATUS: Record<ManualQueueStatus, ManualQueueStatus | null> = {
	pending: "received",
	received: "in_progress",
	in_progress: "ready_for_pickup",
	ready_for_pickup: "picked_up",
	picked_up: null,
};

const toSafeNumber = (value: string): number => {
	const parsed = Number(value);
	if (!Number.isFinite(parsed) || parsed < 0) return 0;
	return parsed;
};

const toDigitsOnly = (value: string): string => value.replace(/[^0-9]/g, "");

const toCurrencyInput = (value: string): string => {
	const sanitized = value.replace(/[^0-9.]/g, "");
	if (sanitized === "") return "";

	const [rawWhole, ...rawFractionParts] = sanitized.split(".");
	const whole = rawWhole === "" ? "0" : rawWhole.replace(/^0+(?=\d)/, "");

	if (rawFractionParts.length === 0) {
		return whole;
	}

	const fraction = rawFractionParts.join("").slice(0, 2);
	return `${whole}.${fraction}`;
};

const normalizeServiceName = (value: string): string => value.trim().toLowerCase();

const toDateInputValue = (isoValue: string): string => {
	const date = new Date(isoValue);
	if (Number.isNaN(date.getTime())) return "";
	const year = date.getFullYear();
	const month = String(date.getMonth() + 1).padStart(2, "0");
	const day = String(date.getDate()).padStart(2, "0");
	return `${year}-${month}-${day}`;
};

const RETAIL_COMMITTED_REFUND_STATUSES = new Set(["requested", "approved", "processing", "succeeded"]);

const resolveCommittedRetailRefundQtyByOrderItem = (receipt: ReceiptSnapshot): Map<number, number> => {
	const qtyByOrderItem = new Map<number, number>();

	receipt.refundEntries.forEach((entry) => {
		const status = String(entry.status || "").toLowerCase();
		if (!RETAIL_COMMITTED_REFUND_STATUSES.has(status)) {
			return;
		}

		(entry.items || []).forEach((line) => {
			const orderItemId = Number(line.orderItemId || 0);
			const qty = Math.max(0, Number(line.approvedQty || line.requestedQty || 0));
			if (orderItemId <= 0 || qty <= 0) {
				return;
			}

			qtyByOrderItem.set(orderItemId, (qtyByOrderItem.get(orderItemId) || 0) + qty);
		});
	});

	return qtyByOrderItem;
};

const buildReceiptText = (snapshot: ReceiptSnapshot): string => {
	const lines = [
		"SoleSpace Repair POS",
		"Point of Sale Receipt",
		"",
		`Receipt: ${snapshot.receiptNo}`,
		`Date: ${snapshot.dateLabel}`,
		`Customer: ${snapshot.customerName}`,
		...(snapshot.customerPhone.length > 0 ? [`Phone: ${snapshot.customerPhone}`] : []),
		...(snapshot.paymentReference ? [`Reference: ${snapshot.paymentReference}`] : []),
		`Cashier: ${snapshot.cashierName}`,
		`Method: ${snapshot.paymentMethod.toUpperCase()}`,
		`Phase: ${getDueTypeLabel(snapshot.dueType)}`,
		"",
		"Items:",
		...snapshot.items.map((line) => `${line.label} | ${line.qty} x ${formatPeso(line.unitPrice)} = ${formatPeso(line.qty * line.unitPrice)}`),
		"",
		`Subtotal: ${formatPeso(snapshot.subtotal)}`,
		`Discount: - ${formatPeso(snapshot.discount)}`,
		`VAT (${snapshot.vatRate.toFixed(2)}%): ${formatPeso(snapshot.vatAmount)}`,
		`Total: ${formatPeso(snapshot.totalDue)}`,
		`Tendered: ${formatPeso(snapshot.cashReceived)}`,
		`Change: ${formatPeso(snapshot.change)}`,
		...(snapshot.notes.trim().length > 0 ? ["", `Notes: ${snapshot.notes}`] : []),
		"",
		"Thank you for trusting SoleSpace Repair.",
	];

	return lines.join("\n");
};

const PointOfSalePage = () => {
	const { props } = usePage();
	const cashierName = String((props as any)?.auth?.shop_owner?.name || (props as any)?.auth?.user?.name || "Shop Owner Cashier");
	const shopRepairPaymentPolicy: ManualPaymentPolicy =
		String(
			(props as any)?.auth?.shop_owner?.repair_payment_policy
			?? (props as any)?.auth?.user?.shop_owner?.repair_payment_policy
			?? (props as any)?.shop_settings?.repair_payment_policy
		) === "full_upfront"
			? "full_upfront"
			: "deposit_50";
	const urlParams = typeof window !== "undefined" ? new URLSearchParams(window.location.search) : new URLSearchParams();
	const requestedRepairRequestId = String(urlParams.get("repair_request_id") || "");
	const requestedDueType = normalizeDueType(urlParams.get("due_type"));
	const hasRequestedDueType = urlParams.has("due_type");

	const [isOrderModalOpen, setIsOrderModalOpen] = useState<boolean>(false);
	const [orderSearch, setOrderSearch] = useState<string>("");
	const [serviceSearch, setServiceSearch] = useState<string>("");
	const [customerName, setCustomerName] = useState<string>("");
	const [customerPhone, setCustomerPhone] = useState<string>("");
	const [customerEmail, setCustomerEmail] = useState<string>("");
	const [servicePage, setServicePage] = useState<number>(1);

	const [items, setItems] = useState<POSItem[]>([]);
	const [discountInput, setDiscountInput] = useState<string>("0");
	const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("cash");
	const [cashReceivedInput, setCashReceivedInput] = useState<string>("");
	const [proofReference, setProofReference] = useState<string>("");
	const [isProcessingPayment, setIsProcessingPayment] = useState<boolean>(false);
	const [notes, setNotes] = useState<string>("");
	const [receiptSnapshot, setReceiptSnapshot] = useState<ReceiptSnapshot | null>(null);
	const [isReceiptModalOpen, setIsReceiptModalOpen] = useState<boolean>(false);
	const [isHistoryModalOpen, setIsHistoryModalOpen] = useState<boolean>(false);
	const [isLoadingHistory, setIsLoadingHistory] = useState<boolean>(false);
	const [receiptHistory, setReceiptHistory] = useState<ReceiptSnapshot[]>([]);
	const [historySearch, setHistorySearch] = useState<string>("");
	const [historyDate, setHistoryDate] = useState<string>("");
	const [selectedRepairOrder, setSelectedRepairOrder] = useState<RepairOrderOption | null>(null);

	const [repairOrders, setRepairOrders] = useState<RepairOrderOption[]>([]);
	const [serviceCatalog, setServiceCatalog] = useState<RepairServiceOption[]>([]);
	const [servicePackages, setServicePackages] = useState<ServicePackageOption[]>([]);
	const [isLoadingData, setIsLoadingData] = useState<boolean>(false);
	const [isRefundQueueOpen, setIsRefundQueueOpen] = useState<boolean>(false);
	const [isRefundQueueLoading, setIsRefundQueueLoading] = useState<boolean>(false);
	const [refundQueue, setRefundQueue] = useState<RefundQueueItem[]>([]);
	const [processingRefundId, setProcessingRefundId] = useState<number | null>(null);
	const [manualQueue, setManualQueue] = useState<ManualQueueRow[]>([]);
	const [manualQueueSearch, setManualQueueSearch] = useState<string>("");
	const [manualQueueLoading, setManualQueueLoading] = useState<boolean>(false);
	const [manualQueueActionLoadingId, setManualQueueActionLoadingId] = useState<number | null>(null);
const businessType = resolveBusinessTypeForPos(props as any);
const allowedModes = useMemo(() => resolveAllowedModes(businessType), [businessType]);
const [mode, setMode] = useState<PosMode>(allowedModes[0]);
const [retailSearch, setRetailSearch] = useState<string>("");
const [retailProducts, setRetailProducts] = useState<RetailCatalogProduct[]>([]);
const [retailLoading, setRetailLoading] = useState<boolean>(false);
const [retailCart, setRetailCart] = useState<RetailCartItem[]>([]);
const [retailSelectionByProduct, setRetailSelectionByProduct] = useState<Record<number, { size: string; color: string }>>({});
const [retailCustomerName, setRetailCustomerName] = useState<string>("");
const [retailCustomerPhone, setRetailCustomerPhone] = useState<string>("");
const [retailCustomerEmail, setRetailCustomerEmail] = useState<string>("");
const [retailPaymentMethod, setRetailPaymentMethod] = useState<PaymentMethod>("cash");
const [retailCashReceivedInput, setRetailCashReceivedInput] = useState<string>("");
const [retailProofReference, setRetailProofReference] = useState<string>("");
const [retailNotes, setRetailNotes] = useState<string>("");
const [retailProcessingPayment, setRetailProcessingPayment] = useState<boolean>(false);
const [isRetailRefundModalOpen, setIsRetailRefundModalOpen] = useState<boolean>(false);
const [retailRefundReceipt, setRetailRefundReceipt] = useState<ReceiptSnapshot | null>(null);
const [retailRefundTransactionId, setRetailRefundTransactionId] = useState<number>(0);
const [retailRefundableBalance, setRetailRefundableBalance] = useState<number>(0);
const [retailRefundItems, setRetailRefundItems] = useState<RetailRefundSelectableItem[]>([]);
const [retailRefundDraftByOrderItem, setRetailRefundDraftByOrderItem] = useState<Record<number, RetailRefundDraft>>({});
const [retailRefundSearch, setRetailRefundSearch] = useState<string>("");
const [retailRefundValidationError, setRetailRefundValidationError] = useState<string>("");
const [retailRefundSubmitting, setRetailRefundSubmitting] = useState<boolean>(false);

useEffect(() => {
	if (!allowedModes.includes(mode)) {
		setMode(allowedModes[0]);
	}
}, [allowedModes, mode]);

useEffect(() => {
	if (mode === "retail") {
		setIsOrderModalOpen(false);
		setIsRefundQueueOpen(false);
		setIsReceiptModalOpen(false);
		setIsHistoryModalOpen(false);
	}
}, [mode]);

useEffect(() => {
	let isMounted = true;

	const loadData = async () => {
			if (!allowedModes.includes("repair")) {
				setIsLoadingData(false);
				return;
			}

			setIsLoadingData(true);
			try {
				const [servicesResult, ordersResult, packagesResult] = await Promise.allSettled([
					axios.get("/api/shop-owner/repair-services"),
					axios.get("/api/shop-owner/repairs"),
					axios.get("/api/repair-packages"),
				]);

				if (isMounted && servicesResult.status === "fulfilled") {
					const rawServices = Array.isArray((servicesResult.value.data as any)?.data)
						? (servicesResult.value.data as any).data
						: Array.isArray(servicesResult.value.data)
							? servicesResult.value.data
							: [];

					const mappedServices: RepairServiceOption[] = rawServices
						.map((entry: any, index: number) => {
							const price = Number(entry?.price ?? entry?.amount ?? 0);
							return {
								id: String(entry?.id ?? `S-${index}`),
								name: String(entry?.name ?? entry?.service_name ?? "Repair Service"),
								category: String(entry?.category ?? entry?.service_category ?? "General"),
								price: Number.isFinite(price) ? price : 0,
								duration: String(entry?.duration ?? entry?.turnaround_time ?? "1-2 days"),
							};
						})
						.filter((entry: RepairServiceOption) => entry.price > 0);

					if (mappedServices.length > 0) {
						setServiceCatalog(mappedServices);
					}
				}

				if (isMounted && ordersResult.status === "fulfilled") {
					const rawOrders = Array.isArray((ordersResult.value.data as any)?.data)
						? (ordersResult.value.data as any).data
						: Array.isArray(ordersResult.value.data)
							? ordersResult.value.data
							: [];

					const mappedOrders: RepairOrderOption[] = rawOrders
						.map((entry: any, index: number) => {
							const amount = Number(
								entry?.final_total
								?? entry?.pricing_breakdown?.final_total
								?? entry?.total
								?? entry?.finalPrice
								?? entry?.amount
								?? 0,
							);

							const selectedServicesRaw = Array.isArray(entry?.included_services_snapshot)
								? entry.included_services_snapshot
								: Array.isArray(entry?.add_on_services_snapshot)
									? entry.add_on_services_snapshot
									: Array.isArray(entry?.services)
										? entry.services
										: Array.isArray(entry?.selectedServices)
								? entry.selectedServices
								: typeof entry?.service === "string"
									? [entry.service]
									: typeof entry?.item === "string"
										? [entry.item]
										: [];

							const requestedServices = selectedServicesRaw
								.map((selected: any) => {
									if (typeof selected === "string") return selected;
									return String(selected?.name ?? selected?.service_name ?? "");
								})
								.map((serviceName: string) => serviceName.trim())
								.filter((serviceName: string) => serviceName.length > 0);

							const packageName = String(entry?.pricing_breakdown?.package_name ?? "").trim();
							const primaryService = String(
								entry?.service
								?? entry?.item
								?? entry?.service_name
								?? packageName
								?? requestedServices[0]
								?? "Repair Service"
							);
							return {
								id: String(entry?.id ?? `R-${index}`),
								customer: String(entry?.customer ?? entry?.customer_name ?? "Walk-in Customer"),
								customerId: Number.isFinite(Number(entry?.customer_id)) ? Number(entry.customer_id) : null,
								paymentPolicy: normalizePaymentPolicy(entry?.payment_policy_snapshot ?? entry?.payment_policy ?? entry?.shop_owner?.repair_payment_policy),
								paymentStatus: String(entry?.payment_status ?? "pending"),
								status: String(entry?.status ?? ""),
								returnDeliveryMethod: String(entry?.return_delivery_method ?? (entry?.delivery_method === "walk_in" ? "walk_in" : "customer_pickup")),
								service: primaryService,
								amount: Number.isFinite(amount) ? amount : 0,
								requestedServices: requestedServices.length > 0 ? requestedServices : [primaryService],
							};
						})
						.filter((entry: RepairOrderOption) => entry.amount > 0)
						.map((entry: RepairOrderOption) => ({
							...entry,
							dueTypeToCollect: resolveOutstandingDueType(entry),
						}));

					if (mappedOrders.length > 0) {
						setRepairOrders(mappedOrders);
					}
				}

				if (isMounted && packagesResult.status === "fulfilled") {
					const rawPackages = Array.isArray((packagesResult.value.data as any)?.data)
						? (packagesResult.value.data as any).data
						: Array.isArray(packagesResult.value.data)
							? packagesResult.value.data
							: [];

					const mappedPackages: ServicePackageOption[] = rawPackages
						.map((entry: any, index: number) => {
							const packagePrice = Number(entry?.effective_package_price ?? entry?.package_price ?? entry?.price ?? 0);
							const serviceIds = Array.isArray(entry?.services)
								? entry.services
									.map((service: any) => Number(service?.id ?? 0))
									.filter((id: number) => Number.isInteger(id) && id > 0)
								: [];
							const serviceNames = Array.isArray(entry?.services)
								? entry.services
									.map((service: any) => String(service?.name ?? "").trim())
									.filter((name: string) => name.length > 0)
								: [];
							const servicesTotal = Number(entry?.services_total_price ?? 0);
							const savings = Number(entry?.savings_amount ?? Math.max(servicesTotal - packagePrice, 0));

							return {
								id: String(entry?.id ?? `P-${index}`),
								name: String(entry?.name ?? "Repair Package"),
								description: String(entry?.description ?? ""),
								includedServices: serviceNames,
								serviceIds,
								price: Number.isFinite(packagePrice) ? packagePrice : 0,
								saveText: savings > 0 ? `Save P${savings.toFixed(2)}` : "",
							};
						})
						.filter((entry: ServicePackageOption) => entry.price > 0);

					setServicePackages(mappedPackages);
				}
			} catch {
				// Use fallback data when endpoints are unavailable.
			} finally {
				if (isMounted) {
					setIsLoadingData(false);
				}
			}
		};

		loadData();

		return () => {
			isMounted = false;
		};
	}, []);

	const fetchRefundQueue = async () => {
		setIsRefundQueueLoading(true);
		try {
			const response = await axios.get('/api/repair-pos/refunds/queue?include_history=1', { withCredentials: true });
			const data = Array.isArray(response?.data?.data) ? response.data.data : [];
			setRefundQueue(data);
		} catch {
			setRefundQueue([]);
		} finally {
			setIsRefundQueueLoading(false);
		}
	};

	const fetchManualQueue = async (searchValue = "") => {
		setManualQueueLoading(true);
		try {
			const query = searchValue.trim();
			const response = await axios.get('/api/repair-pos/manual-queue', {
				params: query.length > 0 ? { q: query } : {},
				withCredentials: true,
			});
			const data = Array.isArray(response?.data?.data) ? response.data.data : [];
			setManualQueue(data);
		} catch {
			setManualQueue([]);
		} finally {
			setManualQueueLoading(false);
		}
	};

	const fetchRetailProducts = async (searchValue = "") => {
		setRetailLoading(true);
		try {
			const query = searchValue.trim();
			const response = await axios.get('/api/retail-pos/products', {
				params: query.length > 0 ? { q: query } : {},
				withCredentials: true,
			});
			const data = Array.isArray(response?.data?.data) ? response.data.data : [];
			const mapped: RetailCatalogProduct[] = data.map((row: any) => ({
				id: Number(row?.id ?? 0),
				name: String(row?.name ?? "Retail Product"),
				price: Number(row?.price ?? 0),
				stock: Number(row?.stock_quantity ?? 0),
				image: row?.main_image ? String(row.main_image) : null,
				variants: Array.isArray(row?.variants)
					? row.variants.map((variant: any) => ({
						id: Number(variant?.id ?? 0),
						size: String(variant?.size ?? "").trim(),
						color: String(variant?.color ?? "").trim(),
						stock: Number(variant?.quantity ?? 0),
						image: variant?.image ? String(variant.image) : null,
					})).filter((variant: RetailProductVariant) => variant.id > 0)
					: [],
			})).filter((row: RetailCatalogProduct) => row.id > 0);

			setRetailProducts(mapped);
			setRetailSelectionByProduct((prev) => {
				const next = { ...prev };
				mapped.forEach((product) => {
					if (next[product.id]) {
						return;
					}

					const defaultVariant = product.variants.find((variant) => variant.stock > 0) ?? product.variants[0];
					next[product.id] = {
						size: defaultVariant?.size ?? "",
						color: defaultVariant?.color ?? "",
					};
				});

				return next;
			});
		} catch {
			setRetailProducts([]);
		} finally {
			setRetailLoading(false);
		}
	};

	useEffect(() => {
		if (!allowedModes.includes("repair")) {
			return;
		}

		fetchManualQueue();
	}, [allowedModes]);

	useEffect(() => {
		if (!allowedModes.includes("retail") || mode !== "retail") {
			return;
		}

		fetchRetailProducts(retailSearch);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [allowedModes, mode]);

	useEffect(() => {
		let isMounted = true;

		const loadHistory = async () => {
			if (!isHistoryModalOpen) return;

			setIsLoadingHistory(true);
			try {
				let rows: any[] = [];
				if (mode === "retail") {
					const response = await axios.get('/api/retail-pos/transactions', {
						params: { per_page: 200 },
						withCredentials: true,
					});

					rows = Array.isArray((response.data as any)?.data?.data)
						? (response.data as any).data.data
						: [];
				} else {
					const scopedRepairId = selectedRepairOrder ? Number(selectedRepairOrder.id) : undefined;
					const response = await repairPosHistoryApi.listTransactions(scopedRepairId, 200);
					rows = Array.isArray((response.data as any)?.data?.data)
						? (response.data as any).data.data
						: [];
				}

				const mappedHistory: ReceiptSnapshot[] = rows.map((row: any, index: number) => {
					const receiptPayload = row?.receipt?.print_payload ?? {};
					const issuedAt = String(row?.receipt?.issued_at ?? row?.created_at ?? new Date().toISOString());
					const moduleType: "repair" | "retail" = String(row?.module_type || "").toLowerCase() === "retail"
						? "retail"
						: "repair";

					const items: POSItem[] = (() => {
						if (moduleType === "retail" && Array.isArray(row?.source_order?.items)) {
							const retailOrderItems = row.source_order.items
								.map((orderItem: any) => {
									const orderItemId = Number(orderItem?.id || 0);
									const qty = Math.max(0, Number(orderItem?.quantity ?? 0));
									const subtotal = Number(orderItem?.subtotal ?? 0);
									const unitPrice = Number(orderItem?.price ?? 0) > 0
										? Number(orderItem?.price ?? 0)
										: Number((subtotal / Math.max(1, qty)).toFixed(2));

									if (orderItemId <= 0 || qty <= 0 || unitPrice <= 0) {
										return null;
									}

									const size = String(orderItem?.size ?? "").trim();
									const color = String(orderItem?.color ?? "").trim();
									const variantLabel = [size, color].filter(Boolean).join(" / ");

									return {
										id: `order-item-${orderItemId}`,
										label: variantLabel !== ""
											? `${String(orderItem?.product_name ?? "Item")} (${variantLabel})`
											: String(orderItem?.product_name ?? "Item"),
										qty,
										unitPrice,
										orderItemId,
										source: "manual" as const,
									};
								})
								.filter((item: POSItem | null): item is POSItem => item !== null);

							if (retailOrderItems.length > 0) {
								return retailOrderItems;
							}
						}

						const payloadItems = Array.isArray(receiptPayload?.items) ? receiptPayload.items : [];
						return payloadItems.map((item: any, itemIndex: number) => ({
							id: String(item?.id ?? `receipt-item-${index}-${itemIndex}`),
							label: String(item?.label ?? item?.name ?? item?.product_name ?? "Item"),
							qty: Math.max(0, Number(item?.qty ?? item?.quantity ?? 0)),
							unitPrice: Number(item?.unitPrice ?? item?.unit_price ?? item?.price ?? 0),
							orderItemId: Number(item?.order_item_id || 0) > 0 ? Number(item?.order_item_id) : undefined,
							source: "manual" as const,
						}));
					})();

					const latestRefund = Array.isArray(row?.refunds) && row.refunds.length > 0
						? row.refunds[0]
						: null;

					const methodRaw = String(
						row?.payment_lines?.[0]?.tender_type
						?? receiptPayload?.payment_lines?.[0]?.tender_type
						?? "cash",
					);
					const paymentMethod: PaymentMethod = methodRaw.includes("wallet")
						? "gcash"
						: methodRaw.includes("card")
							? "card"
							: "cash";
					const dueType = parseDueType(row?.due_type ?? receiptPayload?.due_type);

					return {
						moduleType,
						transactionId: Number(row?.id || 0),
						repairRequestId: moduleType === "repair" ? Number(row?.module_reference_id || 0) : undefined,
						repairStatus: moduleType === "repair"
							? String(row?.repair_request?.status ?? row?.repairRequest?.status ?? "")
							: null,
						customerType: String(row?.customer_type || "walk_in") === "registered" ? "registered" : "walk_in",
						dueType: moduleType === "repair" ? dueType : null,
						paidAmount: Number(row?.paid_amount ?? 0),
						refundEntries: Array.isArray(row?.refunds)
							? row.refunds.map((entry: any) => ({
								status: String(entry?.status || ""),
								approvedAmount: Number(entry?.approved_amount ?? 0),
								items: Array.isArray(entry?.items)
									? entry.items
										.map((item: any) => ({
											orderItemId: Number(item?.order_item_id || 0),
											requestedQty: Math.max(0, Number(item?.requested_qty || 0)),
											approvedQty: Math.max(0, Number(item?.approved_qty ?? item?.requested_qty ?? 0)),
										}))
										.filter((item: ReceiptRefundEntryItem) => item.orderItemId > 0 && item.approvedQty > 0)
									: [],
							}))
							: [],
						latestRefund: latestRefund ? {
							id: Number(latestRefund?.id || 0),
							status: String(latestRefund?.status || "requested"),
						} : undefined,
						receiptNo: String(row?.receipt?.receipt_no ?? row?.transaction_no ?? `POS-${index + 1}`),
						createdAtISO: issuedAt,
						dateLabel: new Date(issuedAt).toLocaleString("en-PH", {
							weekday: "short",
							month: "short",
							day: "2-digit",
							year: "numeric",
							hour: "2-digit",
							minute: "2-digit",
						}),
						cashierName: String(row?.created_by ?? cashierName),
						customerName: String(receiptPayload?.customer?.name ?? row?.walk_in_name ?? "Customer"),
						customerPhone: String(receiptPayload?.customer?.phone ?? row?.walk_in_phone ?? ""),
						paymentReference: String(row?.payment_lines?.[0]?.provider_reference ?? "") || null,
						paymentMethod,
						notes: "",
						cashReceived: Number(row?.paid_amount ?? receiptPayload?.totals?.paid ?? 0),
						subtotal: Number(row?.subtotal ?? receiptPayload?.totals?.subtotal ?? 0),
						discount: Number(row?.discount_amount ?? receiptPayload?.totals?.discount ?? 0),
						vatRate: VAT_RATE,
						vatAmount: Number(row?.tax_amount ?? receiptPayload?.totals?.tax ?? 0),
						totalDue: Number(row?.total_amount ?? receiptPayload?.totals?.total ?? 0),
						change: 0,
						items,
					};
				});

				if (isMounted) {
					setReceiptHistory((prev) => {
						if (mappedHistory.length === 0) {
							return prev;
						}

						const combined = [...mappedHistory, ...prev];
						const seen = new Set<string>();

						return combined.filter((entry) => {
							const key = entry.transactionId && entry.transactionId > 0
								? `tx:${entry.transactionId}`
								: `rcpt:${entry.receiptNo}`;

							if (seen.has(key)) {
								return false;
							}

							seen.add(key);
							return true;
						});
					});
				}
			} catch {
				if (isMounted) {
					setReceiptHistory((prev) => prev);
				}
			} finally {
				if (isMounted) {
					setIsLoadingHistory(false);
				}
			}
		};

		loadHistory();

		return () => {
			isMounted = false;
		};
	}, [cashierName, isHistoryModalOpen, mode, selectedRepairOrder]);

	useEffect(() => {
		if (!isRefundQueueOpen) return;
		fetchRefundQueue();
	}, [isRefundQueueOpen]);

	useEffect(() => {
		if (!requestedRepairRequestId || selectedRepairOrder) return;

		const targetOrder = repairOrders.find((entry) => entry.id === requestedRepairRequestId);
		if (!targetOrder) return;

		const resolvedDueType = resolveDueTypeForPolicy(targetOrder.paymentPolicy ?? "deposit_50", requestedDueType);
		const dueAmount = computeDueAmountForOrder(targetOrder, resolvedDueType);
		setSelectedRepairOrder(targetOrder);
		setCustomerName(targetOrder.customer);
		setCustomerEmail("");
		setItems([
			{
				id: `order-${targetOrder.id}-${resolvedDueType}`,
				label: `${targetOrder.service} (${resolvedDueType})`,
				qty: 1,
				unitPrice: dueAmount,
				source: "repair-order",
			},
		]);
	}, [requestedDueType, repairOrders, requestedRepairRequestId, selectedRepairOrder]);

	const subtotal = useMemo(() => {
		return items.reduce((sum, item) => sum + item.qty * item.unitPrice, 0);
	}, [items]);

	const isManualStandaloneCheckout = useMemo(() => {
		return !selectedRepairOrder && !requestedRepairRequestId;
	}, [requestedRepairRequestId, selectedRepairOrder]);

	const dueTypeForManualCheckout: PosDueType = shopRepairPaymentPolicy === "deposit_50" ? "deposit" : "full";

	const chargeableSubtotal = useMemo(() => {
		if (!isManualStandaloneCheckout) {
			return subtotal;
		}

		return dueTypeForManualCheckout === "deposit"
			? Math.round(subtotal * 0.5 * 100) / 100
			: subtotal;
	}, [dueTypeForManualCheckout, isManualStandaloneCheckout, subtotal]);

	const discount = useMemo(() => {
		return 0;
	}, [subtotal]);

	const taxableBase = useMemo(() => Math.max(chargeableSubtotal - discount, 0), [chargeableSubtotal, discount]);
	const dueBreakdown = useMemo(() => {
		return buildRepairBreakdown({
			finalTotal: taxableBase,
			vatRate: VAT_RATE,
			taxMode: "vat_inclusive",
		});
	}, [taxableBase]);
	const vatAmount = useMemo(() => dueBreakdown.vatAmount, [dueBreakdown.vatAmount]);
	const totalDue = useMemo(() => dueBreakdown.grandTotal, [dueBreakdown.grandTotal]);

	const cashReceived = useMemo(() => toSafeNumber(cashReceivedInput), [cashReceivedInput]);
	const tenderedAmount = paymentMethod === "cash" ? cashReceived : totalDue;
	const changeValue = Math.max(tenderedAmount - totalDue, 0);
	const shortValue = Math.max(totalDue - tenderedAmount, 0);
	const hasInsufficientCash = paymentMethod === "cash" && shortValue > 0;
	const isPaid = receiptSnapshot !== null && items.length > 0;
	const isCustomerPhoneValid = customerPhone.length === 11;
	const hasCashInput = cashReceivedInput.trim().length > 0;
	const hasProofReference = proofReference.trim().length > 0;

	const canPay = !isProcessingPayment && computeCanPay({
		itemsCount: items.length,
		customerName,
		customerPhone,
		paymentMethod,
		cashReceivedInput,
		hasInsufficientCash,
		proofReference,
	});
	const canPrint = isPaid;
	const payDisableReason = useMemo(() => {
		if (isProcessingPayment) return "Processing payment...";
		if (items.length === 0) return "Add at least one service before checkout.";
		if (customerName.trim().length === 0) return "Customer name is required.";
		if (paymentMethod === "cash" && !isCustomerPhoneValid) return "Cash payments require an 11-digit phone number.";
		if (paymentMethod === "cash" && !hasCashInput) return "Enter cash received for cash payments.";
		if (paymentMethod !== "cash" && !hasProofReference) return "Enter proof reference for GCash/Card payments.";
		if (hasInsufficientCash) return `Insufficient cash by ${formatPeso(shortValue)}.`;
		return "";
	}, [customerName, hasCashInput, hasInsufficientCash, hasProofReference, isCustomerPhoneValid, isProcessingPayment, items.length, paymentMethod, shortValue]);
	const effectiveDueType = useMemo(() => {
		const policy = selectedRepairOrder?.paymentPolicy ?? "deposit_50";
		return resolveDueTypeForPolicy(policy, requestedDueType);
	}, [requestedDueType, selectedRepairOrder]);
	const hasRepairOrderItem = useMemo(() => items.some((item) => item.source === "repair-order"), [items]);

	useEffect(() => {
		if (paymentMethod === "cash" && proofReference.length > 0) {
			setProofReference("");
		}
	}, [paymentMethod, proofReference]);

	useEffect(() => {
		if (selectedRepairOrder && !hasRepairOrderItem) {
			setSelectedRepairOrder(null);
		}
	}, [hasRepairOrderItem, selectedRepairOrder]);

	const selectedOrderServiceSet = useMemo(() => {
		if (!selectedRepairOrder) return null;
		return new Set(selectedRepairOrder.requestedServices.map((serviceName) => normalizeServiceName(serviceName)));
	}, [selectedRepairOrder]);

	const activeManualPackage = useMemo(() => {
		const packageItem = items.find((item) => item.source === "package");
		const packageId = Number(packageItem?.manualRepairPackageId ?? 0);
		if (!Number.isInteger(packageId) || packageId <= 0) {
			return null;
		}

		return servicePackages.find((pkg) => Number(pkg.id) === packageId) ?? null;
	}, [items, servicePackages]);

	const activeManualPackageServiceIds = useMemo(() => {
		if (!activeManualPackage) return new Set<number>();
		return new Set(
			activeManualPackage.serviceIds
				.map((id) => Number(id))
				.filter((id) => Number.isInteger(id) && id > 0),
		);
	}, [activeManualPackage]);

	const packageOrderItem = useMemo(() => {
		return items.find((item) => item.source === "package") ?? null;
	}, [items]);

	const packageAddOnOrderItems = useMemo(() => {
		return items.filter((item) => item.source === "package-add-on");
	}, [items]);

	const standaloneOrderItems = useMemo(() => {
		return items.filter((item) => item.source !== "package" && item.source !== "package-add-on");
	}, [items]);

	const visiblePackages = useMemo(() => {
		const query = serviceSearch.trim().toLowerCase();
		if (!query) return servicePackages;
		return servicePackages.filter((pkg) => {
			return (
				pkg.name.toLowerCase().includes(query) ||
				pkg.description.toLowerCase().includes(query) ||
				pkg.includedServices.some((serviceName) => serviceName.toLowerCase().includes(query))
			);
		});
	}, [servicePackages, serviceSearch]);

	const resetOrderInputs = () => {
		setItems([]);
		setDiscountInput("0");
		setPaymentMethod("cash");
		setCashReceivedInput("");
		setProofReference("");
		setNotes("");
		setCustomerName("");
		setCustomerPhone("");
		setCustomerEmail("");
		setSelectedRepairOrder(null);
	};

	const addFromRepairOrder = (order: RepairOrderOption) => {
		const resolvedDueType = order.dueTypeToCollect ?? resolveDueTypeForPolicy(order.paymentPolicy ?? "deposit_50", requestedDueType);
		const dueAmount = computeDueAmountForOrder(order, resolvedDueType);
		setItems([
			{
				id: `order-${order.id}-${resolvedDueType}`,
				label: `${order.service} (${resolvedDueType})`,
				qty: 1,
				unitPrice: dueAmount,
				source: "repair-order",
			},
		]);
		setSelectedRepairOrder(order);
		setCustomerName(order.customer);
		setCustomerEmail("");
		setOrderSearch("");
		setIsOrderModalOpen(false);
	};

	const addFromServiceCatalog = (service: RepairServiceOption) => {
		if (selectedRepairOrder) return;

		const serviceId = Number(service.id);
		if (!Number.isInteger(serviceId) || serviceId <= 0) return;

		setItems((prev) => {
			const hasSelectedPackage = prev.some((item) => item.source === "package");
			if (hasSelectedPackage && activeManualPackageServiceIds.has(serviceId)) {
				return prev;
			}

			const alreadySelected = prev.some((item) => {
				if (item.source !== "service-catalog" && item.source !== "package-add-on") {
					return false;
				}

				return (item.manualServiceIds ?? []).some((id) => Number(id) === serviceId);
			});

			if (alreadySelected) {
				return prev;
			}

			return [
				...prev,
				{
					id: `service-${service.id}-${Date.now()}`,
					label: hasSelectedPackage ? `${service.name} (Add-on)` : service.name,
					qty: 1,
					unitPrice: service.price,
					source: hasSelectedPackage ? "package-add-on" : "service-catalog",
					manualServiceIds: [serviceId],
				},
			];
		});
	};

	const addPackageToOrder = (pkg: ServicePackageOption) => {
		if (selectedRepairOrder) return;
		if (isPackageSelected(pkg)) return;

		setItems((prev) => {
			const preservedItems = prev.filter((item) => (
				item.source !== "package"
				&& item.source !== "service-catalog"
				&& item.source !== "package-add-on"
			));

			const packageId = Number(pkg.id);
			return [
				...preservedItems,
				{
					id: `package-${pkg.id}-${Date.now()}`,
					label: `${pkg.name} (${pkg.includedServices.length} services)`,
					qty: 1,
					unitPrice: pkg.price,
					source: "package",
					manualRepairPackageId: Number.isInteger(packageId) && packageId > 0 ? packageId : null,
					manualServiceIds: pkg.serviceIds,
				},
			];
		});
	};

	const getRetailSelectionForProduct = (product: RetailCatalogProduct): { size: string; color: string } => {
		const existing = retailSelectionByProduct[product.id];
		if (existing?.size && existing?.color) {
			return existing;
		}

		const defaultVariant = product.variants.find((variant) => variant.stock > 0) ?? product.variants[0];
		return {
			size: defaultVariant?.size ?? "",
			color: defaultVariant?.color ?? "",
		};
	};

	const resolveRetailVariant = (product: RetailCatalogProduct, size: string, color: string): RetailProductVariant | null => {
		const normalizedSize = normalizeVariantToken(size);
		const normalizedColor = normalizeVariantToken(color);
		const matched = product.variants.find((variant) => (
			normalizeVariantToken(variant.size) === normalizedSize
			&& normalizeVariantToken(variant.color) === normalizedColor
		));
		return matched ?? null;
	};

	const updateRetailSelection = (productId: number, updates: Partial<{ size: string; color: string }>) => {
		setRetailSelectionByProduct((prev) => ({
			...prev,
			[productId]: {
				...(prev[productId] ?? { size: "", color: "" }),
				...updates,
			},
		}));
	};

	const addRetailProductToCart = (product: RetailCatalogProduct) => {
		const selection = getRetailSelectionForProduct(product);
		const selectedVariant = resolveRetailVariant(product, selection.size, selection.color);
		const nextVariantIdentity = getRetailVariantIdentity(
			product.id,
			selectedVariant?.id ?? null,
			selectedVariant?.size ?? selection.size,
			selectedVariant?.color ?? selection.color,
		);
		const stock = selectedVariant ? selectedVariant.stock : product.stock;
		if (stock <= 0) return;

		setRetailCart((prev) => {
			const existing = prev.find((item) => getRetailVariantIdentity(item.productId, item.variantId, item.size, item.color) === nextVariantIdentity);
			if (existing) {
				return prev.map((item) => {
					if (item.lineId !== existing.lineId) return item;
					return {
						...item,
						qty: Math.min(item.qty + 1, item.stock),
					};
				});
			}

			return [
				...prev,
				{
					lineId: createRetailLineId(),
					productId: product.id,
					name: product.name,
					unitPrice: product.price,
					qty: 1,
					stock,
					image: selectedVariant?.image ?? product.image ?? undefined,
					variantId: selectedVariant?.id ?? null,
					size: selectedVariant?.size ?? (selection.size || undefined),
					color: selectedVariant?.color ?? (selection.color || undefined),
				},
			];
		});
	};

	const updateRetailCartVariant = (lineId: string, nextSize: string, nextColor: string) => {
		setRetailCart((prev) => {
			return prev.map((item) => {
				if (item.lineId !== lineId) return item;

				const product = retailProducts.find((entry) => entry.id === item.productId);
				if (!product) return item;

				const matched = resolveRetailVariant(product, nextSize, nextColor);
				if (!matched) return item;

				const nextIdentity = getRetailVariantIdentity(item.productId, matched.id, matched.size, matched.color);
				const duplicate = prev.find((entry) => entry.lineId !== lineId && getRetailVariantIdentity(entry.productId, entry.variantId, entry.size, entry.color) === nextIdentity);

				if (duplicate) {
					return {
						...item,
						qty: Math.min(item.qty + duplicate.qty, matched.stock),
						variantId: matched.id,
						size: matched.size,
						color: matched.color,
					};
				}

				return {
					...item,
					unitPrice: matched ? matched?.stock >= item.qty ? item.unitPrice : item.unitPrice : item.unitPrice,
					variantId: matched.id,
					size: matched.size,
					color: matched.color,
					stock: matched.stock,
				};
			});
		});
	};

	const updateRetailCartQty = (lineId: string, nextQty: number) => {
		setRetailCart((prev) => prev.map((item) => {
			if (item.lineId !== lineId) return item;
			return {
				...item,
				qty: Math.max(1, Math.min(nextQty, item.stock)),
			};
		}));
	};

	const removeRetailCartItem = (lineId: string) => {
		setRetailCart((prev) => prev.filter((item) => item.lineId !== lineId));
	};

	const clearRetailTransaction = () => {
		setRetailCart([]);
		setRetailCustomerName("");
		setRetailCustomerPhone("");
		setRetailCustomerEmail("");
		setRetailPaymentMethod("cash");
		setRetailCashReceivedInput("");
		setRetailProofReference("");
		setRetailNotes("");
	};

	const retailSubtotal = useMemo(() => {
		return retailCart.reduce((sum, item) => sum + item.qty * item.unitPrice, 0);
	}, [retailCart]);

	const retailBreakdown = useMemo(() => {
		return buildRepairBreakdown({
			finalTotal: retailSubtotal,
			vatRate: VAT_RATE,
			taxMode: "vat_inclusive",
		});
	}, [retailSubtotal]);
	const retailVatAmount = useMemo(() => retailBreakdown.vatAmount, [retailBreakdown.vatAmount]);
	const retailTotalDue = useMemo(() => retailBreakdown.grandTotal, [retailBreakdown.grandTotal]);
	const retailCashReceived = useMemo(() => toSafeNumber(retailCashReceivedInput), [retailCashReceivedInput]);
	const retailTenderedAmount = retailPaymentMethod === "cash" ? retailCashReceived : retailTotalDue;
	const retailChangeValue = Math.max(retailTenderedAmount - retailTotalDue, 0);
	const retailShortValue = Math.max(retailTotalDue - retailTenderedAmount, 0);
	const retailHasInsufficientCash = retailPaymentMethod === "cash" && retailShortValue > 0;
	const retailCanPay = !retailProcessingPayment && computeCanPay({
		itemsCount: retailCart.length,
		customerName: retailCustomerName,
		customerPhone: retailCustomerPhone,
		paymentMethod: retailPaymentMethod,
		cashReceivedInput: retailCashReceivedInput,
		hasInsufficientCash: retailHasInsufficientCash,
		proofReference: retailProofReference,
	});
	const retailPayDisableReason = useMemo(() => {
		if (retailProcessingPayment) return "Processing payment...";
		if (retailCart.length === 0) return "Add at least one product before checkout.";
		if (retailCustomerName.trim().length === 0) return "Customer name is required.";
		if (retailPaymentMethod === "cash" && retailCashReceivedInput.trim().length === 0) return "Enter cash received for cash payments.";
		if (retailPaymentMethod !== "cash" && retailProofReference.trim().length === 0) return "Enter proof reference for GCash/Card payments.";
		if (retailHasInsufficientCash) return `Insufficient cash by ${formatPeso(retailShortValue)}.`;
		return "";
	}, [retailCart.length, retailCashReceivedInput, retailCustomerName, retailHasInsufficientCash, retailPaymentMethod, retailProcessingPayment, retailProofReference, retailShortValue]);

	useEffect(() => {
		if (retailPaymentMethod === "cash" && retailProofReference.length > 0) {
			setRetailProofReference("");
		}
	}, [retailPaymentMethod, retailProofReference]);

	useEffect(() => {
		if (mode === "retail") {
			setSelectedRepairOrder(null);
		}
	}, [mode]);

	const canRequestRetailRefund = (receipt: ReceiptSnapshot): boolean => {
		const openStatuses = new Set(["requested", "approved", "processing"]);
		let hasOpenRequest = false;
		let refundedAmount = 0;

		receipt.refundEntries.forEach((entry) => {
			const status = String(entry.status || "").toLowerCase();
			if (openStatuses.has(status)) {
				hasOpenRequest = true;
			}

			if (status === "succeeded") {
				refundedAmount += Number(entry.approvedAmount ?? 0);
			}
		});

		if (hasOpenRequest) {
			return false;
		}

		const paidAmount = Math.max(0, Number(receipt.paidAmount || receipt.totalDue || 0));
		const remaining = Math.max(0, Number((paidAmount - refundedAmount).toFixed(2)));
		return remaining > 0;
	};

	const resolveRetailRefundRequestAmount = (receipt: ReceiptSnapshot): number => {
		const refundedAmount = receipt.refundEntries.reduce((sum, entry) => {
			return String(entry.status || "").toLowerCase() === "succeeded"
				? sum + Number(entry.approvedAmount ?? 0)
				: sum;
		}, 0);

		const paidAmount = Math.max(0, Number(receipt.paidAmount || receipt.totalDue || 0));
		const remaining = Math.max(0, Number((paidAmount - refundedAmount).toFixed(2)));
		return remaining > 0 ? remaining : Number(receipt.totalDue || 0);
	};

	const resetRetailRefundModalState = () => {
		setIsRetailRefundModalOpen(false);
		setRetailRefundReceipt(null);
		setRetailRefundTransactionId(0);
		setRetailRefundableBalance(0);
		setRetailRefundItems([]);
		setRetailRefundDraftByOrderItem({});
		setRetailRefundSearch("");
		setRetailRefundValidationError("");
		setRetailRefundSubmitting(false);
	};

	const updateRetailRefundQty = (orderItemId: number, rawValue: string) => {
		const sourceItem = retailRefundItems.find((item) => item.orderItemId === orderItemId);
		if (!sourceItem) return;

		const parsed = Math.floor(Number(rawValue));
		const nextQty = Number.isFinite(parsed)
			? Math.max(0, Math.min(parsed, sourceItem.remainingQty))
			: 0;

		setRetailRefundDraftByOrderItem((prev) => ({
			...prev,
			[orderItemId]: {
				inspectionDisposition: prev[orderItemId]?.inspectionDisposition ?? "resellable",
				requestedQty: nextQty,
			},
		}));

		if (retailRefundValidationError.length > 0) {
			setRetailRefundValidationError("");
		}
	};

	const updateRetailRefundDisposition = (orderItemId: number, disposition: RetailRefundDisposition) => {
		setRetailRefundDraftByOrderItem((prev) => ({
			...prev,
			[orderItemId]: {
				requestedQty: prev[orderItemId]?.requestedQty ?? 0,
				inspectionDisposition: disposition,
			},
		}));

		if (retailRefundValidationError.length > 0) {
			setRetailRefundValidationError("");
		}
	};

	const filteredRetailRefundItems = useMemo(() => {
		const query = retailRefundSearch.trim().toLowerCase();
		if (!query) return retailRefundItems;

		return retailRefundItems.filter((item) => (
			item.label.toLowerCase().includes(query)
			|| String(item.orderItemId).includes(query)
		));
	}, [retailRefundItems, retailRefundSearch]);

	const retailRefundSelection = useMemo(() => {
		const selectedLines = retailRefundItems
			.map((item) => {
				const draft = retailRefundDraftByOrderItem[item.orderItemId];
				const rawQty = Math.floor(Number(draft?.requestedQty ?? 0));
				const requestedQty = Number.isFinite(rawQty)
					? Math.max(0, Math.min(rawQty, item.remainingQty))
					: 0;

				return {
					order_item_id: item.orderItemId,
					requested_qty: requestedQty,
					inspection_disposition: draft?.inspectionDisposition === "damaged" ? "damaged" as const : "resellable" as const,
					line_amount: Number((requestedQty * item.unitPrice).toFixed(2)),
				};
			})
			.filter((line) => line.requested_qty > 0);

		const requestedAmount = Number(selectedLines.reduce((sum, line) => sum + line.line_amount, 0).toFixed(2));
		const selectedQtyByOrderItem = new Map<number, number>();
		selectedLines.forEach((line) => {
			selectedQtyByOrderItem.set(line.order_item_id, line.requested_qty);
		});

		const isFull = retailRefundItems.length > 0
			&& retailRefundItems.every((item) => selectedQtyByOrderItem.get(item.orderItemId) === item.remainingQty);
		const requestType: "full" | "partial" = isFull ? "full" : "partial";
		const exceedsBalance = requestedAmount > retailRefundableBalance + 0.001;

		return {
			selectedLines,
			requestedAmount,
			requestType,
			exceedsBalance,
			selectedItemCount: selectedLines.length,
		};
	}, [retailRefundDraftByOrderItem, retailRefundItems, retailRefundableBalance]);

	const retailRefundCanSubmit = useMemo(() => {
		if (retailRefundSubmitting) return false;
		if (!isRetailRefundModalOpen) return false;
		if (!retailRefundReceipt) return false;
		if (retailRefundTransactionId <= 0) return false;
		if (retailRefundSelection.selectedItemCount === 0) return false;
		if (retailRefundSelection.requestedAmount <= 0) return false;
		if (retailRefundSelection.exceedsBalance) return false;
		return true;
	}, [
		isRetailRefundModalOpen,
		retailRefundReceipt,
		retailRefundSelection.exceedsBalance,
		retailRefundSelection.requestedAmount,
		retailRefundSelection.selectedItemCount,
		retailRefundSubmitting,
		retailRefundTransactionId,
	]);

	const handleRetailPay = async () => {
		if (!retailCanPay) return;

		setRetailProcessingPayment(true);
		try {
			const idempotencyKey = `retail-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
			const checkoutResponse = await axios.post(
				"/api/retail-pos/checkout",
				{
					idempotency_key: idempotencyKey,
					customer_type: "walk_in",
					customer_id: null,
					walk_in_name: retailCustomerName.trim(),
					walk_in_phone: retailCustomerPhone.trim() || null,
					walk_in_email: retailCustomerEmail.trim() || null,
					items: retailCart.map((item) => ({
						product_id: item.productId,
						qty: item.qty,
						unit_price: Number(item.unitPrice.toFixed(2)),
						size: item.size ?? null,
						color: item.color ?? null,
						image: item.image ?? null,
					})),
					payment_lines: [
						{
							tender_type: mapTenderType(retailPaymentMethod),
							amount: Number(retailTotalDue.toFixed(2)),
							provider_reference: retailPaymentMethod === "cash" ? null : retailProofReference.trim(),
						},
					],
				},
				{
					headers: {
						"X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
						Accept: "application/json",
					},
					withCredentials: true,
				},
			);

			const transactionId = Number(checkoutResponse?.data?.data?.id ?? checkoutResponse?.data?.transaction_id ?? 0);
			const transactionNo = String(checkoutResponse?.data?.data?.transaction_no ?? checkoutResponse?.data?.transaction_no ?? "");

			let receiptPayload: any = null;
			if (transactionId > 0) {
				try {
					const receiptResponse = await axios.get(`/api/retail-pos/transactions/${transactionId}/receipt`, { withCredentials: true });
					receiptPayload = receiptResponse?.data?.data ?? null;
				} catch {
					// Keep local snapshot fallback if receipt endpoint is temporarily unavailable.
				}
			}

			const receiptNo = String(receiptPayload?.receipt_no || transactionNo || `POS-${Date.now()}`);
			const issuedAt = String(receiptPayload?.issued_at || new Date().toISOString());
			const receiptTotals = receiptPayload?.print_payload?.totals || {};
			const receiptLineItems = Array.isArray(receiptPayload?.print_payload?.items)
				? receiptPayload.print_payload.items
				: [];
			const mappedReceiptItems: POSItem[] = receiptLineItems.map((item: any, itemIndex: number) => ({
				id: String(item?.id ?? `receipt-item-${transactionId || "new"}-${itemIndex}`),
				label: String(item?.label ?? item?.name ?? item?.product_name ?? "Item"),
				qty: Math.max(0, Number(item?.qty ?? item?.quantity ?? 0)),
				unitPrice: Number(item?.unitPrice ?? item?.unit_price ?? item?.price ?? 0),
				orderItemId: Number(item?.order_item_id || 0) > 0 ? Number(item?.order_item_id) : undefined,
				source: "manual",
			}));

			const snapshot: ReceiptSnapshot = {
				moduleType: "retail",
				transactionId: transactionId > 0 ? transactionId : undefined,
				customerType: "walk_in",
				dueType: null,
				paidAmount: Number(receiptTotals?.paid ?? retailTotalDue),
				refundEntries: [],
				receiptNo,
				createdAtISO: issuedAt,
				dateLabel: new Date(issuedAt).toLocaleString("en-PH", {
					weekday: "short",
					month: "short",
					day: "2-digit",
					year: "numeric",
					hour: "2-digit",
					minute: "2-digit",
				}),
				cashierName,
				customerName: retailCustomerName.trim(),
				customerPhone: getPhoneDisplayForReceipt(retailPaymentMethod, retailCustomerPhone),
				paymentReference: retailPaymentMethod === "cash" ? null : retailProofReference.trim(),
				paymentMethod: retailPaymentMethod,
				notes: retailNotes,
				cashReceived: retailTenderedAmount,
				subtotal: Number(receiptTotals?.subtotal ?? retailBreakdown.netSubtotal),
				discount: Number(receiptTotals?.discount ?? 0),
				vatRate: VAT_RATE,
				vatAmount: Number(receiptTotals?.tax ?? retailVatAmount),
				totalDue: Number(receiptTotals?.total ?? retailTotalDue),
				change: retailChangeValue,
				items: mappedReceiptItems.length > 0
					? mappedReceiptItems
					: retailCart.map((item) => ({
						id: item.lineId,
						label: item.name,
						qty: item.qty,
						unitPrice: item.unitPrice,
						source: "manual",
					})),
			};

			setReceiptSnapshot(snapshot);
			setReceiptHistory((prev) => [snapshot, ...prev]);
			clearRetailTransaction();

			await Swal.fire({
				icon: "success",
				title: "Retail payment successful!",
				text: `Amount: ${formatPeso(snapshot.totalDue)}`,
				confirmButtonColor: "#10b981",
				confirmButtonText: "View Receipt",
			});

			setIsReceiptModalOpen(true);
		} catch (error: any) {
			const validationErrors = error?.response?.data?.errors || {};
			const firstValidationError = Object.values(validationErrors).flat()[0];
			const message = String(
				firstValidationError
				|| error?.response?.data?.message
				|| "Retail checkout failed. Please verify payment details and try again.",
			);
			await Swal.fire({
				icon: "error",
				title: "Checkout Failed",
				text: message,
				confirmButtonColor: "#dc2626",
			});
		} finally {
			setRetailProcessingPayment(false);
		}
	};

	const isPackageSelected = (pkg: ServicePackageOption): boolean => {
		const packageId = Number(pkg.id);
		if (!Number.isInteger(packageId) || packageId <= 0) return false;

		return items.some((item) => {
			return item.source === "package" && Number(item.manualRepairPackageId ?? 0) === packageId;
		});
	};

	const isServiceSelected = (service: RepairServiceOption): boolean => {
		const serviceId = Number(service.id);
		if (!Number.isInteger(serviceId) || serviceId <= 0) return false;

		return items.some((item) => {
			if (item.source !== "service-catalog" && item.source !== "package-add-on") {
				return false;
			}

			return (item.manualServiceIds ?? []).some((id) => Number(id) === serviceId);
		});
	};

	const filteredRepairOrders = useMemo(() => {
		const query = orderSearch.trim().toLowerCase();
		const attachableOrders = repairOrders
			.filter((order) => order.dueTypeToCollect !== null)
			.filter((order) => !hasRequestedDueType || order.dueTypeToCollect === requestedDueType);

		if (!query) return attachableOrders;
		return attachableOrders.filter((order) => {
			return (
				order.customer.toLowerCase().includes(query) ||
				order.service.toLowerCase().includes(query)
			);
		});
	}, [hasRequestedDueType, orderSearch, repairOrders, requestedDueType]);

	const filteredServiceCatalog = useMemo(() => {
		const query = serviceSearch.trim().toLowerCase();
		if (!query) return serviceCatalog;
		return serviceCatalog.filter((service) => {
			return (
				service.name.toLowerCase().includes(query) ||
				service.category.toLowerCase().includes(query)
			);
		});
	}, [serviceSearch, serviceCatalog]);

	const filteredReceiptHistory = useMemo(() => {
		const query = historySearch.trim().toLowerCase();
		return receiptHistory.filter((receipt) => {
			const isRetailEntry = receipt.moduleType === "retail";
			if (mode === "retail" && !isRetailEntry) return false;
			if (mode === "repair" && isRetailEntry) return false;

			const matchesDate = historyDate.length === 0 || toDateInputValue(receipt.createdAtISO) === historyDate;
			if (!matchesDate) return false;

			if (query.length === 0) return true;
			const haystack = [
				receipt.receiptNo,
				receipt.customerName,
				receipt.customerPhone,
				receipt.cashierName,
				receipt.paymentMethod,
				getDueTypeLabel(receipt.dueType),
				String(receipt.latestRefund?.status || ""),
				receipt.items.map((item) => item.label).join(" "),
			]
				.join(" ")
				.toLowerCase();

			return haystack.includes(query);
		});
	}, [historyDate, historySearch, mode, receiptHistory]);

	const repairRefundStateById = useMemo(() => {
		const state = new Map<number, { paidAmount: number; refundedAmount: number; hasOpenRequest: boolean }>();

		receiptHistory.forEach((receipt) => {
			const repairId = Number(receipt.repairRequestId ?? 0);
			if (repairId <= 0) {
				return;
			}

			const current = state.get(repairId) ?? {
				paidAmount: 0,
				refundedAmount: 0,
				hasOpenRequest: false,
			};

			current.paidAmount += Number(receipt.paidAmount ?? 0);

			receipt.refundEntries.forEach((entry) => {
				const status = String(entry.status || "").toLowerCase();

				if (["requested", "approved", "processing"].includes(status)) {
					current.hasOpenRequest = true;
				}

				if (status === "succeeded") {
					current.refundedAmount += Number(entry.approvedAmount ?? 0);
				}
			});

			state.set(repairId, current);
		});

		return state;
	}, [receiptHistory]);

	const canRequestRepairRefund = (receipt: ReceiptSnapshot): boolean => {
		if (hasOpenOrCompletedRefund(receipt)) {
			return false;
		}

		const repairId = Number(receipt.repairRequestId ?? 0);
		if (repairId <= 0) {
			return true;
		}

		const state = repairRefundStateById.get(repairId);
		if (!state) {
			return true;
		}

		if (state.hasOpenRequest) {
			return false;
		}

		const remaining = Math.max(0, Number((state.paidAmount - state.refundedAmount).toFixed(2)));
		return remaining > 0;
	};

	const canRequestWarrantyClaimFromReceipt = (receipt: ReceiptSnapshot): boolean => {
		if (receipt.moduleType === "retail") {
			return false;
		}

		if (receipt.customerType !== "walk_in") {
			return false;
		}

		if (Number(receipt.repairRequestId ?? 0) <= 0) {
			return false;
		}

		if (String(receipt.receiptNo || "").trim().length === 0) {
			return false;
		}

		if (!isWarrantyEligibleRepairStatus(receipt.repairStatus)) {
			return false;
		}

		return String(receipt.customerPhone || "").trim().length > 0;
	};

	const canRequestWarrantyClaimFromManualQueue = (row: ManualQueueRow): boolean => {
		if (Number(row.id ?? 0) <= 0) {
			return false;
		}

		if (String(row.receipt_no || "").trim().length === 0) {
			return false;
		}

		const normalizedStatus = String(row.status ?? "")
			.trim()
			.toLowerCase()
			.replace(/-/g, "_");

		if (normalizedStatus !== "picked_up") {
			return false;
		}

		return String(row.phone || "").trim().length > 0;
	};

	const resolveRefundRequestAmount = (receipt: ReceiptSnapshot): number => {
		const repairId = Number(receipt.repairRequestId ?? 0);
		if (repairId <= 0) {
			return Number(receipt.totalDue || 0);
		}

		const state = repairRefundStateById.get(repairId);
		if (!state) {
			return Number(receipt.totalDue || 0);
		}

		const remaining = Math.max(0, Number((state.paidAmount - state.refundedAmount).toFixed(2)));
		return remaining > 0 ? remaining : Number(receipt.totalDue || 0);
	};

	const totalServicePages = useMemo(() => {
		return Math.max(1, Math.ceil(filteredServiceCatalog.length / SERVICES_PER_PAGE));
	}, [filteredServiceCatalog.length]);

	const paginatedServiceCatalog = useMemo(() => {
		const start = (servicePage - 1) * SERVICES_PER_PAGE;
		return filteredServiceCatalog.slice(start, start + SERVICES_PER_PAGE);
	}, [filteredServiceCatalog, servicePage]);

	useEffect(() => {
		setServicePage(1);
	}, [serviceSearch]);

	useEffect(() => {
		if (servicePage > totalServicePages) {
			setServicePage(totalServicePages);
		}
	}, [servicePage, totalServicePages]);

	const removeItem = (id: string) => {
		setItems((prev) => {
			const target = prev.find((item) => item.id === id);
			if (!target) return prev;

			if (target.source === "package") {
				return prev.filter((item) => item.id !== id && item.source !== "package-add-on");
			}

			return prev.filter((item) => item.id !== id);
		});
	};

	const unselectManualPackage = () => {
		setItems((prev) => prev.filter((item) => item.source !== "package" && item.source !== "package-add-on"));
	};

	const clearTransaction = () => {
		resetOrderInputs();
		setReceiptSnapshot(null);
		setIsReceiptModalOpen(false);
	};

	const handlePay = async () => {
		if (!canPay) return;

		const hasRepairReference = Boolean(selectedRepairOrder || requestedRepairRequestId);
		const repairRequestId = hasRepairReference
			? Number(selectedRepairOrder?.id || requestedRepairRequestId)
			: null;

		if (hasRepairReference && (!Number.isFinite(Number(repairRequestId)) || Number(repairRequestId) <= 0)) {
			await Swal.fire({
				icon: "error",
				title: "Invalid Repair Request",
				text: "Unable to resolve repair request for POS checkout.",
				confirmButtonColor: "#dc2626",
			});
			return;
		}

		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
		const customerType = hasRepairReference && selectedRepairOrder?.customerId ? "registered" : "walk_in";
		const dueTypeForCheckout = hasRepairReference
			? (selectedRepairOrder?.dueTypeToCollect ?? effectiveDueType)
			: dueTypeForManualCheckout;
		const idempotencyKey = hasRepairReference
			? `repair-${repairRequestId}-${dueTypeForCheckout}-${Date.now()}`
			: `repair-manual-${Date.now()}`;
		const manualServiceSummary = items.map((item) => item.label).join(", ").slice(0, 1800);
		const manualRepairPackageId = hasRepairReference
			? null
			: (items.find((item) => item.source === "package" && Number.isInteger(item.manualRepairPackageId as number) && Number(item.manualRepairPackageId) > 0)?.manualRepairPackageId ?? null);
		const manualServiceIds = hasRepairReference
			? []
			: Array.from(
				new Set(
					items
						.flatMap((item) => item.manualServiceIds ?? [])
						.map((id) => Number(id))
						.filter((id) => Number.isInteger(id) && id > 0),
				),
			);

		setIsProcessingPayment(true);
		try {
			const checkoutResponse = await axios.post(
				"/api/repair-pos/checkout",
				{
					repair_request_id: hasRepairReference ? repairRequestId : null,
					due_type: dueTypeForCheckout,
					idempotency_key: idempotencyKey,
					customer_type: customerType,
					customer_id: hasRepairReference ? (selectedRepairOrder?.customerId ?? null) : null,
					walk_in_name: customerName.trim() || null,
					walk_in_phone: customerPhone.trim() || null,
					walk_in_email: hasRepairReference ? null : (customerEmail.trim() || null),
					manual_repair_subtotal: hasRepairReference ? null : Number(subtotal.toFixed(2)),
					manual_service_summary: hasRepairReference ? null : (manualServiceSummary || "Walk-in POS service"),
					manual_payment_policy: hasRepairReference ? null : shopRepairPaymentPolicy,
					manual_repair_package_id: hasRepairReference ? null : manualRepairPackageId,
					manual_service_ids: hasRepairReference ? [] : manualServiceIds,
					payment_lines: [
						{
							tender_type: mapTenderType(paymentMethod),
							amount: Number(totalDue.toFixed(2)),
							provider_reference: paymentMethod === "cash" ? null : proofReference.trim(),
						},
					],
				},
				{
					headers: {
						"X-CSRF-TOKEN": csrfToken,
						Accept: "application/json",
					},
					withCredentials: true,
				},
			);

			const transactionId = Number(checkoutResponse?.data?.transaction_id || 0);
			const transactionNo = String(checkoutResponse?.data?.transaction_no || "");

			let receiptPayload: any = null;
			if (transactionId > 0) {
				try {
					const receiptResponse = await axios.get(`/api/repair-pos/transactions/${transactionId}/receipt`, { withCredentials: true });
					receiptPayload = receiptResponse?.data?.data ?? null;
				} catch {
					// Keep local snapshot fallback if receipt endpoint is temporarily unavailable.
				}
			}

			const receiptNo = String(receiptPayload?.receipt_no || transactionNo || `POS-${Date.now()}`);
			const issuedAt = String(receiptPayload?.issued_at || new Date().toISOString());
			const receiptTotals = receiptPayload?.print_payload?.totals || {};

			const snapshot: ReceiptSnapshot = {
				transactionId: transactionId > 0 ? transactionId : undefined,
				repairRequestId: hasRepairReference && repairRequestId ? Number(repairRequestId) : undefined,
				customerType,
				dueType: dueTypeForCheckout,
				paidAmount: Number(receiptTotals?.paid ?? totalDue),
				refundEntries: [],
				receiptNo,
				createdAtISO: issuedAt,
				dateLabel: new Date(issuedAt).toLocaleString("en-PH", {
					weekday: "short",
					month: "short",
					day: "2-digit",
					year: "numeric",
					hour: "2-digit",
					minute: "2-digit",
				}),
				cashierName,
				customerName: customerName.trim(),
				customerPhone: getPhoneDisplayForReceipt(paymentMethod, customerPhone),
				paymentReference: paymentMethod === "cash" ? null : proofReference.trim(),
				paymentMethod,
				notes,
				cashReceived: tenderedAmount,
				subtotal: Number(receiptTotals?.subtotal ?? dueBreakdown.netSubtotal),
				discount: Number(receiptTotals?.discount ?? discount),
				vatRate: VAT_RATE,
				vatAmount: Number(receiptTotals?.tax ?? vatAmount),
				totalDue: Number(receiptTotals?.total ?? totalDue),
				change: changeValue,
				items: [...items],
			};

			setReceiptSnapshot(snapshot);
			setReceiptHistory((prev) => [snapshot, ...prev]);
			if (hasRepairReference && repairRequestId) {
				setRepairOrders((prev) => prev.filter((entry) => entry.id !== String(repairRequestId)));
			}
			await fetchManualQueue(manualQueueSearch);
			resetOrderInputs();

			await Swal.fire({
				icon: "success",
				title: "Payment Successful!",
				text: `Amount: ${formatPeso(snapshot.totalDue)}`,
				confirmButtonColor: "#10b981",
				confirmButtonText: "View Receipt",
			});

			setIsReceiptModalOpen(true);
		} catch (error: any) {
			const apiErrors = error?.response?.data?.errors || {};
			const dueTypeError = apiErrors?.due_type?.[0];
			const normalizedDueTypeError = String(dueTypeError || "").toUpperCase();
			const message =
				normalizedDueTypeError === "PAYMENT_PHASE_ALREADY_SETTLED"
					? "This payment phase is already settled for this repair request."
					: dueTypeError
				|| apiErrors?.payment_lines?.[0]
				|| apiErrors?.["payment_lines.0.provider_reference"]?.[0]
				|| error?.response?.data?.message
				|| "POS checkout failed. Please verify payment details and try again.";

			await Swal.fire({
				icon: "error",
				title: "Checkout Failed",
				text: message,
				confirmButtonColor: "#dc2626",
			});
		} finally {
			setIsProcessingPayment(false);
		}
	};

	const printReceipt = () => {
		if (!receiptSnapshot) return;
		setIsReceiptModalOpen(true);
		window.print();
	};

	const handleRetailRefund = async (receipt: ReceiptSnapshot) => {
		if (!canRequestRetailRefund(receipt)) {
			await Swal.fire({
				icon: "info",
				title: "Refund Unavailable",
				text: "This receipt has no refundable balance or has an active refund request.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const transactionId = Number(receipt.transactionId ?? 0);
		const refundableBalance = resolveRetailRefundRequestAmount(receipt);
		if (transactionId <= 0 || refundableBalance <= 0) {
			await Swal.fire({
				icon: "warning",
				title: "Refund Unavailable",
				text: "Unable to resolve transaction reference or refundable amount.",
				confirmButtonColor: "#b45309",
			});
			return;
		}

		const committedQtyByOrderItem = resolveCommittedRetailRefundQtyByOrderItem(receipt);
		const refundableItems: RetailRefundSelectableItem[] = receipt.items
			.map((item) => {
				const orderItemId = Number(item.orderItemId ?? 0);
				const purchasedQty = Math.max(0, Number(item.qty ?? 0));
				const unitPrice = Math.max(0, Number(item.unitPrice ?? 0));
				const committedQty = Math.max(0, Number(committedQtyByOrderItem.get(orderItemId) || 0));
				const remainingQty = Math.max(0, purchasedQty - committedQty);

				return {
					orderItemId,
					label: String(item.label || "Retail Item"),
					purchasedQty,
					committedQty,
					remainingQty,
					unitPrice,
				};
			})
			.filter((item) => item.orderItemId > 0 && item.remainingQty > 0 && item.unitPrice > 0);

		if (refundableItems.length === 0) {
			await Swal.fire({
				icon: "warning",
				title: "Refund Unavailable",
				text: "No refundable line items were found for this retail receipt.",
				confirmButtonColor: "#b45309",
			});
			return;
		}

		const initialDrafts = refundableItems.reduce<Record<number, RetailRefundDraft>>((acc, item) => {
			acc[item.orderItemId] = {
				requestedQty: 0,
				inspectionDisposition: "resellable",
			};
			return acc;
		}, {});

		setRetailRefundReceipt(receipt);
		setRetailRefundTransactionId(transactionId);
		setRetailRefundableBalance(refundableBalance);
		setRetailRefundItems(refundableItems);
		setRetailRefundDraftByOrderItem(initialDrafts);
		setRetailRefundSearch("");
		setRetailRefundValidationError("");
		setRetailRefundSubmitting(false);
		setIsRetailRefundModalOpen(true);
	};

	const submitRetailRefund = async () => {
		if (!retailRefundReceipt || retailRefundTransactionId <= 0) {
			setRetailRefundValidationError("Unable to resolve retail receipt context.");
			return;
		}

		if (retailRefundSelection.selectedItemCount === 0) {
			setRetailRefundValidationError("Select at least one line with refund qty greater than zero.");
			return;
		}

		if (retailRefundSelection.requestedAmount <= 0) {
			setRetailRefundValidationError("Computed refund amount must be greater than zero.");
			return;
		}

		if (retailRefundSelection.exceedsBalance) {
			setRetailRefundValidationError("Computed refund amount exceeds refundable balance.");
			return;
		}

		const requestType = retailRefundSelection.requestType;
		const requestedAmount = Number(retailRefundSelection.requestedAmount.toFixed(2));
		const refundLines = retailRefundSelection.selectedLines
			.map(({ line_amount, ...line }) => line);

		let createdRefundId = 0;
		let latestStatus = "requested";
		const reasonNotes = [
			"Requested from Retail POS receipt history.",
			`Receipt: ${retailRefundReceipt.receiptNo}`,
			`Request type: ${requestType}`,
			`Requested amount: ${requestedAmount.toFixed(2)}`,
			`Requested lines: ${refundLines.length}`,
		]
			.filter(Boolean)
			.join("\n");

		setRetailRefundValidationError("");
		setRetailRefundSubmitting(true);
		try {
			const createResponse = await axios.post(
				"/api/retail-pos/refunds",
				{
					source_transaction_id: retailRefundTransactionId,
					request_type: requestType,
					requested_amount: requestedAmount,
					refund_lines: refundLines,
					reason_code: "retail_pos_item_issue",
					reason_notes: reasonNotes,
				},
				{ withCredentials: true },
			);

			createdRefundId = Number((createResponse.data as any)?.refund_id ?? 0);
			latestStatus = String((createResponse.data as any)?.data?.status ?? "requested").toLowerCase() || "requested";

			if (createdRefundId <= 0) {
				throw new Error("Refund request was created without a valid reference.");
			}

			await axios.post(
				`/api/retail-pos/refunds/${createdRefundId}/approve`,
				{
					approved_amount: requestedAmount,
					approval_note: "Approved from Retail POS shop owner history.",
				},
				{ withCredentials: true },
			);

			const executeResponse = await axios.post(
				`/api/retail-pos/refunds/${createdRefundId}/execute`,
				{
					execution_mode: "manual",
					execution_note: "Executed from Retail POS shop owner history.",
				},
				{ withCredentials: true },
			);

			latestStatus = String((executeResponse.data as any)?.data?.status ?? "succeeded").toLowerCase() || "succeeded";
			const approvedAmount = Number((executeResponse.data as any)?.data?.approved_amount ?? requestedAmount);
			const committedLines: ReceiptRefundEntryItem[] = retailRefundSelection.selectedLines
				.map((line) => ({
					orderItemId: Number(line.order_item_id || 0),
					requestedQty: Math.max(0, Number(line.requested_qty || 0)),
					approvedQty: Math.max(0, Number(line.requested_qty || 0)),
				}))
				.filter((line: ReceiptRefundEntryItem) => line.orderItemId > 0 && line.approvedQty > 0);

			setReceiptHistory((prev) => prev.map((entry) => (
				entry.receiptNo === retailRefundReceipt.receiptNo
					? {
						...entry,
						refundEntries: [
							{
								status: latestStatus,
								approvedAmount,
								items: committedLines,
							},
							...entry.refundEntries,
						],
						latestRefund: {
							id: createdRefundId,
							status: latestStatus,
						},
					}
					: entry
			)));

			resetRetailRefundModalState();
			await Swal.fire({
				icon: "success",
				title: "Retail Refund Completed",
				text: `Refunded ${formatPeso(approvedAmount)} successfully.`,
				confirmButtonColor: "#10b981",
			});
		} catch (error: any) {
			if (createdRefundId > 0) {
				setReceiptHistory((prev) => prev.map((entry) => (
					entry.receiptNo === retailRefundReceipt.receiptNo
						? {
							...entry,
							latestRefund: {
								id: createdRefundId,
								status: latestStatus || "requested",
							},
						}
						: entry
				)));
			}

			const message = error?.response?.data?.message || error?.message || "Unable to process retail refund.";
			setRetailRefundValidationError(String(message));
			await Swal.fire({
				icon: "error",
				title: "Retail Refund Failed",
				text: String(message),
				confirmButtonColor: "#dc2626",
			});
		} finally {
			setRetailRefundSubmitting(false);
		}
	};

	const handleRequestRefund = async (receipt: ReceiptSnapshot) => {
		if (receipt.moduleType === "retail") {
			await handleRetailRefund(receipt);
			return;
		}

		if (!canRequestRepairRefund(receipt)) {
			await Swal.fire({
				icon: "info",
				title: "Refund Already Exists",
				text: "A refund is already in progress/completed for this receipt or its repair request.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const transactionId = Number(receipt.transactionId ?? 0);
		if (transactionId <= 0) {
			await Swal.fire({
				icon: "warning",
				title: "Refund Unavailable",
				text: "This record has no linked transaction reference.",
				confirmButtonColor: "#b45309",
			});
			return;
		}

		const requestedAmount = resolveRefundRequestAmount(receipt);
		const refundConfirmation = await Swal.fire({
			icon: "warning",
			title: "Confirm Refund Request",
			html: `This will request a refund for <b>${formatPeso(requestedAmount)}</b>.<br/>For individual shops, this may complete immediately.`,
			showCancelButton: true,
			confirmButtonText: "Yes, Continue",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#dc2626",
		});

		if (!refundConfirmation.isConfirmed) {
			return;
		}

		try {
			const response = await repairPosHistoryApi.requestRefund({
				source_transaction_id: transactionId,
				request_type: "full",
				requested_amount: requestedAmount,
				reason_code: "shop_owner_requested_refund",
				reason_notes: "Requested from Shop Owner POS receipt history.",
				receipt_no: receipt.receiptNo,
			});

			const createdRefundId = Number((response.data as any)?.refund_id ?? 0);
			const responseRequestedAmount = Number((response.data as any)?.data?.requested_amount ?? requestedAmount);
			const responseApprovedAmount = Number((response.data as any)?.data?.approved_amount ?? responseRequestedAmount);
			const apiRefundStatus = String((response.data as any)?.data?.status ?? "").toLowerCase();
			const wasAutoProcessed = Boolean((response.data as any)?.auto_processed);
			const nextStatus = apiRefundStatus !== ""
				? apiRefundStatus
				: (wasAutoProcessed ? "succeeded" : "requested");
			setReceiptHistory((prev) => prev.map((entry) => (
				entry.receiptNo === receipt.receiptNo
					? {
						...entry,
						refundEntries: [
							{
								status: nextStatus,
								approvedAmount: nextStatus === "succeeded" ? responseApprovedAmount : 0,
							},
							...entry.refundEntries,
						],
						latestRefund: {
							id: createdRefundId > 0 ? createdRefundId : Number(entry.latestRefund?.id ?? 0),
							status: nextStatus,
						},
					}
					: entry
			)));

			await Swal.fire({
				icon: "success",
				title: wasAutoProcessed ? "Refund Completed" : "Refund Requested",
				text: wasAutoProcessed
					? "Refund was processed immediately for this individual shop."
					: "Refund request submitted for approval workflow.",
				confirmButtonColor: "#10b981",
			});
		} catch (error: any) {
			const message = error?.response?.data?.message || "Unable to create refund request.";
			await Swal.fire({
				icon: "error",
				title: "Request Failed",
				text: message,
				confirmButtonColor: "#dc2626",
			});
		}
	};

	const handleRequestWarrantyClaim = async (payload: {
		repairRequestId?: number;
		receiptNo?: string | null;
		walkInPhone?: string | null;
	}) => {
		const repairRequestId = Number(payload.repairRequestId ?? 0);
		const receiptNo = String(payload.receiptNo ?? '').trim();
		const walkInPhone = String(payload.walkInPhone ?? '').trim();

		if (repairRequestId <= 0 || receiptNo.length === 0 || walkInPhone.length === 0) {
			await Swal.fire({
				icon: 'warning',
				title: 'Warranty Claim Unavailable',
				text: 'Missing repair reference, receipt number, or customer phone.',
				confirmButtonColor: '#b45309',
			});
			return;
		}

		const modal = await Swal.fire({
			title: 'File Warranty Claim',
			html: `
				<div style="text-align:left; display:flex; flex-direction:column; gap:12px;">
					<div>
						<label for="shop_owner_pos_warranty_reason_code" style="display:block; font-size:12px; font-weight:700; margin-bottom:6px;">Reason</label>
						<select id="shop_owner_pos_warranty_reason_code" class="swal2-input" style="margin:0; width:100%;">
							<option value="issue_returned" selected>Issue returned</option>
							<option value="same_defect">Same defect not fixed</option>
							<option value="poor_workmanship">Workmanship concern</option>
							<option value="other">Other</option>
						</select>
					</div>
					<div>
						<label for="shop_owner_pos_warranty_reason_details" style="display:block; font-size:12px; font-weight:700; margin-bottom:6px;">Details</label>
						<textarea id="shop_owner_pos_warranty_reason_details" class="swal2-textarea" style="margin:0; width:100%;" placeholder="Describe the issue..."></textarea>
					</div>
					<div>
						<label for="shop_owner_pos_warranty_return_method" style="display:block; font-size:12px; font-weight:700; margin-bottom:6px;">Preferred Return Method</label>
						<select id="shop_owner_pos_warranty_return_method" class="swal2-input" style="margin:0; width:100%;">
							<option value="walk_in" selected>Walk-in</option>
							<option value="customer_delivery">Customer Delivery</option>
						</select>
					</div>
					<div>
						<label for="shop_owner_pos_warranty_images" style="display:block; font-size:12px; font-weight:700; margin-bottom:6px;">Evidence Images</label>
						<input id="shop_owner_pos_warranty_images" type="file" accept="image/jpeg,image/jpg,image/png,image/webp" multiple style="display:block; width:100%;" />
						<p style="margin-top:6px; font-size:11px; color:#6b7280;">Upload 1 to 10 images (JPEG/PNG/WEBP, max 20MB each).</p>
					</div>
				</div>
			`,
			showCancelButton: true,
			confirmButtonText: 'Submit Claim',
			cancelButtonText: 'Cancel',
			confirmButtonColor: '#2563eb',
			focusConfirm: false,
			preConfirm: () => {
				const reasonCode = (document.getElementById('shop_owner_pos_warranty_reason_code') as HTMLSelectElement | null)?.value?.trim() || '';
				const reasonDetails = (document.getElementById('shop_owner_pos_warranty_reason_details') as HTMLTextAreaElement | null)?.value?.trim() || '';
				const preferredReturnMethod = (document.getElementById('shop_owner_pos_warranty_return_method') as HTMLSelectElement | null)?.value?.trim() || 'walk_in';
				const files = Array.from((document.getElementById('shop_owner_pos_warranty_images') as HTMLInputElement | null)?.files || []);

				if (!reasonCode) {
					Swal.showValidationMessage('Please select a reason.');
					return null;
				}

				if (reasonCode === 'other' && reasonDetails.length === 0) {
					Swal.showValidationMessage('Please provide details for Other reason.');
					return null;
				}

				if (files.length === 0) {
					Swal.showValidationMessage('Please upload at least one image.');
					return null;
				}

				if (files.length > 10) {
					Swal.showValidationMessage('You can upload a maximum of 10 images.');
					return null;
				}

				return {
					reasonCode,
					reasonDetails,
					preferredReturnMethod: preferredReturnMethod === 'customer_delivery' ? 'customer_delivery' : 'walk_in',
					files,
				};
			},
		});

		if (!modal.isConfirmed || !modal.value) {
			return;
		}

		try {
			await repairPosHistoryApi.requestWarrantyClaim({
				repair_request_id: repairRequestId,
				receipt_no: receiptNo,
				walk_in_phone: walkInPhone,
				reason_code: modal.value.reasonCode,
				reason_details: modal.value.reasonDetails,
				preferred_return_method: modal.value.preferredReturnMethod,
				images: modal.value.files,
			});

			await Swal.fire({
				icon: 'success',
				title: 'Warranty Claim Submitted',
				text: 'Warranty claim has been filed for repairer review.',
				confirmButtonColor: '#10b981',
			});
		} catch (error: any) {
			await Swal.fire({
				icon: 'error',
				title: 'Submission Failed',
				text: error?.response?.data?.message || 'Unable to submit warranty claim.',
				confirmButtonColor: '#dc2626',
			});
		}
	};

	const getRefundStatusClass = (status: string): string => {
		switch (status) {
			case 'succeeded': return 'bg-emerald-100 text-emerald-700';
			case 'failed':
			case 'rejected': return 'bg-red-100 text-red-700';
			case 'approved': return 'bg-blue-100 text-blue-700';
			case 'processing': return 'bg-amber-100 text-amber-700';
			default: return 'bg-slate-100 text-slate-700';
		}
	};

	const performRefundAction = async (refundId: number, action: 'approve' | 'reject' | 'execute', payload: Record<string, unknown>) => {
		const actionLabel = action === 'execute' ? 'execute' : action;
		const confirmation = await Swal.fire({
			icon: action === 'reject' ? 'warning' : 'question',
			title: `Confirm ${actionLabel}`,
			text: `Are you sure you want to ${actionLabel} this refund request?`,
			showCancelButton: true,
			confirmButtonText: `Yes, ${actionLabel}`,
			cancelButtonText: 'Cancel',
			confirmButtonColor: action === 'reject' ? '#dc2626' : '#2563eb',
		});

		if (!confirmation.isConfirmed) {
			return;
		}

		setProcessingRefundId(refundId);
		try {
			await axios.post(`/api/repair-pos/refunds/${refundId}/${action}`, payload, { withCredentials: true });
			await fetchRefundQueue();
			await Swal.fire({
				icon: 'success',
				title: `Refund ${action === 'execute' ? 'executed' : `${action}d`}`,
				confirmButtonColor: '#2563eb',
			});
		} catch (error: any) {
			const message = error?.response?.data?.message || 'Unable to process refund action.';
			await Swal.fire({
				icon: 'error',
				title: 'Action failed',
				text: message,
				confirmButtonColor: '#dc2626',
			});
		} finally {
			setProcessingRefundId(null);
		}
	};

	const advanceManualQueueStatus = async (row: ManualQueueRow) => {
		const nextStatus = MANUAL_QUEUE_NEXT_STATUS[row.status];
		if (!nextStatus) return;

		setManualQueueActionLoadingId(row.id);
		try {
			await axios.patch(`/api/repair-pos/manual-queue/${row.id}/status`, { status: nextStatus }, { withCredentials: true });
			await fetchManualQueue(manualQueueSearch);
			await Swal.fire({
				icon: 'success',
				title: 'Status updated',
				timer: 1200,
				showConfirmButton: false,
			});
		} catch (error: any) {
			await Swal.fire({
				icon: 'error',
				title: 'Status update failed',
				text: error?.response?.data?.message || 'Please try again.',
				confirmButtonColor: '#dc2626',
			});
		} finally {
			setManualQueueActionLoadingId(null);
		}
	};

	const continueManualQueuePayment = (row: ManualQueueRow) => {
		if (!row.next_due_type) return;

		const dueAmount = row.next_due_type === "deposit"
			? Math.round((Number(row.total) * 0.5) * 100) / 100
			: row.next_due_type === "balance"
				? Number(row.remaining_balance)
				: Number(row.remaining_balance > 0 ? row.remaining_balance : row.total);

		if (!(dueAmount > 0)) {
			return;
		}

		setSelectedRepairOrder({
			id: String(row.id),
			customer: row.customer_name,
			customerId: null,
			paymentPolicy: row.payment_policy,
			paymentStatus: row.remaining_balance <= 0 ? "completed" : (row.paid > 0 ? "paid" : "unpaid"),
			status: row.status,
			returnDeliveryMethod: "walk_in",
			dueTypeToCollect: row.next_due_type,
			service: row.request_id,
			amount: Number(row.total),
			requestedServices: [row.request_id],
		});

		setCustomerName(row.customer_name || "Walk-in Customer");
		setCustomerPhone(row.phone || "");
		setCustomerEmail("");
		setItems([
			{
				id: `manual-queue-${row.id}-${row.next_due_type}`,
				label: `${row.request_id} (${row.next_due_type})`,
				qty: 1,
				unitPrice: dueAmount,
				source: "repair-order",
			},
		]);
	};

	return (
		<AppLayoutShopOwner hideHeader={isOrderModalOpen || isRefundQueueOpen || isReceiptModalOpen || isHistoryModalOpen || isRetailRefundModalOpen}>
			<Head title="Point of Sale" />

			<style>{`
				@media print {
					@page {
						size: A4;
						margin: 12mm;
					}

					body * {
						visibility: hidden !important;
					}

					body {
						background: #fff !important;
					}

					.pos-print-area,
					.pos-print-area * {
						visibility: visible !important;
					}

					.pos-print-area {
						position: static !important;
						inset: auto !important;
						width: 100% !important;
						max-width: none !important;
						min-height: calc(297mm - 24mm);
						padding: 16mm !important;
						margin: 0 !important;
						background: #fff !important;
						border: 0 !important;
						border-radius: 0 !important;
						box-shadow: none !important;
					}

					.receipt-modal-actions {
						display: none !important;
					}
				}
			`}</style>

			<div className="space-y-6 p-4 md:p-6">
				{!isOrderModalOpen && !isRefundQueueOpen && !isReceiptModalOpen && !isHistoryModalOpen && !isRetailRefundModalOpen && (
				<div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
					<div>
						<h1 className="text-2xl font-bold text-slate-900">Point of Sale</h1>
						<p className="mt-1 text-sm text-slate-500">
						{mode === "repair"
							? "Manage repair cashier transactions and payment processing."
							: "Process retail walk-in sales with the same POS design system."
						}
					</p>
					</div>
					<div className="flex flex-wrap items-center justify-start gap-2 xl:justify-end">
						{allowedModes.includes("repair") && (
							<button
								type="button"
								onClick={() => setMode("repair")}
								className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${
									mode === "repair"
										? "bg-slate-900 text-white"
										: "border border-slate-300 text-slate-700 hover:bg-slate-50"
									}`}
							>
								Repair Mode
							</button>
						)}
						{allowedModes.includes("retail") && (
							<button
								type="button"
								onClick={() => setMode("retail")}
								className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${
									mode === "retail"
										? "bg-slate-900 text-white"
										: "border border-slate-300 text-slate-700 hover:bg-slate-50"
									}`}
							>
								Retail Mode
							</button>
						)}
						<button
							type="button"
							onClick={() => setIsRefundQueueOpen(true)}
							className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
						>
							Refund Queue
						</button>
						<button
							type="button"
							onClick={() => setIsHistoryModalOpen(true)}
							className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
						>
							History
						</button>
						{mode === "repair" && allowedModes.includes("repair") && (
							<button
								type="button"
								onClick={() => setIsOrderModalOpen(true)}
								className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-500"
							>
								Open Order Picker
							</button>
						)}
					</div>
				</div>
				)}

				{mode === "retail" ? (
				<div data-testid="retail-pos-mode" className="grid grid-cols-1 gap-6 xl:h-[calc(100vh-170px)] xl:grid-cols-12 xl:items-stretch">
					<section className="space-y-6 xl:col-span-8 xl:flex xl:h-full xl:flex-col xl:space-y-0 xl:gap-6">
						<div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
							<h2 className="mb-2 text-base font-semibold text-slate-900">Customer Information</h2>
							<p className="mb-3 text-xs text-slate-500">Capture walk-in details before checkout.</p>
							<div className="grid grid-cols-1 gap-2 md:grid-cols-3">
								<input
									title="Retail customer name"
									value={retailCustomerName}
									onChange={(event) => setRetailCustomerName(event.target.value)}
									placeholder="Customer name"
									className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
								/>
								<input
									title="Retail customer phone"
									type="text"
									inputMode="numeric"
									pattern="[0-9]*"
									maxLength={11}
									value={retailCustomerPhone}
									onChange={(event) => setRetailCustomerPhone(toDigitsOnly(event.target.value).slice(0, 11))}
									placeholder="Phone number"
									className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
								/>
								<input
									title="Retail customer email"
									type="email"
									value={retailCustomerEmail}
									onChange={(event) => setRetailCustomerEmail(event.target.value)}
									placeholder="Email (optional)"
									className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
								/>
							</div>
						</div>

						<div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:flex-1 xl:min-h-0 xl:flex xl:flex-col">
							<div className="mb-4 flex items-center justify-between">
								<div>
									<h2 className="text-lg font-semibold text-slate-900">Retail Product Catalog</h2>
									<p className="mt-1 text-xs text-slate-500">Tap products to add to current order.</p>
								</div>
								<button
									type="button"
									onClick={() => fetchRetailProducts(retailSearch)}
									className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
								>
									Refresh
								</button>
							</div>
							<div className="mb-4 flex items-center gap-2">
								<input
									title="Search retail products"
									value={retailSearch}
									onChange={(event) => setRetailSearch(event.target.value)}
									placeholder="Search product name"
									className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
								/>
								<button
									type="button"
									onClick={() => fetchRetailProducts(retailSearch)}
									className="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
								>
									Search
								</button>
							</div>

							{retailLoading ? (
								<div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">Loading retail products...</div>
							) : retailProducts.length === 0 ? (
								<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">No retail products found for this shop.</div>
							) : (
								<div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 xl:flex-1 xl:min-h-0 xl:content-start xl:overflow-y-auto xl:pr-1">
									{retailProducts.map((product) => {
										const isSelected = retailCart.some((entry) => entry.productId === product.id);
										const inCartQty = retailCart
											.filter((entry) => entry.productId === product.id)
											.reduce((sum, entry) => sum + entry.qty, 0);
										const selection = getRetailSelectionForProduct(product);
										const colorOptions = Array.from(new Set(
											(product.variants.some((variant) => variant.stock > 0)
												? product.variants.filter((variant) => variant.stock > 0)
												: product.variants)
												.map((variant) => variant.color)
												.filter((color) => color.length > 0),
										));
										const selectedColor = colorOptions.find((color) => normalizeVariantToken(color) === normalizeVariantToken(selection.color))
											?? colorOptions[0]
											?? selection.color;
										const variantsForSelectedColor = product.variants.filter((variant) => (
											normalizeVariantToken(variant.color) === normalizeVariantToken(selectedColor)
										));
										const sizeSourceVariants = variantsForSelectedColor.length > 0
											? (variantsForSelectedColor.some((variant) => variant.stock > 0)
												? variantsForSelectedColor.filter((variant) => variant.stock > 0)
												: variantsForSelectedColor)
											: (product.variants.some((variant) => variant.stock > 0)
												? product.variants.filter((variant) => variant.stock > 0)
												: product.variants);
										const sizeOptions = Array.from(new Set(sizeSourceVariants.map((variant) => variant.size).filter((size) => size.length > 0)));
										const selectedSize = sizeOptions.find((size) => normalizeVariantToken(size) === normalizeVariantToken(selection.size))
											?? sizeOptions[0]
											?? selection.size;
										const selectedVariant = resolveRetailVariant(product, selectedSize, selectedColor);
										const selectedStock = selectedVariant ? selectedVariant.stock : product.stock;

										return (
											<div
												key={product.id}
												className={`h-56 rounded-xl border p-4 text-left transition ${
												selectedStock > 0
													? "border-slate-200 bg-slate-50"
													: "border-slate-200 bg-slate-100 opacity-80"
											}`}
											>
												<div className="flex h-full flex-col">
													<div className="flex items-start justify-between">
														<span className="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-semibold uppercase text-slate-600">
															{selectedStock > 0 ? `${selectedStock} in stock` : "Out of stock"}
														</span>
														<span className={`rounded-full px-2 py-1 text-[10px] font-semibold ${isSelected ? "bg-blue-600 text-white" : "bg-slate-100 text-slate-600"}`}>
															{isSelected ? `In cart (${inCartQty})` : "Tap to add"}
														</span>
													</div>
													<p className="mt-3 line-clamp-2 text-xl font-semibold text-slate-900">{product.name}</p>
													{product.variants.length > 0 && (
														<div className="mt-2 grid grid-cols-2 gap-2">
															<select
																title={`Select size for ${product.name}`}
																value={selectedSize}
																onChange={(event) => {
																	const nextSize = event.target.value;
																	const hasCurrentColorForSize = product.variants.some((variant) => (
																		normalizeVariantToken(variant.size) === normalizeVariantToken(nextSize)
																		&& normalizeVariantToken(variant.color) === normalizeVariantToken(selectedColor)
																	));
																	const firstColorForSize = product.variants.find((variant) => normalizeVariantToken(variant.size) === normalizeVariantToken(nextSize) && variant.stock > 0)?.color
																		?? product.variants.find((variant) => normalizeVariantToken(variant.size) === normalizeVariantToken(nextSize))?.color
																		?? "";
																	updateRetailSelection(product.id, {
																		size: nextSize,
																		color: hasCurrentColorForSize ? selectedColor : firstColorForSize,
																	});
																}}
																className="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-700 outline-none focus:border-blue-500"
															>
																{sizeOptions.map((size) => (
																		<option key={size} value={size}>{size}</option>
																))}
															</select>
															<select
																title={`Select color for ${product.name}`}
																value={selectedColor}
																onChange={(event) => {
																	const nextColor = event.target.value;
																	const hasCurrentSizeForColor = product.variants.some((variant) => (
																		normalizeVariantToken(variant.color) === normalizeVariantToken(nextColor)
																		&& normalizeVariantToken(variant.size) === normalizeVariantToken(selectedSize)
																	));
																	const firstSizeForColor = product.variants.find((variant) => normalizeVariantToken(variant.color) === normalizeVariantToken(nextColor) && variant.stock > 0)?.size
																		?? product.variants.find((variant) => normalizeVariantToken(variant.color) === normalizeVariantToken(nextColor))?.size
																		?? "";
																	updateRetailSelection(product.id, {
																		color: nextColor,
																		size: hasCurrentSizeForColor ? selectedSize : firstSizeForColor,
																	});
																}}
																className="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-700 outline-none focus:border-blue-500"
															>
																{colorOptions.map((color) => (
																		<option key={color} value={color}>{color}</option>
																	))}
															</select>
															</div>
													)}
													<div className="mt-auto flex items-center justify-between border-t border-slate-200 pt-3">
														<p className="text-2xl font-bold text-slate-900">{formatPeso(product.price)}</p>
														<button
															type="button"
															onClick={() => addRetailProductToCart(product)}
															disabled={selectedStock <= 0}
															className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"
														>
															Add
														</button>
													</div>
												</div>
											</div>
										);
									})}
								</div>
							)}
						</div>
					</section>

					<section className="space-y-6 xl:col-span-4 xl:h-full">
						<div className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:h-full">
							<div className="flex items-center justify-between">
								<h2 className="text-lg font-semibold text-slate-900">Current Order</h2>
								{retailCart.length > 0 && <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">{retailCart.length} item(s)</span>}
							</div>

							<div className="flex-1 min-h-0 space-y-2 overflow-y-auto pr-1">
								{retailCart.length === 0 ? (
									<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center text-xs text-slate-500">No products in order yet.</div>
								) : (
									retailCart.map((item) => (
										<div key={item.lineId} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
											<div className="mb-2 flex items-start justify-between gap-2">
												<p className="text-sm font-medium text-slate-900">{item.name}</p>
												<button
													type="button"
													onClick={() => removeRetailCartItem(item.lineId)}
													title="Remove product"
													aria-label="Remove product"
													className="rounded-md p-1 text-red-600 transition hover:bg-red-50 hover:text-red-500"
												>
													<svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
														<path d="M3 6h18" />
														<path d="M8 6V4h8v2" />
														<path d="M19 6l-1 14H6L5 6" />
														<path d="M10 11v6" />
														<path d="M14 11v6" />
													</svg>
												</button>
											</div>
											<div className="mb-2 grid grid-cols-2 gap-2">
												{(() => {
													const sourceProduct = retailProducts.find((entry) => entry.id === item.productId);
													if (!sourceProduct || sourceProduct.variants.length === 0) return null;

													const colorOptions = Array.from(new Set(
														(sourceProduct.variants.some((variant) => variant.stock > 0)
															? sourceProduct.variants.filter((variant) => variant.stock > 0)
															: sourceProduct.variants)
														.map((variant) => variant.color)
														.filter((color) => color.length > 0),
													));
													const selectedColor = colorOptions.find((color) => normalizeVariantToken(color) === normalizeVariantToken(item.color ?? ""))
														?? colorOptions[0]
														?? item.color
														?? "";
													const variantsForSelectedColor = sourceProduct.variants.filter((variant) => (
														normalizeVariantToken(variant.color) === normalizeVariantToken(selectedColor)
													));
													const sizeSourceVariants = variantsForSelectedColor.length > 0
														? (variantsForSelectedColor.some((variant) => variant.stock > 0)
															? variantsForSelectedColor.filter((variant) => variant.stock > 0)
															: variantsForSelectedColor)
														: (sourceProduct.variants.some((variant) => variant.stock > 0)
															? sourceProduct.variants.filter((variant) => variant.stock > 0)
															: sourceProduct.variants);
													const sizeOptions = Array.from(new Set(sizeSourceVariants.map((variant) => variant.size).filter((size) => size.length > 0)));
													const selectedSize = sizeOptions.find((size) => normalizeVariantToken(size) === normalizeVariantToken(item.size ?? ""))
														?? sizeOptions[0]
														?? "";

													return (
														<>
															<select
																title={`Cart size for ${item.name}`}
																value={selectedSize}
																onChange={(event) => {
																	const nextSize = event.target.value;
																	const hasCurrentColorForSize = sourceProduct.variants.some((variant) => (
																		normalizeVariantToken(variant.size) === normalizeVariantToken(nextSize)
																		&& normalizeVariantToken(variant.color) === normalizeVariantToken(selectedColor)
																	));
																	const nextColor = sourceProduct.variants.find((variant) => normalizeVariantToken(variant.size) === normalizeVariantToken(nextSize) && variant.stock > 0)?.color
																		?? sourceProduct.variants.find((variant) => normalizeVariantToken(variant.size) === normalizeVariantToken(nextSize))?.color
																		?? "";
																	updateRetailCartVariant(item.lineId, nextSize, hasCurrentColorForSize ? selectedColor : nextColor);
																}}
																className="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-700 outline-none focus:border-blue-500"
															>
																{sizeOptions.map((size) => (
																		<option key={size} value={size}>{size}</option>
																	))}
															</select>
															<select
																title={`Cart color for ${item.name}`}
																value={selectedColor}
																onChange={(event) => {
																	const nextColor = event.target.value;
																	const hasCurrentSizeForColor = sourceProduct.variants.some((variant) => (
																		normalizeVariantToken(variant.color) === normalizeVariantToken(nextColor)
																		&& normalizeVariantToken(variant.size) === normalizeVariantToken(selectedSize)
																	));
																	const nextSizeForColor = sourceProduct.variants.find((variant) => normalizeVariantToken(variant.color) === normalizeVariantToken(nextColor) && variant.stock > 0)?.size
																		?? sourceProduct.variants.find((variant) => normalizeVariantToken(variant.color) === normalizeVariantToken(nextColor))?.size
																		?? selectedSize;
																	updateRetailCartVariant(item.lineId, hasCurrentSizeForColor ? selectedSize : nextSizeForColor, nextColor);
																}}
																className="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-700 outline-none focus:border-blue-500"
															>
																{colorOptions.map((color) => (
																		<option key={color} value={color}>{color}</option>
																	))}
																</select>
															</>
													);
												})()}
											</div>
											<div className="mb-2 flex items-center justify-between text-xs text-slate-500">
												<span>{formatPeso(item.unitPrice)} each</span>
												<span>Stock: {item.stock}</span>
											</div>
											<div className="flex items-center justify-between">
												<div className="flex items-center gap-2">
													<button
														type="button"
														onClick={() => updateRetailCartQty(item.lineId, item.qty - 1)}
														className="h-7 w-7 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-100"
													>
														-
													</button>
													<span className="min-w-6 text-center text-sm font-semibold text-slate-900">{item.qty}</span>
													<button
														type="button"
														onClick={() => updateRetailCartQty(item.lineId, item.qty + 1)}
														disabled={item.qty >= item.stock}
														className="h-7 w-7 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
													>
														+
													</button>
												</div>
												<p className="text-sm font-bold text-slate-900">{formatPeso(item.qty * item.unitPrice)}</p>
											</div>
										</div>
									))
								)}
							</div>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Payment Method</label>
							<select
								title="Retail payment method"
								value={retailPaymentMethod}
								onChange={(event) => setRetailPaymentMethod(event.target.value as PaymentMethod)}
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
							>
								<option value="cash">Cash</option>
								<option value="gcash">GCash</option>
								<option value="card">Card</option>
							</select>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Cash Received</label>
							<input
								title="Retail cash received"
								type="text"
								inputMode="decimal"
								value={retailCashReceivedInput}
								onChange={(event) => setRetailCashReceivedInput(toCurrencyInput(event.target.value))}
								disabled={retailPaymentMethod !== "cash"}
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500 disabled:bg-slate-100"
							/>

							{retailPaymentMethod !== "cash" && (
								<div>
									<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Proof Reference</label>
									<input
										title="Retail proof reference"
										value={retailProofReference}
										onChange={(event) => setRetailProofReference(event.target.value)}
										placeholder="Enter transaction/auth reference"
										className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
									/>
								</div>
							)}

							<div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
								<div className="space-y-2 text-sm">
									<div className="flex items-center justify-between text-slate-600"><span>Subtotal (Before VAT)</span><span>{formatPeso(retailBreakdown.netSubtotal)}</span></div>
									<div className="flex items-center justify-between text-slate-600"><span>VAT ({VAT_RATE}%)</span><span>{formatPeso(retailVatAmount)}</span></div>
									<div className="my-2 border-t border-dashed border-slate-300" />
									<div className="flex items-center justify-between text-base font-bold text-slate-900"><span>Total Due</span><span>{formatPeso(retailTotalDue)}</span></div>
									<div className="flex items-center justify-between text-slate-700"><span>Tendered</span><span>{formatPeso(retailTenderedAmount)}</span></div>
									<div className="flex items-center justify-between text-green-700"><span>Change</span><span className="font-semibold">{formatPeso(retailChangeValue)}</span></div>
								</div>
							</div>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</label>
							<textarea
								title="Retail notes"
								value={retailNotes}
								onChange={(event) => setRetailNotes(event.target.value)}
								rows={2}
								placeholder="Optional cashier notes"
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
							/>

							{!retailCanPay && retailPayDisableReason.length > 0 && (
								<div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
									{retailPayDisableReason}
								</div>
							)}

							<div className="mt-auto grid grid-cols-2 gap-2">
								<button
									type="button"
									onClick={handleRetailPay}
									disabled={!retailCanPay}
									className="rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"
								>
									{retailProcessingPayment ? "Processing..." : "Pay"}
								</button>
								<button
									type="button"
									onClick={clearRetailTransaction}
									disabled={retailProcessingPayment}
									className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
								>
									Clear
								</button>
							</div>
						</div>
					</section>
				</div>
			) : (
				<div data-testid="repair-pos-mode" className="grid grid-cols-1 gap-6 xl:h-[calc(100vh-170px)] xl:grid-cols-12 xl:items-stretch">
					<section className="space-y-6 xl:col-span-8 xl:flex xl:h-full xl:flex-col xl:space-y-0 xl:gap-6">
						<div className="grid grid-cols-1 gap-4">
							<div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
								<h2 className="mb-2 text-base font-semibold text-slate-900">Customer Information</h2>
								<p className="mb-3 text-xs text-slate-500">Input customer name. Phone is required for cash and optional for GCash/Card. Email is optional.</p>
								<div className="grid grid-cols-1 gap-2 md:grid-cols-3">
									<input
										title="Customer name"
										value={customerName}
										onChange={(event) => setCustomerName(event.target.value)}
										disabled={!!selectedRepairOrder}
										placeholder="Customer name"
										className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500 disabled:bg-slate-100"
									/>
									<input
										title="Customer phone number"
										type="text"
										inputMode="numeric"
										pattern="[0-9]*"
										maxLength={11}
										value={customerPhone}
										onChange={(event) => setCustomerPhone(toDigitsOnly(event.target.value).slice(0, 11))}
										placeholder="Phone number"
										className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
									/>
									<input
										title="Customer email (optional)"
										type="email"
										value={customerEmail}
										onChange={(event) => setCustomerEmail(event.target.value)}
										disabled={!!selectedRepairOrder}
										placeholder="Email (optional)"
										className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500 disabled:bg-slate-100"
									/>
								</div>
								{paymentMethod === "cash" && customerPhone.length > 0 && !isCustomerPhoneValid && (
									<p className="mt-2 text-xs font-semibold text-red-600">Phone number must be exactly 11 digits.</p>
								)}
								<p className="mt-2 text-xs text-slate-500">These details will appear on the printed receipt.</p>
								{selectedRepairOrder && (
									<p className="mt-1 text-xs font-semibold text-blue-700">Customer name is locked because this order is attached from Job Order Repair.</p>
								)}
							</div>

							<div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
								<div className="mb-3 flex flex-wrap items-center justify-between gap-2">
									<div>
										<h2 className="text-base font-semibold text-slate-900">Manual Walk-in Queue</h2>
										<p className="text-xs text-slate-500">Search by receipt, customer name, or phone and continue status/payment flow.</p>
									</div>
									<div className="flex items-center gap-2">
										<input
											title="Search manual queue"
											value={manualQueueSearch}
											onChange={(event) => setManualQueueSearch(event.target.value)}
											placeholder="Receipt / name / phone"
											className="rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
										/>
										<button
											type="button"
											onClick={() => fetchManualQueue(manualQueueSearch)}
											className="rounded-xl bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-700"
										>
											Search
										</button>
									</div>
								</div>

								{manualQueueLoading ? (
									<div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">Loading manual queue...</div>
								) : manualQueue.length === 0 ? (
									<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">No manual walk-in records ready for queue.</div>
								) : (
									<div className="space-y-2">
										{manualQueue.map((row) => {
											const nextStatus = MANUAL_QUEUE_NEXT_STATUS[row.status];
											return (
												<div key={row.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
													<div>
														<p className="text-sm font-semibold text-slate-900">{row.request_id}</p>
														<p className="text-xs text-slate-600">{row.customer_name} • {row.phone || "No phone"}</p>
														{row.receipt_no && <p className="text-xs text-slate-600">Receipt: {row.receipt_no}</p>}
														<p className="text-xs text-slate-600">Status: {row.status}</p>
														<p className="text-xs text-slate-700">Remaining: {formatPeso(Number(row.remaining_balance || 0))}</p>
													</div>
													<div className="flex items-center gap-2">
														<button
															type="button"
															onClick={() => advanceManualQueueStatus(row)}
															disabled={!nextStatus || manualQueueActionLoadingId === row.id}
															className="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
														>
															{manualQueueActionLoadingId === row.id ? "Updating..." : (nextStatus ? `Mark ${nextStatus}` : "Done")}
														</button>
														<button
															type="button"
															onClick={() => continueManualQueuePayment(row)}
															disabled={!row.next_due_type}
															className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
														>
															Continue Payment
														</button>
														{canRequestWarrantyClaimFromManualQueue(row) && (
															<button
																type="button"
																onClick={() => handleRequestWarrantyClaim({
																	repairRequestId: Number(row.id ?? 0),
																	receiptNo: String(row.receipt_no ?? ''),
																	walkInPhone: row.phone,
																})}
																className="rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50"
															>
																Warranty
															</button>
														)}
													</div>
												</div>
											);
										})}
									</div>
								)}
							</div>
						</div>

						<div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:flex-1 xl:min-h-0 xl:flex xl:flex-col">
							<div className="mb-4 flex items-center justify-between">
								<h2 className="text-lg font-semibold text-slate-900">Repair Service Catalog</h2>
								<span className="text-xs text-slate-500">{selectedRepairOrder ? "Requested services highlighted" : "Tap to add"}</span>
							</div>
							<input
								title="Search repair services"
								value={serviceSearch}
								onChange={(event) => setServiceSearch(event.target.value)}
								placeholder="Search service name or category"
								className="mb-4 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
							/>

							{isLoadingData && (
								<div className="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">Loading services and repair orders...</div>
							)}

							<div className="mb-1 flex items-center justify-between">
								<h3 className="text-2xl font-semibold text-slate-900">Packages and Individual Services</h3>
								<span className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Choose one or more</span>
							</div>

							<div className="space-y-5 xl:flex-1 xl:min-h-0 xl:overflow-y-auto xl:pr-1">
								<div>
									<div className="mb-2 flex items-center justify-between">
										<h4 className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-600">Packages</h4>
										<span className="text-xs text-slate-500">Bundle pricing</span>
									</div>
									{visiblePackages.length === 0 ? (
										<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center text-xs text-slate-500">
											No package matches your current search.
										</div>
									) : (
										<div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
											{visiblePackages.map((pkg) => {
												const selected = isPackageSelected(pkg);
												return (
													<button
														type="button"
														key={`package-${pkg.id}`}
														onClick={() => addPackageToOrder(pkg)}
														disabled={!!selectedRepairOrder}
														className="h-56 rounded-xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
													>
														<div className="flex h-full flex-col">
															<div className="flex items-start justify-between">
																<span className="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-semibold uppercase text-slate-600">Package</span>
																<span className={`flex h-6 w-6 items-center justify-center rounded-full border ${selected ? "border-blue-500 bg-blue-500 text-white" : "border-slate-300"}`}>
																	{selected && (
																		<svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
																			<path d="M4 10l4 4 8-8" />
																		</svg>
																	)}
																</span>
															</div>
															<p className="mt-3 text-xl font-semibold text-slate-900">{pkg.name}</p>
															<p className="mt-1 text-xs text-slate-600">{pkg.description}</p>
															<p className="mt-2 text-xs text-slate-700">Includes {pkg.includedServices.length} services</p>
															<p className="text-xs text-slate-700">{pkg.saveText}</p>
															<div className="mt-auto flex items-center justify-between border-t border-slate-200 pt-3">
																<p className="text-2xl font-bold text-slate-900">{formatPeso(pkg.price)}</p>
																<p className="text-xs text-slate-500">Bundle offer</p>
															</div>
														</div>
													</button>
												);
											})}
										</div>
									)}
									{activeManualPackage && !selectedRepairOrder && (
										<div className="mt-2 flex justify-end">
											<button
												type="button"
												onClick={unselectManualPackage}
												className="text-xs font-semibold text-slate-700 underline hover:text-slate-900"
											>
												Unselect package
											</button>
										</div>
									)}
								</div>

								<div>
									<div className="mb-2 flex items-center justify-between">
										<h4 className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-600">Individual Services</h4>
										<span className="text-xs text-slate-500">Select add-ons or standalone services</span>
									</div>
									{paginatedServiceCatalog.length === 0 ? (
										<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center text-xs text-slate-500">
											No individual services match your current search.
										</div>
									) : (
										<div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
											{paginatedServiceCatalog.map((service) => {
												const isRequestedService = !selectedOrderServiceSet || selectedOrderServiceSet.has(normalizeServiceName(service.name));
												const isIncludedByPackage = activeManualPackageServiceIds.has(Number(service.id));
												const canSelectService = isRequestedService && !isIncludedByPackage;
												const selected = isServiceSelected(service);
												return (
													<button
														type="button"
														key={`service-${service.id}`}
														onClick={() => addFromServiceCatalog(service)}
														disabled={!canSelectService}
														className={`h-56 rounded-xl border p-4 text-left transition ${
															canSelectService
																? "border-slate-200 bg-slate-50 hover:border-blue-300 hover:bg-blue-50"
																: "border-slate-200 bg-slate-100 opacity-45 grayscale cursor-not-allowed"
														}`}
													>
														<div className="flex h-full flex-col">
															<div className="flex items-start justify-between">
																<span className="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-semibold text-slate-600">{service.category}</span>
																<span className={`flex h-6 w-6 items-center justify-center rounded-full border ${selected ? "border-blue-500 bg-blue-500 text-white" : "border-slate-300"}`}>
																	{selected && (
																		<svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
																			<path d="M4 10l4 4 8-8" />
																		</svg>
																	)}
																</span>
															</div>
															<p className="mt-3 text-xl font-semibold text-slate-900">{service.name}</p>
															<ul className="mt-2 list-disc pl-5 text-xs text-slate-600">
																<li>{service.category} service for customer request.</li>
																<li>Estimated turnaround: {service.duration}.</li>
															</ul>
															<div className="mt-auto flex items-center justify-between border-t border-slate-200 pt-3">
																<p className="text-2xl font-bold text-slate-900">{formatPeso(service.price)}</p>
																<p className="text-xs text-slate-500">{service.duration}</p>
															</div>
															{activeManualPackage && isIncludedByPackage && <span className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Included in package</span>}
															{activeManualPackage && !isIncludedByPackage && <span className="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Add-on</span>}
															{selectedRepairOrder && isRequestedService && <span className="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Requested</span>}
														</div>
													</button>
												);
											})}
										</div>
									)}
								</div>
							</div>

							{filteredServiceCatalog.length > 0 && (
								<div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-4 text-sm text-slate-700">
									<p>
										Showing {(servicePage - 1) * SERVICES_PER_PAGE + 1} to {Math.min(servicePage * SERVICES_PER_PAGE, filteredServiceCatalog.length)} of {filteredServiceCatalog.length} individual services
									</p>
									<div className="flex items-center gap-2">
										<button
											type="button"
											onClick={() => setServicePage((prev) => Math.max(prev - 1, 1))}
											disabled={servicePage === 1}
											className="h-9 w-9 rounded-lg border border-slate-300 text-slate-500 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
										>
											&#8249;
										</button>
										<div className="h-9 min-w-10 rounded-lg bg-blue-600 px-3 text-center text-sm font-semibold leading-9 text-white">
											{servicePage}
										</div>
										<button
											type="button"
											onClick={() => setServicePage((prev) => Math.min(prev + 1, totalServicePages))}
											disabled={servicePage === totalServicePages}
											className="h-9 w-9 rounded-lg border border-slate-300 text-slate-500 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
										>
											&#8250;
										</button>
									</div>
								</div>
							)}
						</div>
					</section>

					<section className="space-y-6 xl:col-span-4 xl:h-full">
						<div className="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:h-full">
							<div className="flex items-center justify-between">
								<h2 className="text-lg font-semibold text-slate-900">Current Order</h2>
								{isPaid && <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Paid</span>}
							</div>

							<div className="flex-1 min-h-0 space-y-2 overflow-y-auto pr-1">
								{items.length === 0 ? (
									<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-center text-xs text-slate-500">No services in order yet.</div>
								) : (
									<>
										{packageOrderItem && (
											<div className="rounded-xl border border-blue-200 bg-blue-50/40 p-3">
												<div className="mb-2 flex items-start justify-between gap-2">
													<div>
														<p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-blue-700">Package</p>
														<p className="text-sm font-semibold text-slate-900">{packageOrderItem.label}</p>
													</div>
													<button
														type="button"
														onClick={unselectManualPackage}
														title="Unselect package"
														aria-label="Unselect package"
														className="rounded-md p-1 text-red-600 transition hover:bg-red-50 hover:text-red-500"
													>
														<svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
															<path d="M3 6h18" />
															<path d="M8 6V4h8v2" />
															<path d="M19 6l-1 14H6L5 6" />
															<path d="M10 11v6" />
															<path d="M14 11v6" />
														</svg>
													</button>
												</div>
												<p className="text-right text-sm font-bold text-slate-900">{formatPeso(packageOrderItem.qty * packageOrderItem.unitPrice)}</p>

												{packageAddOnOrderItems.length > 0 && (
													<div className="mt-3 border-t border-blue-200 pt-2">
														<p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-blue-700">Add-ons</p>
														<div className="space-y-2">
															{packageAddOnOrderItems.map((item) => (
																<div key={item.id} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-2 py-1.5">
																	<p className="text-xs font-medium text-slate-800">{item.label.replace(/\s*\(add-on\)\s*$/i, "")}</p>
																	<div className="flex items-center gap-2">
																		<p className="text-xs font-semibold text-slate-900">{formatPeso(item.qty * item.unitPrice)}</p>
																		<button
																			type="button"
																			onClick={() => removeItem(item.id)}
																			title="Remove add-on"
																			aria-label="Remove add-on"
																			className="rounded p-1 text-red-600 transition hover:bg-red-50 hover:text-red-500"
																		>
																			<svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
																				<path d="M3 6h18" />
																				<path d="M8 6V4h8v2" />
																				<path d="M19 6l-1 14H6L5 6" />
																			</svg>
																		</button>
																	</div>
																</div>
															))}
														</div>
													</div>
												)}
											</div>
										)}

										{standaloneOrderItems.map((item) => (
											<div key={item.id} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
												<div className="mb-2 flex items-start justify-between gap-2">
													<p className="text-sm font-medium text-slate-900">{item.label}</p>
													<button
														type="button"
														onClick={() => removeItem(item.id)}
														title="Remove item"
														aria-label="Remove item"
														className="rounded-md p-1 text-red-600 transition hover:bg-red-50 hover:text-red-500"
													>
														<svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
															<path d="M3 6h18" />
															<path d="M8 6V4h8v2" />
															<path d="M19 6l-1 14H6L5 6" />
															<path d="M10 11v6" />
															<path d="M14 11v6" />
														</svg>
													</button>
												</div>
												<p className="text-right text-sm font-bold text-slate-900">{formatPeso(item.qty * item.unitPrice)}</p>
											</div>
										))}
									</>
								)}
							</div>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Discount (PHP)</label>
							<input
								title="Discount in PHP"
								type="text"
								inputMode="numeric"
								pattern="[0-9]*"
								min={0}
								value={discountInput}
								onChange={(event) => setDiscountInput(toDigitsOnly(event.target.value))}
								disabled
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
							/>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Payment Method</label>
							<select
								title="Payment method"
								value={paymentMethod}
								onChange={(event) => setPaymentMethod(event.target.value as PaymentMethod)}
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
							>
								<option value="cash">Cash</option>
								<option value="gcash">GCash</option>
								<option value="card">Card</option>
							</select>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Cash Received</label>
							<input
								title="Cash received"
								type="text"
								inputMode="decimal"
								pattern="[0-9]*[.]?[0-9]{0,2}"
								min={0}
								value={cashReceivedInput}
								onChange={(event) => setCashReceivedInput(toCurrencyInput(event.target.value))}
								disabled={paymentMethod !== "cash"}
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500 disabled:bg-slate-100"
							/>

							{paymentMethod !== "cash" && (
								<div>
									<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Proof Reference</label>
									<input
										title="GCash/Card reference"
										value={proofReference}
										onChange={(event) => setProofReference(event.target.value)}
										placeholder="Enter transaction/auth reference"
										className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
									/>
									<p className="mt-1 text-[11px] text-slate-500">Required for GCash/Card transactions.</p>
								</div>
							)}

							<div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
								<div className="space-y-2 text-sm">
									<div className="flex items-center justify-between text-slate-600"><span>Subtotal (Before VAT)</span><span>{formatPeso(dueBreakdown.netSubtotal)}</span></div>
									<div className="flex items-center justify-between text-slate-600"><span>Discount</span><span>- {formatPeso(discount)}</span></div>
									<div className="flex items-center justify-between text-slate-600"><span>VAT ({VAT_RATE}%)</span><span>{formatPeso(vatAmount)}</span></div>
									<div className="my-2 border-t border-dashed border-slate-300" />
									<div className="flex items-center justify-between text-base font-bold text-slate-900"><span>Total Due</span><span>{formatPeso(totalDue)}</span></div>
									<div className="flex items-center justify-between text-slate-700"><span>Tendered</span><span>{formatPeso(tenderedAmount)}</span></div>
									<div className="flex items-center justify-between text-green-700"><span>Change</span><span className="font-semibold">{formatPeso(changeValue)}</span></div>
								</div>
							</div>

							<label className="block text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</label>
							<textarea
								title="Cashier notes"
								value={notes}
								onChange={(event) => setNotes(event.target.value)}
								rows={2}
								placeholder="Optional cashier notes"
								className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
							/>

							{hasInsufficientCash && (
								<div className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
									Insufficient cash by {formatPeso(shortValue)}. Add more cash before payment.
								</div>
							)}

							{!canPay && payDisableReason.length > 0 && (
								<div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
									{payDisableReason}
								</div>
							)}

							<div className="mt-auto grid grid-cols-2 gap-2">
								<button
									type="button"
									onClick={handlePay}
									disabled={!canPay}
									className="rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"
								>
									{isProcessingPayment ? "Processing..." : "Pay"}
								</button>
								<button
									type="button"
									onClick={clearTransaction}
									disabled={isProcessingPayment}
									className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
								>
									Clear
								</button>
							</div>
						</div>

					</section>
				</div>
			)}

			{isRefundQueueOpen && (
					<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
						<div className="w-full max-w-4xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
							<div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
								<h3 className="text-lg font-semibold text-slate-900">Repair Refund Queue</h3>
								<button
									type="button"
									onClick={() => setIsRefundQueueOpen(false)}
									className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
								>
									Close
								</button>
							</div>

							<div className="max-h-[70vh] overflow-y-auto p-5">
								{isRefundQueueLoading ? (
									<div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">Loading refund queue...</div>
								) : refundQueue.length === 0 ? (
									<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">No repair refunds found.</div>
								) : (
									<div className="space-y-3">
										{refundQueue.map((refund) => {
											const financeStatus = String(refund.finance_status || 'pending').toLowerCase();
											const ownerStatus = String(refund.shop_owner_status || 'pending').toLowerCase();
											const canApprove = refund.status === 'requested' && financeStatus === 'approved_initial' && ownerStatus === 'pending';
											const canExecute = false;
											return (
												<div key={refund.id} className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
													<div className="flex flex-wrap items-start justify-between gap-3">
														<div>
															<p className="text-sm font-semibold text-slate-900">#{refund.id} {refund.repairRequest?.request_id ? `- ${refund.repairRequest.request_id}` : ''}</p>
															<p className="text-xs text-slate-600">Customer: {refund.repairRequest?.customer_name || 'N/A'}</p>
															<p className="text-xs text-slate-600">Amount: {formatPeso(Number(refund.approved_amount ?? refund.requested_amount ?? 0))}</p>
															{refund.failure_reason && <p className="text-xs text-red-600">Reason: {refund.failure_reason}</p>}
														</div>
														<div className="flex items-center gap-2">
															<span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase ${getRefundStatusClass(refund.status)}`}>{refund.status}</span>
															{financeStatus && (
																<span className="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-blue-700">F:{financeStatus}</span>
															)}
															{ownerStatus && (
																<span className="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-violet-700">O:{ownerStatus}</span>
															)}
															{canApprove && (
																<button
																	type="button"
																	onClick={() => performRefundAction(refund.id, 'approve', {})}
																	disabled={processingRefundId === refund.id}
																	className="rounded-lg border border-blue-300 px-3 py-1 text-xs font-semibold text-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
																>
																	Approve
																</button>
															)}
															{canApprove && (
																<button
																	type="button"
																	onClick={() => performRefundAction(refund.id, 'reject', { rejection_reason: 'Rejected by Shop Owner from POS queue' })}
																	disabled={processingRefundId === refund.id}
																	className="rounded-lg border border-red-300 px-3 py-1 text-xs font-semibold text-red-700 disabled:cursor-not-allowed disabled:opacity-40"
																>
																	Reject
																</button>
															)}
															{canExecute && (
																<button
																	type="button"
																	onClick={() => performRefundAction(refund.id, 'execute', { execution_mode: 'manual' })}
																	disabled={processingRefundId === refund.id}
																	className="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
																>
																	Execute
																</button>
															)}
														</div>
													</div>
												</div>
											);
										})}
									</div>
								)}
							</div>
						</div>
					</div>
				)}

				{isOrderModalOpen && (
					<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
						<div className="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
							<div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
								<h3 className="text-lg font-semibold text-slate-900">Attach From Repair Orders</h3>
								<button
									type="button"
									onClick={() => setIsOrderModalOpen(false)}
									className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
								>
									Close
								</button>
							</div>

							<div className="p-5">
								<input
									title="Search repair order"
									value={orderSearch}
									onChange={(event) => setOrderSearch(event.target.value)}
									placeholder="Search by customer or service"
									className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
								/>

								<div className="mt-4 max-h-90 space-y-3 overflow-y-auto pr-1">
									{filteredRepairOrders.length === 0 ? (
										<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">No matching repair orders.</div>
									) : (
										filteredRepairOrders.map((order) => (
											<div key={order.id} className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
												<div>
													<p className="font-semibold text-slate-900">{order.customer}</p>
													<p className="text-sm text-slate-600">{order.service}</p>
													<p className="text-xs text-slate-500">Services: {order.requestedServices.join(", ")}</p>
													<p className="text-xs text-slate-500">Estimated amount {formatPeso(order.amount)}</p>
												</div>
												<button
													type="button"
													onClick={() => addFromRepairOrder(order)}
													className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-500"
												>
													Add
												</button>
											</div>
										))
									)}
								</div>
							</div>
						</div>
					</div>
				)}

				{isHistoryModalOpen && (
					<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
						<div className="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
							<div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
								<h3 className="text-lg font-semibold text-slate-900">Receipt History</h3>
								<button
									type="button"
									onClick={() => setIsHistoryModalOpen(false)}
									className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
								>
									Close
								</button>
							</div>

							<div className="space-y-4 p-5">
								<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
									<input
										title="Search receipt history"
										value={historySearch}
										onChange={(event) => setHistorySearch(event.target.value)}
										placeholder="Search by receipt, customer, cashier, item"
										className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
									/>
									<input
										title="Filter by receipt date"
										type="date"
										value={historyDate}
										onChange={(event) => setHistoryDate(event.target.value)}
										className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
									/>
								</div>

								<div className="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
									{isLoadingHistory && (
										<div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">Loading transaction history...</div>
									)}
									{filteredReceiptHistory.length === 0 ? (
										<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">No receipts found for current filter.</div>
									) : (
										filteredReceiptHistory.map((receipt) => (
											<div key={receipt.receiptNo} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
												<div className="flex flex-wrap items-start justify-between gap-3">
													<div>
														<p className="text-sm font-semibold text-slate-900">{receipt.receiptNo}</p>
														<p className="text-xs text-slate-600">{receipt.dateLabel}</p>
														<p className="text-xs text-slate-600">Customer: {receipt.customerName}</p>
														<p className="text-xs text-slate-600">Method: {receipt.paymentMethod.toUpperCase()} | Phase: {getDueTypeLabel(receipt.dueType)}</p>
													</div>
													<div className="flex items-center gap-2">
														<p className="text-sm font-bold text-slate-900">{formatPeso(receipt.totalDue)}</p>
														{receipt.latestRefund?.status && (
															<span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ${getRefundStatusClass(String(receipt.latestRefund.status || ""))}`}>
																{receipt.latestRefund.status}
															</span>
														)}
														{canRequestWarrantyClaimFromReceipt(receipt) && (
															<button
																type="button"
																onClick={() => handleRequestWarrantyClaim({
																	repairRequestId: Number(receipt.repairRequestId ?? 0),
																	receiptNo: receipt.receiptNo,
																	walkInPhone: receipt.customerPhone,
																})}
																className="rounded-lg border border-indigo-300 px-3 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50"
															>
																Warranty
															</button>
														)}
														{(receipt.moduleType === "retail" ? canRequestRetailRefund(receipt) : canRequestRepairRefund(receipt)) && (
															<button
																type="button"
																onClick={() => handleRequestRefund(receipt)}
																title="Refund"
																aria-label="Refund"
																className="inline-flex items-center justify-center bg-transparent p-1 text-amber-600 transition-colors hover:text-amber-700"
															>
																<svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
																	<path d="M3 12a9 9 0 0 0 15.3 6.36L21 16" />
																	<path d="M21 12A9 9 0 0 0 5.7 5.64L3 8" />
																	<path d="M3 3v5h5" />
																	<path d="M21 21v-5h-5" />
																</svg>
															</button>
														)}
														<button
															type="button"
															onClick={() => {
																setReceiptSnapshot(receipt);
																setIsHistoryModalOpen(false);
																setIsReceiptModalOpen(true);
															}}
															title="View Receipt"
															aria-label="View Receipt"
															className="inline-flex items-center justify-center bg-transparent p-1 text-blue-600 transition-colors hover:text-blue-700"
														>
															<svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
																<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z" />
																<circle cx="12" cy="12" r="3" />
															</svg>
														</button>
													</div>
												</div>
											</div>
										))
									)}
								</div>
							</div>
						</div>
					</div>
				)}

				{isRetailRefundModalOpen && retailRefundReceipt && (
					<div
						className="fixed inset-0 z-60 flex items-center justify-center bg-slate-950/60 px-3 py-4"
						onClick={(event) => {
							if (event.target === event.currentTarget && !retailRefundSubmitting) {
								resetRetailRefundModalState();
							}
						}}
					>
						<div className="w-full max-w-5xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
							<div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
								<div>
									<h3 className="text-xl font-semibold text-slate-900">Retail Item Refund</h3>
									<p className="mt-1 text-xs text-slate-500">
										Receipt: {retailRefundReceipt.receiptNo} | Customer: {retailRefundReceipt.customerName}
									</p>
								</div>
								<button
									type="button"
									onClick={resetRetailRefundModalState}
									disabled={retailRefundSubmitting}
									className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
								>
									Close
								</button>
							</div>

							<div className="grid grid-cols-1 gap-4 p-5 lg:grid-cols-3">
								<section className="lg:col-span-2">
									<div className="mb-3 flex flex-wrap items-center gap-2">
										<input
											title="Search refund lines"
											value={retailRefundSearch}
											onChange={(event) => setRetailRefundSearch(event.target.value)}
											placeholder="Search item name"
											className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-blue-500"
										/>
									</div>

									<div className="max-h-[56vh] space-y-3 overflow-y-auto pr-1">
										{filteredRetailRefundItems.length === 0 ? (
											<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
												No matching refundable items.
											</div>
										) : (
											filteredRetailRefundItems.map((item) => {
												const draft = retailRefundDraftByOrderItem[item.orderItemId] ?? {
													requestedQty: 0,
													inspectionDisposition: "resellable" as const,
												};
												const requestedQty = Math.max(0, Math.min(Number(draft.requestedQty || 0), item.remainingQty));
												const lineAmount = Number((requestedQty * item.unitPrice).toFixed(2));

												return (
													<div key={item.orderItemId} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
														<div className="flex flex-wrap items-start justify-between gap-2">
															<div>
																<p className="text-sm font-semibold text-slate-900">{item.label}</p>
																<p className="mt-1 text-xs text-slate-500">
																	Purchased: {item.purchasedQty} | Refunded/Requested: {item.committedQty} | Remaining: {item.remainingQty}
																</p>
															</div>
															<span className="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-700">
																{formatPeso(item.unitPrice)} each
															</span>
														</div>

														<div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
															<div>
																<label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Refund Qty</label>
																<div className="flex items-center gap-2">
																	<button
																		type="button"
																		onClick={() => updateRetailRefundQty(item.orderItemId, String(requestedQty - 1))}
																		disabled={retailRefundSubmitting || requestedQty <= 0}
																		className="h-8 w-8 rounded-lg border border-slate-300 text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
																	>
																		-
																	</button>
																	<input
																		title={`Refund qty for ${item.label}`}
																		type="number"
																		min={0}
																		max={item.remainingQty}
																		step={1}
																		value={requestedQty}
																		onChange={(event) => updateRetailRefundQty(item.orderItemId, event.target.value)}
																		disabled={retailRefundSubmitting}
																		className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-center text-sm outline-none transition focus:border-blue-500 disabled:bg-slate-100"
																	/>
																	<button
																		type="button"
																		onClick={() => updateRetailRefundQty(item.orderItemId, String(requestedQty + 1))}
																		disabled={retailRefundSubmitting || requestedQty >= item.remainingQty}
																		className="h-8 w-8 rounded-lg border border-slate-300 text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
																	>
																		+
																	</button>
																	<button
																		type="button"
																		onClick={() => updateRetailRefundQty(item.orderItemId, String(item.remainingQty))}
																		disabled={retailRefundSubmitting || requestedQty === item.remainingQty}
																		className="rounded-lg border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 transition hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
																	>
																		Max
																	</button>
																</div>
															</div>

															<div>
																<label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Inspection</label>
																<select
																	title={`Inspection for ${item.label}`}
																	value={draft.inspectionDisposition}
																	onChange={(event) => updateRetailRefundDisposition(item.orderItemId, event.target.value === "damaged" ? "damaged" : "resellable")}
																	disabled={retailRefundSubmitting}
																	className="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm outline-none transition focus:border-blue-500 disabled:bg-slate-100"
																>
																	<option value="resellable">Resellable (restock)</option>
																	<option value="damaged">Damaged (write-off)</option>
																</select>
															</div>
														</div>

														<div className="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-sm">
															<span className="text-slate-600">Line amount</span>
															<span className="font-semibold text-slate-900">{formatPeso(lineAmount)}</span>
														</div>
													</div>
												);
											})
										)}
									</div>
								</section>

								<aside className="rounded-xl border border-slate-200 bg-slate-50 p-4">
									<h4 className="text-sm font-semibold uppercase tracking-wide text-slate-700">Refund Summary</h4>
									<div className="mt-3 space-y-2 text-sm">
										<div className="flex items-center justify-between text-slate-600">
											<span>Selected lines</span>
											<span className="font-semibold text-slate-900">{retailRefundSelection.selectedItemCount}</span>
										</div>
										<div className="flex items-center justify-between text-slate-600">
											<span>Request type</span>
											<span className="font-semibold uppercase text-slate-900">{retailRefundSelection.requestType}</span>
										</div>
										<div className="flex items-center justify-between text-slate-600">
											<span>Refundable balance</span>
											<span className="font-semibold text-slate-900">{formatPeso(retailRefundableBalance)}</span>
										</div>
										<div className="my-2 border-t border-dashed border-slate-300" />
										<div className="flex items-center justify-between text-base font-bold text-slate-900">
											<span>Requested amount</span>
											<span>{formatPeso(retailRefundSelection.requestedAmount)}</span>
										</div>
										<div className="flex items-center justify-between text-xs text-slate-500">
											<span>Balance after refund</span>
											<span>{formatPeso(Math.max(retailRefundableBalance - retailRefundSelection.requestedAmount, 0))}</span>
										</div>
									</div>

									{retailRefundSelection.exceedsBalance && (
										<div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
											Requested amount exceeds refundable balance.
										</div>
									)}

									{retailRefundValidationError.length > 0 && (
										<div className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
											{retailRefundValidationError}
										</div>
									)}

									<div className="mt-4 grid grid-cols-2 gap-2">
										<button
											type="button"
											onClick={submitRetailRefund}
											disabled={!retailRefundCanSubmit}
											className="rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"
										>
											{retailRefundSubmitting ? "Creating..." : "Create Refund"}
										</button>
										<button
											type="button"
											onClick={() => {
												const resetDrafts = retailRefundItems.reduce<Record<number, RetailRefundDraft>>((acc, item) => {
													acc[item.orderItemId] = {
														requestedQty: 0,
														inspectionDisposition: "resellable",
													};
													return acc;
												}, {});
												setRetailRefundDraftByOrderItem(resetDrafts);
												setRetailRefundValidationError("");
											}}
											disabled={retailRefundSubmitting}
											className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
										>
											Clear
										</button>
									</div>
								</aside>
							</div>
						</div>
					</div>
				)}

				{isReceiptModalOpen && receiptSnapshot && (
					<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
						<div className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
							<div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
								<h3 className="text-lg font-semibold text-slate-900">Receipt (Thermal)</h3>
								<div className="flex items-center gap-2">
									<button
										type="button"
										onClick={printReceipt}
										title="Print receipt"
										aria-label="Print receipt"
										className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-1 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 hover:text-slate-900"
									>
										<svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
											<path d="M6 9V4h12v5" />
											<path d="M6 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-1" />
											<path d="M6 14h12v6H6z" />
											<path d="M8 14v-3h8v3" />
										</svg>
										<span>Print</span>
									</button>
									<button
										type="button"
										onClick={() => setIsReceiptModalOpen(false)}
										className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
									>
										Close
									</button>
								</div>
							</div>

							<div className="max-h-[75vh] overflow-y-auto p-5">
								<div className="pos-print-area mx-auto w-full max-w-[320px] rounded-lg border border-slate-300 bg-white p-3 text-xs text-slate-800">
									<div className="text-center">
										<p className="text-sm font-bold">SoleSpace Repair POS</p>
										<p>Point of Sale Receipt</p>
									</div>

									<div className="mt-2 border-t border-dashed border-slate-300 pt-2">
										<p>Receipt: {receiptSnapshot.receiptNo}</p>
										<p>Date: {receiptSnapshot.dateLabel}</p>
										<p>Customer: {receiptSnapshot.customerName}</p>
										{receiptSnapshot.customerPhone.length > 0 && <p>Phone: {receiptSnapshot.customerPhone}</p>}
										{receiptSnapshot.paymentReference && <p>Reference: {receiptSnapshot.paymentReference}</p>}
										<p>Cashier: {receiptSnapshot.cashierName}</p>
										<p>Method: {receiptSnapshot.paymentMethod.toUpperCase()}</p>
									</div>

									<div className="mt-2 border-t border-dashed border-slate-300 pt-2">
										{receiptSnapshot.items.map((line) => (
											<div key={line.id} className="mb-1">
												<p className="font-medium">{line.label}</p>
												<p className="text-slate-600">{line.qty} x {formatPeso(line.unitPrice)} = {formatPeso(line.qty * line.unitPrice)}</p>
											</div>
										))}
									</div>

									<div className="mt-2 border-t border-dashed border-slate-300 pt-2">
										<div className="flex justify-between"><span>Subtotal</span><span>{formatPeso(receiptSnapshot.subtotal)}</span></div>
										<div className="flex justify-between"><span>Discount</span><span>- {formatPeso(receiptSnapshot.discount)}</span></div>
										<div className="flex justify-between"><span>VAT ({receiptSnapshot.vatRate.toFixed(2)}%)</span><span>{formatPeso(receiptSnapshot.vatAmount)}</span></div>
										<div className="mt-1 flex justify-between font-bold"><span>Total</span><span>{formatPeso(receiptSnapshot.totalDue)}</span></div>
										<div className="flex justify-between"><span>Tendered</span><span>{formatPeso(receiptSnapshot.cashReceived)}</span></div>
										<div className="flex justify-between font-semibold text-emerald-700"><span>Change</span><span>{formatPeso(receiptSnapshot.change)}</span></div>
									</div>

									{receiptSnapshot.notes.trim().length > 0 && (
										<div className="mt-2 border-t border-dashed border-slate-300 pt-2">
											<p className="font-semibold">Notes</p>
											<p>{receiptSnapshot.notes}</p>
										</div>
									)}

									<p className="mt-3 text-center text-[10px] text-slate-500">Thank you for trusting SoleSpace Repair.</p>
								</div>
							</div>

							<div className="receipt-modal-actions border-t border-slate-200 px-5 py-4" />
						</div>
					</div>
				)}

			</div>
		</AppLayoutShopOwner>
	);
};

export default PointOfSalePage;
