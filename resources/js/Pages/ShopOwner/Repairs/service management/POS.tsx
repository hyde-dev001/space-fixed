import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import axios from "axios";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";
import Swal from "sweetalert2";
import { computeCanPay, getPhoneDisplayForReceipt } from "../../../Repairs/posPaymentValidation";

type PaymentMethod = "cash" | "gcash" | "card";
type PosDueType = "deposit" | "balance" | "full";

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
	price: number;
	saveText: string;
};

type CatalogCardItem =
	| { kind: "package"; key: string; pkg: ServicePackageOption }
	| { kind: "service"; key: string; service: RepairServiceOption };

type POSItem = {
	id: string;
	label: string;
	qty: number;
	unitPrice: number;
	source: "manual" | "repair-order" | "service-catalog" | "package";
};

type ReceiptSnapshot = {
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

const SERVICES_PER_PAGE = 6;
const VAT_RATE = 12;

const normalizeDueType = (value: string | null): PosDueType => {
	if (value === "deposit" || value === "balance" || value === "full") {
		return value;
	}

	return "full";
};

const normalizePaymentPolicy = (value: unknown): "deposit_50" | "full_upfront" => {
	return value === "full_upfront" ? "full_upfront" : "deposit_50";
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

	if (policy === "full_upfront") {
		return paymentStatus === "paid" || paymentStatus === "completed" ? null : "full";
	}

	if (paymentStatus === "completed") return null;
	if (paymentStatus === "paid") {
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

const toSafeNumber = (value: string): number => {
	const parsed = Number(value);
	if (!Number.isFinite(parsed) || parsed < 0) return 0;
	return parsed;
};

const toDigitsOnly = (value: string): string => value.replace(/[^0-9]/g, "");

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
	const urlParams = typeof window !== "undefined" ? new URLSearchParams(window.location.search) : new URLSearchParams();
	const requestedRepairRequestId = String(urlParams.get("repair_request_id") || "");
	const requestedDueType = normalizeDueType(urlParams.get("due_type"));
	const hasRequestedDueType = urlParams.has("due_type");

	const [isOrderModalOpen, setIsOrderModalOpen] = useState<boolean>(false);
	const [orderSearch, setOrderSearch] = useState<string>("");
	const [serviceSearch, setServiceSearch] = useState<string>("");
	const [customerName, setCustomerName] = useState<string>("");
	const [customerPhone, setCustomerPhone] = useState<string>("");
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

	useEffect(() => {
		let isMounted = true;

		const loadData = async () => {
			setIsLoadingData(true);
			try {
				const [servicesResult, ordersResult, packagesResult] = await Promise.allSettled([
					axios.get("/api/shop-owner/repair-services/"),
					axios.get("/api/shop-owner/repairs/"),
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

	const discount = useMemo(() => {
		return 0;
	}, [subtotal]);

	const taxableBase = useMemo(() => Math.max(subtotal - discount, 0), [subtotal, discount]);
	const vatAmount = useMemo(() => taxableBase * (VAT_RATE / 100), [taxableBase]);
	const totalDue = useMemo(() => taxableBase + vatAmount, [taxableBase, vatAmount]);

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
		setOrderSearch("");
		setIsOrderModalOpen(false);
	};

	const addFromServiceCatalog = (service: RepairServiceOption) => {
		if (selectedRepairOrder) return;
		if (isServiceSelected(service)) return;

		setItems((prev) => [
			...prev,
			{
				id: `service-${service.id}-${Date.now()}`,
				label: service.name,
				qty: 1,
				unitPrice: service.price,
				source: "service-catalog",
			},
		]);
	};

	const addPackageToOrder = (pkg: ServicePackageOption) => {
		if (selectedRepairOrder) return;
		if (isPackageSelected(pkg)) return;

		setItems((prev) => [
			...prev,
			{
				id: `package-${pkg.id}-${Date.now()}`,
				label: `${pkg.name} (${pkg.includedServices.length} services)`,
				qty: 1,
				unitPrice: pkg.price,
				source: "package",
			},
		]);
	};

	const isPackageSelected = (pkg: ServicePackageOption): boolean => {
		const packageName = normalizeServiceName(pkg.name);
		return items.some((item) => {
			return item.source === "package" && normalizeServiceName(item.label).startsWith(packageName);
		});
	};

	const isServiceSelected = (service: RepairServiceOption): boolean => {
		const serviceName = normalizeServiceName(service.name);
		return items.some((item) => normalizeServiceName(item.label) === serviceName);
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
			const matchesDate = historyDate.length === 0 || toDateInputValue(receipt.createdAtISO) === historyDate;
			if (!matchesDate) return false;

			if (query.length === 0) return true;
			const haystack = [
				receipt.receiptNo,
				receipt.customerName,
				receipt.customerPhone,
				receipt.cashierName,
				receipt.paymentMethod,
				receipt.items.map((item) => item.label).join(" "),
			]
				.join(" ")
				.toLowerCase();

			return haystack.includes(query);
		});
	}, [historyDate, historySearch, receiptHistory]);

	const combinedCatalogCards = useMemo<CatalogCardItem[]>(() => {
		const packageCards: CatalogCardItem[] = visiblePackages.map((pkg) => ({ kind: "package", key: `package-${pkg.id}`, pkg }));
		const serviceCards: CatalogCardItem[] = filteredServiceCatalog.map((service) => ({ kind: "service", key: `service-${service.id}`, service }));
		return [...packageCards, ...serviceCards];
	}, [visiblePackages, filteredServiceCatalog]);

	const totalCatalogPages = useMemo(() => {
		return Math.max(1, Math.ceil(combinedCatalogCards.length / SERVICES_PER_PAGE));
	}, [combinedCatalogCards.length]);

	const paginatedCatalogCards = useMemo(() => {
		const start = (servicePage - 1) * SERVICES_PER_PAGE;
		return combinedCatalogCards.slice(start, start + SERVICES_PER_PAGE);
	}, [combinedCatalogCards, servicePage]);

	useEffect(() => {
		setServicePage(1);
	}, [serviceSearch]);

	useEffect(() => {
		if (servicePage > totalCatalogPages) {
			setServicePage(totalCatalogPages);
		}
	}, [servicePage, totalCatalogPages]);

	const removeItem = (id: string) => {
		setItems((prev) => prev.filter((item) => item.id !== id));
	};

	const clearTransaction = () => {
		resetOrderInputs();
		setReceiptSnapshot(null);
		setIsReceiptModalOpen(false);
	};

	const handlePay = async () => {
		if (!canPay) return;
		if (!selectedRepairOrder && !requestedRepairRequestId) {
			await Swal.fire({
				icon: "warning",
				title: "Repair Request Required",
				text: "Please open POS from a Job Order so it has a repair request reference.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const repairRequestId = Number(selectedRepairOrder?.id || requestedRepairRequestId);
		if (!Number.isFinite(repairRequestId) || repairRequestId <= 0) {
			await Swal.fire({
				icon: "error",
				title: "Invalid Repair Request",
				text: "Unable to resolve repair request for POS checkout.",
				confirmButtonColor: "#dc2626",
			});
			return;
		}

		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
		const customerType = selectedRepairOrder?.customerId ? "registered" : "walk_in";
		const idempotencyKey = `repair-${repairRequestId}-${effectiveDueType}-${Date.now()}`;

		setIsProcessingPayment(true);
		try {
			const checkoutResponse = await axios.post(
				"/api/repair-pos/checkout",
				{
					repair_request_id: repairRequestId,
					due_type: effectiveDueType,
					idempotency_key: idempotencyKey,
					customer_type: customerType,
					customer_id: selectedRepairOrder?.customerId ?? null,
					walk_in_name: customerName.trim() || null,
					walk_in_phone: customerPhone.trim() || null,
					walk_in_email: null,
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
				subtotal: Number(receiptTotals?.subtotal ?? subtotal),
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
			const message =
				apiErrors?.due_type?.[0]
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
		if (!canPrint || !receiptSnapshot) return;
		setIsReceiptModalOpen(true);
		setTimeout(() => {
			window.print();
		}, 80);
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

	return (
		<AppLayoutShopOwner hideHeader={isOrderModalOpen || isRefundQueueOpen || isReceiptModalOpen || isHistoryModalOpen}>
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

				<div className="grid grid-cols-1 gap-6 xl:h-[calc(100vh-170px)] xl:grid-cols-12 xl:items-stretch">
					<section className="space-y-6 xl:col-span-8 xl:flex xl:h-full xl:flex-col xl:space-y-0 xl:gap-6">
						<div className="grid grid-cols-1 gap-4">
							<div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
								<h2 className="mb-2 text-base font-semibold text-slate-900">Customer Information</h2>
								<p className="mb-3 text-xs text-slate-500">Input customer name. Phone is required for cash and optional for GCash/Card.</p>
								<div className="grid grid-cols-1 gap-2 md:grid-cols-2">
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
								</div>
								{paymentMethod === "cash" && customerPhone.length > 0 && !isCustomerPhoneValid && (
									<p className="mt-2 text-xs font-semibold text-red-600">Phone number must be exactly 11 digits.</p>
								)}
								<p className="mt-2 text-xs text-slate-500">These details will appear on the printed receipt.</p>
								{selectedRepairOrder && (
									<p className="mt-1 text-xs font-semibold text-blue-700">Customer name is locked because this order is attached from Job Order Repair.</p>
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

							<div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 xl:flex-1 xl:min-h-0 xl:content-start xl:overflow-y-auto xl:pr-1">
								{paginatedCatalogCards.map((card) => {
									if (card.kind === "package") {
										const selected = isPackageSelected(card.pkg);
										return (
											<button
												type="button"
												key={card.key}
												onClick={() => addPackageToOrder(card.pkg)}
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
													<p className="mt-3 text-xl font-semibold text-slate-900">{card.pkg.name}</p>
													<p className="mt-1 text-xs text-slate-600">{card.pkg.description}</p>
													<p className="mt-2 text-xs text-slate-700">Includes {card.pkg.includedServices.length} services</p>
													<p className="text-xs text-slate-700">{card.pkg.saveText}</p>
													<div className="mt-auto flex items-center justify-between border-t border-slate-200 pt-3">
														<p className="text-2xl font-bold text-slate-900">{formatPeso(card.pkg.price)}</p>
														<p className="text-xs text-slate-500">Bundle offer</p>
													</div>
												</div>
											</button>
										);
									}

									const isRequestedService = !selectedOrderServiceSet || selectedOrderServiceSet.has(normalizeServiceName(card.service.name));
									const selected = isServiceSelected(card.service);

									return (
										<button
											type="button"
											key={card.key}
											onClick={() => addFromServiceCatalog(card.service)}
											disabled={!isRequestedService}
											className={`h-56 rounded-xl border p-4 text-left transition ${
												isRequestedService
													? "border-slate-200 bg-slate-50 hover:border-blue-300 hover:bg-blue-50"
													: "border-slate-200 bg-slate-100 opacity-45 grayscale cursor-not-allowed"
											}`}
										>
											<div className="flex h-full flex-col">
												<div className="flex items-start justify-between">
													<span className="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-semibold text-slate-600">{card.service.category}</span>
													<span className={`flex h-6 w-6 items-center justify-center rounded-full border ${selected ? "border-blue-500 bg-blue-500 text-white" : "border-slate-300"}`}>
														{selected && (
															<svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
																<path d="M4 10l4 4 8-8" />
															</svg>
														)}
													</span>
												</div>
												<p className="mt-3 text-xl font-semibold text-slate-900">{card.service.name}</p>
												<ul className="mt-2 list-disc pl-5 text-xs text-slate-600">
													<li>{card.service.category} service for customer request.</li>
													<li>Estimated turnaround: {card.service.duration}.</li>
												</ul>
												<div className="mt-auto flex items-center justify-between border-t border-slate-200 pt-3">
													<p className="text-2xl font-bold text-slate-900">{formatPeso(card.service.price)}</p>
													<p className="text-xs text-slate-500">{card.service.duration}</p>
												</div>
												{selectedRepairOrder && isRequestedService && <span className="text-[10px] font-semibold uppercase tracking-wider text-emerald-700">Requested</span>}
											</div>
										</button>
									);
								})}
							</div>

							{combinedCatalogCards.length > 0 && (
								<div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-4 text-sm text-slate-700">
									<p>
										Showing {(servicePage - 1) * SERVICES_PER_PAGE + 1} to {Math.min(servicePage * SERVICES_PER_PAGE, combinedCatalogCards.length)} of {combinedCatalogCards.length} results
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
											onClick={() => setServicePage((prev) => Math.min(prev + 1, totalCatalogPages))}
											disabled={servicePage === totalCatalogPages}
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
									items.map((item) => (
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
									))
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
								inputMode="numeric"
								pattern="[0-9]*"
								min={0}
								value={cashReceivedInput}
								onChange={(event) => setCashReceivedInput(toDigitsOnly(event.target.value))}
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
									<div className="flex items-center justify-between text-slate-600"><span>Subtotal</span><span>{formatPeso(subtotal)}</span></div>
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
											const canApprove = refund.status === 'requested';
											const canExecute = refund.status === 'approved' || refund.status === 'requested';
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
															<button
																type="button"
																onClick={() => performRefundAction(refund.id, 'approve', {})}
																disabled={!canApprove || processingRefundId === refund.id}
																className="rounded-lg border border-blue-300 px-3 py-1 text-xs font-semibold text-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
															>
																Approve
															</button>
															<button
																type="button"
																onClick={() => performRefundAction(refund.id, 'reject', { rejection_reason: 'Rejected from POS queue' })}
																disabled={!canApprove || processingRefundId === refund.id}
																className="rounded-lg border border-red-300 px-3 py-1 text-xs font-semibold text-red-700 disabled:cursor-not-allowed disabled:opacity-40"
															>
																Reject
															</button>
															<button
																type="button"
																onClick={() => performRefundAction(refund.id, 'execute', { execution_mode: 'manual' })}
																disabled={!canExecute || processingRefundId === refund.id}
																className="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
															>
																Execute
															</button>
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
														<p className="text-xs text-slate-600">Method: {receipt.paymentMethod.toUpperCase()}</p>
													</div>
													<div className="flex items-center gap-2">
														<p className="text-sm font-bold text-slate-900">{formatPeso(receipt.totalDue)}</p>
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
								<button
									type="button"
									onClick={() => setIsReceiptModalOpen(false)}
									className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50"
								>
									Close
								</button>
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
