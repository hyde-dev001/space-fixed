import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import axios from "axios";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import Swal from "sweetalert2";
import {
	computeCanPay,
	getPhoneDisplayForReceipt,
	normalizeOptionalCustomerId,
} from "../../Repairs/posPaymentValidation";
import { repairPosHistoryApi } from "../../../services/repairPosHistoryApi";
import { buildRepairBreakdown } from "../../../utils/repairPricing";

type PaymentMethod = "cash" | "gcash" | "card";
type PosDueType = "deposit" | "balance" | "full";
type ManualPaymentPolicy = "full_upfront";

type RepairOrderOption = {
	id: string;
	requestNumber?: string;
	customer: string;
	customerId?: number | null;
	paymentPolicy?: "deposit_50" | "full_upfront";
	paymentStatus?: string;
	status?: string;
	returnDeliveryMethod?: "walk_in" | "customer_pickup" | "shop_delivery" | string;
	dueTypeToCollect?: PosDueType | null;
	paymentPhase?: string | null;
	collectible?: boolean;
	collectibleAmount: number;
	outstandingBalance: number;
	totalPaidAmount: number;
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
	source: "manual" | "repair-order" | "service-catalog" | "package" | "package-add-on";
	manualRepairPackageId?: number | null;
	manualServiceIds?: number[];
};

type ReceiptRefundEntry = {
	status: string;
	approvedAmount: number;
};

type ReceiptSnapshot = {
	transactionId?: number;
	repairRequestId?: number;
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
	customer_id?: number | null;
	phone: string;
	status: ManualQueueStatus;
	payment_policy: "deposit_50" | "full_upfront";
	total: number;
	paid: number;
	remaining_balance: number;
	next_due_type: PosDueType | null;
	receipt_no?: string | null;
};

const hasOpenOrCompletedRefund = (receipt: ReceiptSnapshot): boolean => {
	const status = String(receipt.latestRefund?.status || "").toLowerCase();
	return ["requested", "approved", "processing", "succeeded"].includes(status);
};

const canExecuteReceiptRefund = (receipt: ReceiptSnapshot): boolean => {
	return receipt.customerType === "walk_in" && String(receipt.latestRefund?.status || "").toLowerCase() === "approved";
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

const getRefundStatusHint = (status: string | undefined): string => {
	const normalized = String(status || "").toLowerCase();
	if (normalized === "requested") return "Pending Finance approval";
	if (normalized === "approved") return "Approved, ready for payout execution";
	if (normalized === "processing") return "Payout is being processed";
	if (normalized === "succeeded") return "Refund payout completed";
	if (normalized === "rejected") return "Refund request was rejected";
	return "";
};

const getRefundStatusClass = (status: string): string => {
	switch (String(status || "").toLowerCase()) {
		case "succeeded":
			return "bg-emerald-100 text-emerald-700";
		case "failed":
		case "rejected":
			return "bg-red-100 text-red-700";
		case "approved":
			return "bg-blue-100 text-blue-700";
		case "processing":
			return "bg-amber-100 text-amber-700";
		default:
			return "bg-slate-100 text-slate-700";
	}
};

const SERVICES_PER_PAGE = 6;
const VAT_RATE = 12;

const normalizeDueType = (value: string | null): PosDueType => {
	if (value === "deposit" || value === "balance" || value === "full") {
		return value;
	}

	return "full";
};

const normalizePaymentPolicy = (value: unknown): "deposit_50" | "full_upfront" => {
	return value === "deposit_50" ? "deposit_50" : "full_upfront";
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
	const cashierName = String((props as any)?.auth?.user?.name || "Repairer Cashier");
	const shopRepairPaymentPolicy: ManualPaymentPolicy = "full_upfront";
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
	const [isRefundQueueOpen, setIsRefundQueueOpen] = useState<boolean>(false);
	const [receiptHistory, setReceiptHistory] = useState<ReceiptSnapshot[]>([]);
	const [isLoadingHistory, setIsLoadingHistory] = useState<boolean>(false);
	const [isRefundQueueLoading, setIsRefundQueueLoading] = useState<boolean>(false);
	const [refundQueue, setRefundQueue] = useState<RefundQueueItem[]>([]);
	const [historySearch, setHistorySearch] = useState<string>("");
	const [historyDate, setHistoryDate] = useState<string>("");
	const [selectedRepairOrder, setSelectedRepairOrder] = useState<RepairOrderOption | null>(null);

	const [repairOrders, setRepairOrders] = useState<RepairOrderOption[]>([]);
	const [serviceCatalog, setServiceCatalog] = useState<RepairServiceOption[]>([]);
	const [servicePackages, setServicePackages] = useState<ServicePackageOption[]>([]);
	const [isLoadingData, setIsLoadingData] = useState<boolean>(false);
	const [manualQueue, setManualQueue] = useState<ManualQueueRow[]>([]);
	const [manualQueueSearch, setManualQueueSearch] = useState<string>("");
	const [manualQueueLoading, setManualQueueLoading] = useState<boolean>(false);
	const [manualQueueActionLoadingId, setManualQueueActionLoadingId] = useState<number | null>(null);

	useEffect(() => {
		let isMounted = true;

		const loadData = async () => {
			setIsLoadingData(true);
			try {
				const [servicesResult, ordersResult, packagesResult] = await Promise.allSettled([
					axios.get("/api/repair-services"),
					axios.get("/api/repairer/repairs", { params: { scope: "pos_checkout" } }),
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
								entry?.collection_summary?.grand_total
								?? entry?.final_total
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
							const collectibleAmount = Number(
								entry?.collectible_amount
								?? entry?.collection_summary?.collectible_amount
								?? 0,
							);
							const outstandingBalance = Number(
								entry?.outstanding_balance
								?? entry?.collection_summary?.outstanding_balance
								?? 0,
							);
							const totalPaidAmount = Number(
								entry?.collection_summary?.total_paid_amount
								?? entry?.total_paid_amount
								?? 0,
							);
							return {
								id: String(entry?.id ?? `R-${index}`),
								requestNumber: String(entry?.request_id ?? "").trim() || undefined,
								customer: String(entry?.customer ?? entry?.customer_name ?? "Walk-in Customer"),
								customerId: normalizeOptionalCustomerId(entry?.customer_id ?? entry?.user_id),
								paymentPolicy: normalizePaymentPolicy(entry?.payment_policy_snapshot ?? entry?.payment_policy ?? entry?.shop_owner?.repair_payment_policy),
								paymentStatus: String(entry?.payment_status ?? "pending"),
								status: String(entry?.status ?? ""),
								returnDeliveryMethod: String(entry?.return_delivery_method ?? (entry?.delivery_method === "walk_in" ? "walk_in" : "customer_pickup")),
								dueTypeToCollect: parseDueType(entry?.due_type ?? entry?.collection_summary?.due_type),
								paymentPhase: entry?.phase ?? entry?.collection_summary?.phase ?? null,
								collectible: Boolean(entry?.collectible ?? entry?.collection_summary?.collectible),
								collectibleAmount: Number.isFinite(collectibleAmount) ? collectibleAmount : 0,
								outstandingBalance: Number.isFinite(outstandingBalance) ? outstandingBalance : 0,
								totalPaidAmount: Number.isFinite(totalPaidAmount) ? totalPaidAmount : 0,
								service: primaryService,
								amount: Number.isFinite(amount) ? amount : 0,
								requestedServices: requestedServices.length > 0 ? requestedServices : [primaryService],
							};
						})
						.filter((entry: RepairOrderOption) => entry.amount > 0 && entry.collectibleAmount > 0 && entry.collectible === true && entry.dueTypeToCollect !== null);

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

	useEffect(() => {
		fetchManualQueue();
	}, []);

	useEffect(() => {
		let isMounted = true;

		const loadHistory = async () => {
			if (!isHistoryModalOpen) return;

			setIsLoadingHistory(true);
			try {
				const scopedRepairId = selectedRepairOrder ? Number(selectedRepairOrder.id) : undefined;
				const response = await repairPosHistoryApi.listTransactions(scopedRepairId, 200);
				const rows = Array.isArray((response.data as any)?.data?.data)
					? (response.data as any).data.data
					: [];

				const mappedHistory: ReceiptSnapshot[] = rows.map((row: any, index: number) => {
					const receiptPayload = row?.receipt?.print_payload ?? {};
					const issuedAt = String(row?.receipt?.issued_at ?? row?.created_at ?? new Date().toISOString());
					const items = Array.isArray(receiptPayload?.items)
						? receiptPayload.items
						: [];

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
						transactionId: Number(row?.id || 0),
						repairRequestId: Number(row?.module_reference_id || 0),
						customerType: String(row?.customer_type || "walk_in") === "registered" ? "registered" : "walk_in",
						dueType,
						paidAmount: Number(row?.paid_amount ?? 0),
						refundEntries: Array.isArray(row?.refunds)
							? row.refunds.map((entry: any) => ({
								status: String(entry?.status || ""),
								approvedAmount: Number(entry?.approved_amount ?? 0),
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
						items: Array.isArray(items) ? items : [],
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
	}, [cashierName, isHistoryModalOpen, selectedRepairOrder]);

	useEffect(() => {
		if (!isRefundQueueOpen) return;
		fetchRefundQueue();
	}, [isRefundQueueOpen]);

	const handleRequestRefund = async (receipt: ReceiptSnapshot) => {
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

		try {
			const requestedAmount = resolveRefundRequestAmount(receipt);
			const response = await repairPosHistoryApi.requestRefund({
				source_transaction_id: transactionId,
				request_type: "full",
				requested_amount: requestedAmount,
				reason_code: "repairer_requested_refund",
				reason_notes: "Requested from Repairer POS receipt history.",
				receipt_no: receipt.receiptNo,
			});

			const createdRefundId = Number((response.data as any)?.refund_id ?? 0);
			const responseRequestedAmount = Number((response.data as any)?.data?.requested_amount ?? requestedAmount);
			const responseApprovedAmount = Number((response.data as any)?.data?.approved_amount ?? responseRequestedAmount);
			const apiRefundStatus = String((response.data as any)?.data?.status ?? "").toLowerCase();
			const nextStatus = apiRefundStatus !== "" ? apiRefundStatus : "requested";
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
				title: "Refund Requested",
				text: "Refund request submitted for Finance approval.",
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

	const handleExecuteRefund = async (receipt: ReceiptSnapshot) => {
		const refundId = Number(receipt.latestRefund?.id ?? 0);
		if (refundId <= 0) {
			await Swal.fire({
				icon: "warning",
				title: "Refund Not Found",
				text: "No approved refund request is linked to this receipt.",
				confirmButtonColor: "#b45309",
			});
			return;
		}

		try {
			const response = await axios.post(
				`/api/repair-pos/refunds/${refundId}/execute`,
				{
					execution_mode: "manual",
					execution_note: "Executed from Repairer POS receipt history.",
				},
				{ withCredentials: true },
			);

			await Swal.fire({
				icon: "success",
				title: "Refund Executed",
				text: "Refund payout execution has been submitted.",
				confirmButtonColor: "#10b981",
			});

			const apiRefundStatus = String((response.data as any)?.data?.status ?? "").toLowerCase();
			const nextStatus = apiRefundStatus !== "" ? apiRefundStatus : "succeeded";
			const approvedAmount = Number((response.data as any)?.data?.approved_amount ?? receipt.totalDue);
			setReceiptHistory((prev) => prev.map((entry) => (
				entry.receiptNo === receipt.receiptNo
					? {
						...entry,
						refundEntries: [
							{
								status: nextStatus,
								approvedAmount,
							},
							...entry.refundEntries,
						],
						latestRefund: entry.latestRefund ? { ...entry.latestRefund, status: nextStatus } : entry.latestRefund,
					}
					: entry
			)));
		} catch (error: any) {
			const message = error?.response?.data?.message || "Unable to execute refund payout.";
			await Swal.fire({
				icon: "error",
				title: "Execution Failed",
				text: message,
				confirmButtonColor: "#dc2626",
			});
		}
	};

	useEffect(() => {
		if (!requestedRepairRequestId || selectedRepairOrder) return;

		const targetOrder = repairOrders.find((entry) => entry.id === requestedRepairRequestId);
		if (!targetOrder || !targetOrder.collectible || !targetOrder.dueTypeToCollect || targetOrder.collectibleAmount <= 0) return;

		const resolvedDueType = targetOrder.dueTypeToCollect;
		const dueAmount = targetOrder.collectibleAmount;
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

	const dueTypeForManualCheckout: PosDueType = "full";

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
		if (selectedRepairOrder) {
			return selectedRepairOrder.dueTypeToCollect ?? "full";
		}

		return dueTypeForManualCheckout;
	}, [dueTypeForManualCheckout, selectedRepairOrder]);
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
		const resolvedDueType = order.dueTypeToCollect;
		const dueAmount = order.collectibleAmount;
		if (!order.collectible || !resolvedDueType || dueAmount <= 0) return;
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
			.filter((order) => order.collectible === true && order.collectibleAmount > 0 && order.dueTypeToCollect !== null)
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
				receipt.items.map((item) => item.label).join(" "),
			]
				.join(" ")
				.toLowerCase();

			return haystack.includes(query);
		});
	}, [historyDate, historySearch, receiptHistory]);

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
				repairRequestId: repairRequestId || undefined,
				customerType: customerType,
				dueType: dueTypeForCheckout,
				paidAmount: Number(receiptTotals?.paid ?? tenderedAmount),
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
			setRepairOrders((prev) => prev.filter((entry) => entry.id !== String(repairRequestId)));
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
			customerId: normalizeOptionalCustomerId(row.customer_id),
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
		<AppLayoutERP hideHeader={isOrderModalOpen || isRefundQueueOpen || isReceiptModalOpen || isHistoryModalOpen}>
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
				{!isOrderModalOpen && !isRefundQueueOpen && !isReceiptModalOpen && !isHistoryModalOpen && (
				<div className="flex items-center justify-between">
					<div>
						<h1 className="text-2xl font-bold text-slate-900">Point of Sale</h1>
						<p className="mt-1 text-sm text-slate-500">Manage repair cashier transactions and payment processing.</p>
					</div>
					<div className="flex items-center gap-2">
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
						<button
							type="button"
							onClick={() => setIsOrderModalOpen(true)}
							className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-500"
						>
							Open Order Picker
						</button>
					</div>
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

				<div className="grid grid-cols-1 gap-6 xl:h-[calc(100vh-170px)] xl:grid-cols-12 xl:items-stretch">
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
							{isManualStandaloneCheckout && (
								<p className="text-[11px] text-slate-500">
									Manual policy from Shop Settings: Full Payment Upfront
								</p>
							)}
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
									<div className="flex items-center justify-between text-slate-600"><span>Service Subtotal</span><span>{formatPeso(subtotal)}</span></div>
									<div className="flex items-center justify-between text-slate-600"><span>Chargeable Subtotal</span><span>{formatPeso(chargeableSubtotal)}</span></div>
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
												<p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{order.requestNumber || `Repair #${order.id}`}</p>
												<p className="font-semibold text-slate-900">{order.customer}</p>
												<p className="text-sm text-slate-600">{order.service}</p>
												<p className="text-xs text-slate-500">Services: {order.requestedServices.join(", ")}</p>
												<p className="text-xs text-slate-500">Total {formatPeso(order.amount)} · Paid {formatPeso(order.totalPaidAmount)}</p>
												<p className="text-xs font-semibold text-blue-700">Collect {getDueTypeLabel(order.dueTypeToCollect)} {formatPeso(order.collectibleAmount)} · Remaining {formatPeso(order.outstandingBalance)}</p>
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
														{receipt.latestRefund?.status && (
															<p className="text-[11px] text-slate-500">{getRefundStatusHint(receipt.latestRefund.status)}</p>
														)}
													</div>
													<div className="flex items-center gap-2">
														<p className="text-sm font-bold text-slate-900">{formatPeso(receipt.totalDue)}</p>
														{receipt.latestRefund?.status && (
															<span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-700">
																{receipt.latestRefund.status}
															</span>
														)}
														{canRequestRepairRefund(receipt) && (
															<button
																type="button"
																onClick={() => handleRequestRefund(receipt)}
																className="rounded-lg border border-amber-300 px-3 py-1 text-xs font-semibold text-amber-700 transition hover:bg-amber-50"
															>
																Request Refund
															</button>
														)}
														{canExecuteReceiptRefund(receipt) && (
															<button
																type="button"
																onClick={() => handleExecuteRefund(receipt)}
																className="rounded-lg border border-emerald-300 px-3 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50"
															>
																Execute Payout
															</button>
														)}
														<button
															type="button"
															onClick={() => {
																setReceiptSnapshot(receipt);
																setIsHistoryModalOpen(false);
																setIsReceiptModalOpen(true);
															}}
															className="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-blue-500"
														>
															View
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
		</AppLayoutERP>
	);
};

export default PointOfSalePage;
