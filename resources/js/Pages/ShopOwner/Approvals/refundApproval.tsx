import { Head, router, usePage } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import Swal from "sweetalert2";
import AppLayout_shopOwner from "../../../layout/AppLayout_shopOwner";
import RefundStageBadge from "../../../components/refunds/RefundStageBadge";
import { buildRepairRefundExecutionPayload, type RepairExecutionChannel } from "../../ERP/Finance/repairRefundExecutionPayload";

const initialRefundRequests = [
	{
		id: 101,
		orderNumber: "SO-2026-0012",
		customerName: "Ana Rivera",
		orderTotal: "₱2,500",
		refundAmount: "₱2,500",
		refundMethod: "GCash",
		requestedBy: "John Manager",
		requestDate: "2026-02-09",
		refundReason: "Product defective or damaged",
		refundNote: "Customer reported minor stitching issue on arrival.",
		reason: "Size mismatch and minor stitching issue",
		status: "Pending",
		media: [
			"/images/product/product-01.jpg",
			"/images/product/product-02.jpg",
			"/images/product/product-03.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
		],
	},
	{
		id: 102,
		orderNumber: "SO-2026-0015",
		customerName: "Marco Santos",
		orderTotal: "₱1,200",
		refundAmount: "₱1,200",
		refundMethod: "Bank Transfer",
		requestedBy: "Jane Supervisor",
		requestDate: "2026-02-08",
		refundReason: "Item not as described",
		refundNote: "Packaging was damaged on arrival.",
		reason: "Delayed delivery beyond promised date",
		status: "Pending",
		media: [
			"/images/product/product-02.jpg",
			"/images/product/product-03.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-06.jpg",
		],
	},
	{
		id: 103,
		orderNumber: "SO-2026-0010",
		customerName: "Lia Gomez",
		orderTotal: "₱3,800",
		refundAmount: "₱3,800",
		refundMethod: "Credit Card",
		requestedBy: "Mike Manager",
		requestDate: "2026-02-05",
		refundReason: "Quality issues",
		refundNote: "Outsole separation after first use.",
		reason: "Defective outsole reported",
		status: "Approved",
		media: [
			"/images/product/product-03.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-06.jpg",
			"/images/product/product-07.jpg",
		],
	},
	{
		id: 104,
		orderNumber: "SO-2026-0007",
		customerName: "Rafael Cruz",
		orderTotal: "₱900",
		refundAmount: "₱900",
		refundMethod: "GCash",
		requestedBy: "Sarah Lead",
		requestDate: "2026-02-03",
		refundReason: "Changed my mind",
		refundNote: "Customer requested refund after delivery.",
		reason: "Customer changed mind after purchase",
		status: "Rejected",
		rejectionReason: "Return window expired",
		media: [
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-06.jpg",
			"/images/product/product-07.jpg",
			"/images/product/product-08.jpg",
		],
	},
	{
		id: 105,
		orderNumber: "SO-2026-0018",
		customerName: "Paolo Reyes",
		orderTotal: "₱1,750",
		refundAmount: "₱1,750",
		refundMethod: "Bank Transfer",
		requestedBy: "John Manager",
		requestDate: "2026-02-10",
		refundReason: "Wrong item received",
		refundNote: "Color variant mismatch.",
		reason: "Wrong color variant delivered",
		status: "Pending",
		media: [
			"/images/product/product-01.jpg",
			"/images/product/product-03.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-07.jpg",
			"/images/product/product-08.jpg",
		],
	},
	{
		id: 106,
		orderNumber: "SO-2026-0021",
		customerName: "Kyla Dizon",
		orderTotal: "₱2,950",
		refundAmount: "₱2,950",
		refundMethod: "Credit Card",
		requestedBy: "Jane Supervisor",
		requestDate: "2026-02-10",
		refundReason: "Quality issues",
		refundNote: "Glue marks visible on outsole.",
		reason: "Visible glue marks on outsole",
		status: "Pending",
		media: [
			"/images/product/product-02.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-06.jpg",
			"/images/product/product-07.jpg",
			"/images/product/product-08.jpg",
		],
	},
	{
		id: 107,
		orderNumber: "SO-2026-0009",
		customerName: "Noel Ramos",
		orderTotal: "₱1,100",
		refundAmount: "₱1,100",
		refundMethod: "GCash",
		requestedBy: "Mike Manager",
		requestDate: "2026-02-04",
		refundReason: "Product defective or damaged",
		refundNote: "Stitching torn on first wear.",
		reason: "Item arrived with torn stitching",
		status: "Approved",
		media: [
			"/images/product/product-01.jpg",
			"/images/product/product-02.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-06.jpg",
			"/images/product/product-08.jpg",
		],
	},
	{
		id: 108,
		orderNumber: "SO-2026-0013",
		customerName: "Jessa Lim",
		orderTotal: "₱3,200",
		refundAmount: "₱3,200",
		refundMethod: "Bank Transfer",
		requestedBy: "Sarah Lead",
		requestDate: "2026-02-06",
		refundReason: "Other",
		refundNote: "Order canceled before shipment.",
		reason: "Order cancelled before shipment",
		status: "Approved",
		media: [
			"/images/product/product-02.jpg",
			"/images/product/product-03.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-07.jpg",
			"/images/product/product-08.jpg",
		],
	},
	{
		id: 109,
		orderNumber: "SO-2026-0023",
		customerName: "Luis Tan",
		orderTotal: "₱2,100",
		refundAmount: "₱2,100",
		refundMethod: "GCash",
		requestedBy: "John Manager",
		requestDate: "2026-02-11",
		refundReason: "Wrong item received",
		refundNote: "Incorrect size received.",
		reason: "Incorrect size sent",
		status: "Pending",
		media: [
			"/images/product/product-01.jpg",
			"/images/product/product-03.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-06.jpg",
			"/images/product/product-07.jpg",
		],
	},
	{
		id: 110,
		orderNumber: "SO-2026-0011",
		customerName: "Mika Torres",
		orderTotal: "₱1,450",
		refundAmount: "₱1,450",
		refundMethod: "Credit Card",
		requestedBy: "Jane Supervisor",
		requestDate: "2026-02-05",
		refundReason: "Quality issues",
		refundNote: "Minor defect on toe cap.",
		reason: "Minor defect on toe cap",
		status: "Rejected",
		rejectionReason: "Issue resolved with exchange",
		media: [
			"/images/product/product-02.jpg",
			"/images/product/product-03.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-06.jpg",
			"/images/product/product-08.jpg",
		],
	},
];

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

const ReceiptIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
	</svg>
);

interface RefundRequest {
	id: number;
	refundType?: "order" | "repair";
	workflowSource?: string;
	repairerStatus?: string;
	preferredReturnChannel?: string;
	preferredReturnAccountName?: string;
	preferredReturnAccountRef?: string;
	customerPayoutConsent?: boolean;
	orderNumber: string;
	customerName: string;
	orderTotal?: string;
	refundAmount: string;
	refundMethod: string;
	requestedBy: string;
	requestDate: string;
	refundReason?: string;
	refundNote?: string;
	reason: string;
	status: "Pending" | "Approved" | "Rejected";
	rawStatus?: string;
	shopOwnerStatus?: string;
	financeStatus?: string;
	requiresOwnerApproval?: boolean;
	approvalStage?: string;
	returnStatus?: string;
	refundExecutedAt?: string | null;
	refundedAt?: string | null;
	rejectionReason?: string;
	media?: string[];
	refundPaymentType?: "pure_online" | "mixed" | "manual_only" | string;
	hasGatewayLeg?: boolean;
	hasPosManualLeg?: boolean;
	financeExecution?: {
		execution_channel?: string;
		execution_reference?: string;
		execution_amount?: number;
		execution_proof_urls?: string[];
	};
}

const isSameRefundRequest = (left: RefundRequest, right: RefundRequest): boolean => {
	return left.id === right.id && (left.refundType || "order") === (right.refundType || "order");
};

const getShopOwnerActionBase = (request: RefundRequest): string => {
	return request.refundType === "repair" ? "/api/shop-owner/repair-refunds" : "/api/shop-owner/refunds";
};

const parseCurrencyToNumber = (value: string): number => {
	const parsed = Number(String(value || "").replace(/[^0-9.-]/g, ""));
	return Number.isFinite(parsed) ? parsed : 0;
};

const escapeSwalText = (value?: string): string => {
	return String(value || "")
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#39;");
};

const normalizeReasonDetails = (reason: unknown, otherReasonNote?: unknown): string => {
	const raw = String(reason ?? "").trim();
	const stripped = raw.replace(/\bRefund scope:\s*(?:full|partial)\b[\s\S]*$/i, "").trim();
	const base = stripped !== "" ? stripped : raw;
	const other = String(otherReasonNote ?? "").trim();

	if (other !== "" && !base.toLowerCase().includes(other.toLowerCase())) {
		return `${base}${base !== "" ? "\n\n" : ""}Other reason note: ${other}`;
	}

	return base;
};

const normalizeRefundRequestForDisplay = (item: RefundRequest): RefundRequest => {
	const normalizedReason = normalizeReasonDetails(item.reason, (item as any).otherReasonNote ?? (item as any).other_reason_note);
	return {
		...item,
		reason: normalizedReason,
		refundNote: normalizeReasonDetails(item.refundNote, (item as any).otherReasonNote ?? (item as any).other_reason_note),
	};
};

const formatPayoutChannelLabel = (channel?: string): string => {
	switch (String(channel || "").toLowerCase()) {
		case "gcash":
			return "GCash";
		case "card":
			return "Card";
		case "bank_transfer":
			return "Bank Transfer";
		case "manual_cash":
			return "Manual Cash";
		default:
			return "Not specified";
	}
};

const resolveRepairRefundPaymentType = (request: RefundRequest): "pure_online" | "mixed" | "manual_only" => {
	if (request.hasGatewayLeg && request.hasPosManualLeg) {
		return "mixed";
	}

	if (request.hasGatewayLeg && !request.hasPosManualLeg) {
		return "pure_online";
	}

	if (!request.hasGatewayLeg && request.hasPosManualLeg) {
		return "manual_only";
	}

	const declaredType = String(request.refundPaymentType || "").toLowerCase();
	if (declaredType === "pure_online" || declaredType === "mixed" || declaredType === "manual_only") {
		return declaredType;
	}

	if (request.hasGatewayLeg && request.hasPosManualLeg) {
		return "mixed";
	}

	if (request.hasGatewayLeg && !request.hasPosManualLeg) {
		return "pure_online";
	}

	return "manual_only";
};

const refundReasonOptions = [
	"Product defective or damaged",
	"Wrong item received",
	"Item not as described",
	"Missing parts or accessories",
	"Quality issues",
	"Changed my mind",
	"Better price elsewhere",
	"Other",
];

const refundMethodOptions = [
	"Original Payment Method",
	"Bank Transfer",
	"GCash",
	"PayMongo Wallet",
];

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

export default function RefundApproval() {
	const { auth } = usePage().props as any;
	const registrationType = String(auth?.shop_owner?.registration_type || auth?.registration_type || "").toLowerCase();
	const isIndividualRegistration = registrationType === "individual";

	const [requests, setRequests] = useState<RefundRequest[]>([]);
	const [currentPage, setCurrentPage] = useState(1);
	const [viewModalOpen, setViewModalOpen] = useState(false);
	const [selectedRequest, setSelectedRequest] = useState<RefundRequest | null>(null);
	const [activeImage, setActiveImage] = useState<string | null>(null);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState("All");
	const [isActionProcessing, setIsActionProcessing] = useState(false);
	const [isLoading, setIsLoading] = useState(false);
	const [executeModalOpen, setExecuteModalOpen] = useState(false);
	const [executeRequest, setExecuteRequest] = useState<RefundRequest | null>(null);
	const [executeMode, setExecuteMode] = useState<"manual" | "gateway">("manual");
	const [executeChannel, setExecuteChannel] = useState<RepairExecutionChannel>("gcash");
	const [executeAmount, setExecuteAmount] = useState(0);
	const [executeReference, setExecuteReference] = useState("");
	const [executeProofUrlsText, setExecuteProofUrlsText] = useState("");
	const [executeError, setExecuteError] = useState("");
	const hasAppliedFocusOrder = useRef(false);

	useEffect(() => {
		if (typeof window === "undefined") {
			return;
		}

		const params = new URLSearchParams(window.location.search);
		const requestedStatus = params.get("status");
		const focusOrder = params.get("focus_order");

		if (requestedStatus && ["All", "Pending", "Approved", "Rejected"].includes(requestedStatus)) {
			setStatusFilter(requestedStatus);
		}

		if (focusOrder) {
			setSearchQuery(focusOrder);
			setCurrentPage(1);
		}
	}, []);

	const isVideoEvidence = (src: string): boolean => {
		return /\.(mp4|mov|avi|mkv|webm)(\?.*)?$/i.test(src);
	};

	const fetchRefundRequests = async () => {
		setIsLoading(true);
		try {
			const params = new URLSearchParams();
			if (statusFilter !== "All") {
				params.append("status", statusFilter);
			}
			if (searchQuery.trim()) {
				params.append("search", searchQuery.trim());
			}

			const orderResponse = await fetch(`/api/shop-owner/refunds?${params.toString()}`, {
				credentials: "include",
				headers: {
					Accept: "application/json",
				},
			});

			const repairResponse = await fetch(`/api/shop-owner/repair-refunds?${params.toString()}`, {
				credentials: "include",
				headers: {
					Accept: "application/json",
				},
			});

			const orderData = await orderResponse.json();
			const repairData = repairResponse.status === 404 ? { data: [] } : await repairResponse.json();
			if (!orderResponse.ok) {
				throw new Error(orderData?.message || "Failed to load refund requests");
			}
			if (!repairResponse.ok && repairResponse.status !== 404) {
				throw new Error(repairData?.message || "Failed to load repair refund requests");
			}
			if (repairResponse.status === 404) {
				console.warn("Shop owner repair refund endpoint unavailable: /api/shop-owner/repair-refunds");
			}

			const normalizedOrderRefunds: RefundRequest[] = (Array.isArray(orderData?.data) ? orderData.data : []).map((item: any) => ({
				...item,
				refundType: "order",
			})).map(normalizeRefundRequestForDisplay);

			const normalizedRepairRefunds: RefundRequest[] = (Array.isArray(repairData?.data) ? repairData.data : []).map((item: any) => ({
				...item,
				refundType: "repair",
			})).map(normalizeRefundRequestForDisplay);

			setRequests(
				[...normalizedOrderRefunds, ...normalizedRepairRefunds].sort((a, b) =>
					new Date(b.requestDate).getTime() - new Date(a.requestDate).getTime(),
				),
			);
		} catch (error) {
			Swal.fire({
				icon: "error",
				title: "Failed",
				text: error instanceof Error ? error.message : "Unable to load refund requests.",
				confirmButtonColor: "#2563eb",
			});
		} finally {
			setIsLoading(false);
		}
	};

	useEffect(() => {
		void fetchRefundRequests();
	}, [statusFilter, searchQuery]);

	useEffect(() => {
		if (typeof window === "undefined" || isLoading || hasAppliedFocusOrder.current || requests.length === 0) {
			return;
		}

		const params = new URLSearchParams(window.location.search);
		const focusOrder = String(params.get("focus_order") || "").trim().toLowerCase();
		if (!focusOrder) {
			return;
		}

		const matchedRequest = requests.find(
			(request) => String(request.orderNumber || "").trim().toLowerCase() === focusOrder,
		);
		if (!matchedRequest) {
			return;
		}

		hasAppliedFocusOrder.current = true;
		setSelectedRequest(matchedRequest);
		setViewModalOpen(true);

		params.delete("focus_order");
		const nextQuery = params.toString();
		window.history.replaceState({}, "", `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ""}`);
	}, [isLoading, requests]);

	const filteredData = useMemo(() => {
		return requests.filter((item) => {
			const matchesSearch =
				item.orderNumber.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.customerName.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.requestedBy.toLowerCase().includes(searchQuery.toLowerCase());
			const matchesStatus = statusFilter === "All" || item.status === statusFilter;
			return matchesSearch && matchesStatus;
		});
	}, [requests, searchQuery, statusFilter]);

	const itemsPerPage = 5;
	const totalPages = Math.ceil(filteredData.length / itemsPerPage) || 1;
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedRequests = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const pendingCount = requests.filter((r) => r.status === "Pending").length;
	const approvedCount = requests.filter((r) => r.status === "Approved").length;
	const rejectedCount = requests.filter((r) => r.status === "Rejected").length;

	const handleViewClick = (request: RefundRequest) => {
		setSelectedRequest(request);
		setViewModalOpen(true);
	};

	const handleCloseModal = () => {
		setViewModalOpen(false);
		setActiveImage(null);
	};

	const canShopOwnerApprove = (request: RefundRequest): boolean => {
		const rawStatus = String(request.rawStatus || "").toLowerCase();
		const financeStatus = String(request.financeStatus || "").toLowerCase();
		const shopOwnerStatus = String(request.shopOwnerStatus || "").toLowerCase();

		if (request.refundType === "repair") {
			const financeReadyForRepairOwner = isIndividualRegistration
				? ["pending", "approved_initial", "approved"].includes(financeStatus)
				: financeStatus === "approved_initial";

			return request.status === "Pending"
				&& financeReadyForRepairOwner
				&& shopOwnerStatus === "pending"
				&& !["rejected", "failed", "succeeded", "completed", "paid"].includes(rawStatus);
		}

		const requiresOwnerApproval = isIndividualRegistration ? true : request.requiresOwnerApproval !== false;
		const financeReadyForOwner = isIndividualRegistration
			? ["pending", "approved_initial", "approved"].includes(financeStatus)
			: financeStatus === "approved_initial";

		return request.status === "Pending"
			&& requiresOwnerApproval
			&& financeReadyForOwner
			&& shopOwnerStatus !== "approved"
			&& !["rejected", "failed", "succeeded", "completed", "paid"].includes(rawStatus);
	};

	const canShopOwnerReject = (request: RefundRequest): boolean => {
		const financeStatus = String(request.financeStatus || "").toLowerCase();
		const shopOwnerStatus = String(request.shopOwnerStatus || "").toLowerCase();

		if (request.refundType === "repair") {
			const financeReadyForRepairOwner = isIndividualRegistration
				? ["pending", "approved_initial", "approved"].includes(financeStatus)
				: financeStatus === "approved_initial";

			return request.status === "Pending"
				&& financeReadyForRepairOwner
				&& shopOwnerStatus === "pending";
		}
		
		return request.status === "Pending"
			&& shopOwnerStatus === "pending";
	};

	const handleApprove = async (request: RefundRequest) => {
		if (!canShopOwnerApprove(request)) {
			await Swal.fire({
				title: "Approval Not Allowed",
				text: "This refund has already been approved by shop owner or is no longer approvable.",
				icon: "info",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const financeStatus = String(request.financeStatus || "").toLowerCase();
		if (!isIndividualRegistration && request.requiresOwnerApproval === false) {
			await Swal.fire({
				title: "Owner Approval Not Required",
				text: "This refund request does not require shop owner approval based on settings.",
				icon: "info",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		if (!isIndividualRegistration && financeStatus !== "approved_initial") {
			await Swal.fire({
				title: "Finance Approval Required",
				text: "Finance initial approval is required before shop owner approval.",
				icon: "info",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const result = await Swal.fire({
			title: "Approve Refund?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Customer:</strong> ${request.customerName}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Amount:</strong> ${request.refundAmount}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Method:</strong> ${request.refundMethod}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Requested by:</strong> ${request.requestedBy}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Reason:</strong> ${request.reason}</p>
				</div>
			`,
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#10b981",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
		});

		if (result.isConfirmed) {
			setIsActionProcessing(true);
			try {
				const response = await fetch(`${getShopOwnerActionBase(request)}/${request.id}/approve`, {
					method: "POST",
					credentials: "include",
					headers: {
						"Content-Type": "application/json",
						Accept: "application/json",
						"X-CSRF-TOKEN":
							document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
					},
					body: JSON.stringify({}),
				});

				const data = await response.json();
				if (!response.ok) {
					throw new Error(data?.message || "Failed to approve refund request.");
				}

				const updatedRefund = { ...request, ...(data?.refund || data?.data || {}), status: "Approved" };
				setRequests((prev) =>
					prev.map((r) => (isSameRefundRequest(r, request) ? updatedRefund : r))
				);

				const nextSelected: RefundRequest = {
					...updatedRefund,
					status: "Approved",
					shopOwnerStatus: "approved",
					financeStatus: request.refundType === "repair" && isIndividualRegistration
						? "approved"
						: (updatedRefund.financeStatus ?? request.financeStatus),
				};
				setSelectedRequest(nextSelected);
				
				await Swal.fire({
					title: "Approved!",
					text: data?.message || (
						request.refundType === "repair" && isIndividualRegistration
							? "Shop owner approval recorded. You can execute payout now."
							: "Shop owner approval recorded. Awaiting finance final approval."
					),
					icon: "success",
					confirmButtonColor: "#2563eb",
				});

				if (request.refundType !== "repair") {
					router.visit(
						`/shop-owner/job-orders-retail?tab=refund&focus_order=${encodeURIComponent(request.orderNumber)}`,
					);
					return;
				}

				await fetchRefundRequests();
				return;
			} catch (error) {
				Swal.fire({
					title: "Failed",
					text: error instanceof Error ? error.message : "Unable to approve refund request.",
					icon: "error",
					confirmButtonColor: "#2563eb",
				});
			} finally {
				setIsActionProcessing(false);
			}
		}
	};

	const handleReject = async (request: RefundRequest) => {
		if (!canShopOwnerReject(request)) {
			await Swal.fire({
				title: "Rejection Not Allowed",
				text: request.refundType === "repair" && !isIndividualRegistration
					? "Finance initial approval is required before shop owner rejection for repair refunds."
					: "This refund is no longer rejectable.",
				icon: "info",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const { value: reason } = await Swal.fire({
			title: "Reject Refund",
			html: `
				<div style="text-align: left; margin-bottom: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Amount:</strong> ${request.refundAmount}</p>
				</div>
			`,
			input: "textarea",
			inputLabel: "Rejection Reason",
			inputPlaceholder: "Enter the reason for rejection...",
			inputAttributes: {
				"aria-label": "Enter the reason for rejection",
			},
			showCancelButton: true,
			confirmButtonColor: "#ef4444",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Reject",
			cancelButtonText: "Cancel",
			inputValidator: (value) => {
				if (!value) {
					return "Please provide a reason for rejection";
				}
			},
		});

		if (reason) {
			setIsActionProcessing(true);
			try {
				const response = await fetch(`${getShopOwnerActionBase(request)}/${request.id}/reject`, {
					method: "POST",
					credentials: "include",
					headers: {
						"Content-Type": "application/json",
						Accept: "application/json",
						"X-CSRF-TOKEN":
							document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
					},
					body: JSON.stringify(request.refundType === "repair" ? { reason } : { rejection_reason: reason }),
				});

				const data = await response.json();
				if (!response.ok) {
					throw new Error(data?.message || "Failed to reject refund request.");
				}

				setRequests((prev) =>
					prev.map((r) =>
						isSameRefundRequest(r, request)
							? { ...r, ...(data?.refund || data?.data || {}), status: "Rejected", rejectionReason: reason }
							: r
					)
				);
				Swal.fire({
					title: "Rejected",
					text: data?.message || "The refund request has been rejected.",
					icon: "info",
					confirmButtonColor: "#2563eb",
				});
				await fetchRefundRequests();
			} catch (error) {
				Swal.fire({
					title: "Failed",
					text: error instanceof Error ? error.message : "Unable to reject refund request.",
					icon: "error",
					confirmButtonColor: "#2563eb",
				});
			} finally {
				setIsActionProcessing(false);
			}
		}
	};

	const canExecuteGatewayRefund = (request: RefundRequest): boolean => {
		if (!isIndividualRegistration) {
			return false;
		}

		const rawStatus = String(request.rawStatus || "").toLowerCase();
		const financeStatus = String(request.financeStatus || "").toLowerCase();
		const shopOwnerStatus = String(request.shopOwnerStatus || "").toLowerCase();

		if (request.refundType === "repair") {
			const hasExecutionStarted = Boolean(request.refundExecutedAt);

			return financeStatus === "approved"
				&& ["approved", "skipped"].includes(shopOwnerStatus)
				&& !hasExecutionStarted
				&& !["processing", "succeeded", "failed", "rejected", "cancelled"].includes(rawStatus);
		}

		const returnStatus = String(request.returnStatus || "").toLowerCase();
		const hasExecutionStarted = Boolean(request.refundExecutedAt);

		return financeStatus === "approved"
			&& shopOwnerStatus === "approved"
			&& ["in_transit", "received"].includes(returnStatus)
			&& !hasExecutionStarted
			&& !["processing", "succeeded", "failed", "rejected"].includes(rawStatus);
	};

	const getExecuteActionLabel = (request: RefundRequest): string => {
		if (request.refundType !== "repair") {
			return "Execute Payout";
		}

		const paymentType = resolveRepairRefundPaymentType(request);
		if (paymentType === "pure_online") {
			return "Execute PayMongo Refund";
		}

		if (paymentType === "mixed") {
			return "Execute Mixed Refund";
		}

		return "Execute Manual Payout";
	};

	const resolveExecutionChannel = (request: RefundRequest): RepairExecutionChannel => {
		const preferred = String(request.preferredReturnChannel || "").toLowerCase();
		if (preferred === "gcash" || preferred === "card" || preferred === "bank_transfer" || preferred === "manual_cash") {
			return preferred as RepairExecutionChannel;
		}

		const financeChannel = String(request.financeExecution?.execution_channel || "").toLowerCase();
		if (financeChannel === "gcash" || financeChannel === "card" || financeChannel === "bank_transfer" || financeChannel === "manual_cash") {
			return financeChannel as RepairExecutionChannel;
		}

		return "gcash";
	};

	const resolveFixedExecutionAmount = (request: RefundRequest): number => {
		const fromExecution = Number(request.financeExecution?.execution_amount);
		if (Number.isFinite(fromExecution) && fromExecution > 0) {
			return Math.round(fromExecution * 100) / 100;
		}

		const parsed = parseCurrencyToNumber(request.refundAmount);
		return Math.round(parsed * 100) / 100;
	};

	const closeExecuteModal = () => {
		if (isActionProcessing) {
			return;
		}

		setExecuteModalOpen(false);
		setExecuteRequest(null);
		setExecuteMode("manual");
		setExecuteChannel("gcash");
		setExecuteAmount(0);
		setExecuteReference("");
		setExecuteProofUrlsText("");
		setExecuteError("");
	};

	const openExecuteModal = (request: RefundRequest) => {
		if (request.refundType !== "repair") {
			return;
		}

		const paymentType = resolveRepairRefundPaymentType(request);
		const mode: "manual" | "gateway" = paymentType === "pure_online" ? "gateway" : "manual";
		const channel = resolveExecutionChannel(request);
		const amount = resolveFixedExecutionAmount(request);
		const reference = request.financeExecution?.execution_reference || request.preferredReturnAccountRef || "";
		const proofText = Array.isArray(request.financeExecution?.execution_proof_urls)
			? (request.financeExecution?.execution_proof_urls ?? []).join("\n")
			: "";

		setExecuteRequest(request);
		setExecuteMode(mode);
		setExecuteChannel(channel);
		setExecuteAmount(amount);
		setExecuteReference(reference);
		setExecuteProofUrlsText(proofText);
		setExecuteError("");
		setExecuteModalOpen(true);
	};

	const submitExecuteRequest = async (request: RefundRequest, payload: Record<string, unknown>): Promise<boolean> => {
		setIsActionProcessing(true);
		try {
			const response = await fetch(
				request.refundType === "repair"
					? `/api/shop-owner/repair-refunds/${request.id}/execute`
					: `/api/shop-owner/refunds/${request.id}/execute-gateway-refund`,
				{
				method: "POST",
				credentials: "include",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
				},
				body: JSON.stringify(payload),
			},
			);

			const data = await response.json();
			if (!response.ok) {
				if (response.status === 409) {
					setSelectedRequest((prev) => (prev && isSameRefundRequest(prev, request) ? { ...prev, ...(data?.refund || {}) } : prev));
					await fetchRefundRequests();
					await Swal.fire({
						title: "Already Started",
						text: data?.message || "Refund execution has already started for this request.",
						icon: "info",
						confirmButtonColor: "#2563eb",
					});
					return true;
				}

				throw new Error(data?.message || "Failed to execute refund payout.");
			}

			setSelectedRequest((prev) => (prev && isSameRefundRequest(prev, request) ? { ...prev, ...(data?.refund || data?.data || {}) } : prev));

			Swal.fire({
				title: "Payout Execution Started",
				text: data?.message || "Refund payout execution has started.",
				icon: "success",
				confirmButtonColor: "#2563eb",
			});

			await fetchRefundRequests();
			return true;
		} catch (error) {
			Swal.fire({
				title: "Failed",
				text: error instanceof Error ? error.message : "Unable to execute refund payout.",
				icon: "error",
				confirmButtonColor: "#2563eb",
			});
			return false;
		} finally {
			setIsActionProcessing(false);
		}
	};

	const handleSubmitExecuteModal = async () => {
		if (!executeRequest) {
			return;
		}

		setExecuteError("");

		let payload: Record<string, unknown>;
		if (executeMode === "gateway") {
			payload = buildRepairRefundExecutionPayload({ executionMode: "gateway" });
		} else {
			const reference = executeReference.trim();
			if (!reference) {
				setExecuteError("Execution reference is required for manual refund execution.");
				return;
			}

			if (!Number.isFinite(executeAmount) || executeAmount <= 0) {
				setExecuteError("Execution amount must be greater than zero.");
				return;
			}

			const executionProofUrls = executeProofUrlsText
				.split(/\r?\n|,/)
				.map((item) => item.trim())
				.filter(Boolean);

			if (executionProofUrls.length === 0) {
				setExecuteError("At least one proof URL is required for manual refund execution.");
				return;
			}

			payload = buildRepairRefundExecutionPayload({
				executionMode: "manual",
				executionChannel: executeChannel,
				executionReference: reference,
				executionAmount: executeAmount,
				executionProofUrls,
			});
		}

		const succeeded = await submitExecuteRequest(executeRequest, payload);
		if (succeeded) {
			closeExecuteModal();
			window.setTimeout(() => {
				window.location.reload();
			}, 120);
		}
	};

	const handleExecuteGatewayRefund = async (request: RefundRequest) => {
		if (request.refundType === "repair") {
			openExecuteModal(request);
			return;
		}

		{
			const result = await Swal.fire({
				title: "Execute Refund Payout?",
				html: `
					<div style="text-align: left; margin-top: 1rem;">
						<p style="margin-bottom: 0.5rem;"><strong>Customer:</strong> ${request.customerName}</p>
						<p style="margin-bottom: 0.5rem;"><strong>Amount:</strong> ${request.refundAmount}</p>
						<p style="margin-bottom: 0.5rem;"><strong>Method:</strong> ${request.refundMethod}</p>
					</div>
				`,
				icon: "question",
				showCancelButton: true,
				confirmButtonColor: "#10b981",
				cancelButtonColor: "#6b7280",
				confirmButtonText: "Execute",
				cancelButtonText: "Cancel",
			});

			if (!result.isConfirmed) {
				return;
			}
		}

		const succeeded = await submitExecuteRequest(request, {});
		if (succeeded) {
			window.setTimeout(() => {
				window.location.reload();
			}, 120);
		}
	};

	return (
		<AppLayout_shopOwner>
			<Head title="Refund Approvals - Shop Owner" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1 text-gray-900 dark:text-white">Refund Approvals</h1>
						<p className="text-gray-600 dark:text-gray-400">Review and approve refund requests from customers</p>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
					<MetricCard
						title="Pending Approvals"
						value={pendingCount}
						change={9}
						changeType="increase"
						icon={ClockIcon}
						color="warning"
						description="Awaiting your review"
					/>
					<MetricCard
						title="Approved"
						value={approvedCount}
						change={6}
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
						<h2 className="text-lg font-semibold text-gray-900 dark:text-white">Refund Requests</h2>
						<p className="text-sm text-gray-500 dark:text-gray-400">Review and take action on refund requests</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by order number, customer, or requestor..."
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
								aria-label="Filter refund requests by status"
								value={statusFilter}
								onChange={(e) => {
									setStatusFilter(e.target.value);
									setCurrentPage(1);
								}}
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
									<th className="pb-3 font-medium">Customer</th>
									<th className="pb-3 font-medium">Amount</th>
									<th className="pb-3 font-medium">Method</th>
									<th className="pb-3 font-medium">Requested By</th>
									<th className="pb-3 font-medium">Status</th>
									<th className="pb-3 font-medium">Refund/Return Stage</th>
									<th className="pb-3 font-medium text-right">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
								{paginatedRequests.map((request) => (
									<tr key={`${request.refundType || "order"}-${request.id}`} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
										<td className="py-4 text-gray-700 dark:text-gray-300">{request.customerName}</td>
										<td className="py-4 text-gray-700 dark:text-gray-300">{request.refundAmount}</td>
										<td className="py-4 text-gray-700 dark:text-gray-300">{request.refundMethod}</td>
										<td className="py-4 text-gray-700 dark:text-gray-300">{request.requestedBy}</td>
										<td className="py-4">
											<span
												className={`px-2 py-1 rounded-full text-xs font-semibold ${
													request.status === "Pending"
														? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300"
														: request.status === "Approved"
														? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
														: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
												}`}
											>
												{request.status}
											</span>
										</td>
											<td className="py-4">
												<RefundStageBadge request={{ ...request, isIndividualRegistration }} />
											</td>
										<td className="py-4 text-right">
											<div className="inline-flex items-center gap-2">
												<button
													onClick={() => handleViewClick(request)}
													className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
													title="View Details"
												>
													<EyeIcon className="w-5 h-5" />
												</button>
												{/* Approve/Reject buttons removed from table rows; actions are available in the detail modal. */}
											</div>
										</td>
									</tr>
								))}
								{paginatedRequests.length === 0 && (
									<tr>
									<td colSpan={7} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
											{isLoading ? "Loading refund requests..." : "No refund requests found."}
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
										className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
										title="Previous page"
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
													className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
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
					)}
				</div>
			</div>

			{viewModalOpen && selectedRequest && (
				<div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={handleCloseModal} />
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-5xl max-h-[90vh] overflow-hidden">
						<div className="px-8 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
							<div className="flex items-center gap-3">
								<div className="flex items-center justify-center w-10 h-10 rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
									<ReceiptIcon className="size-5" />
								</div>
								<div>
									<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Refund Request Details</h3>
									<p className="text-sm text-gray-500 dark:text-gray-400">Request #{selectedRequest.id}</p>
								</div>
							</div>
							<button
								onClick={handleCloseModal}
								aria-label="Close refund details"
								title="Close"
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
							>
								<XIcon className="size-5" />
							</button>
						</div>

						<div className="px-8 py-6 overflow-y-auto max-h-[70vh]">
							<div className="space-y-6">
								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Refund</p>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-800/40">
										{selectedRequest.refundReason || selectedRequest.reason}
									</div>
								</div>

								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason Details</p>
									<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900">
										{selectedRequest.reason}
									</div>
								</div>

								<div className="grid grid-cols-2 gap-4">
									<div>
										<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Refund Amount</p>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm font-semibold text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-800/40">
											{selectedRequest.refundAmount}
										</div>
									</div>
									<div>
										<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Refund Method</p>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900">
											{selectedRequest.refundMethod}
										</div>
									</div>
								</div>

								{selectedRequest.refundType === "repair" && resolveRepairRefundPaymentType(selectedRequest) !== "pure_online" && (
									<div>
										<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Customer Payout Destination</p>
										<div className="border border-gray-200 dark:border-gray-800 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900 grid grid-cols-1 sm:grid-cols-3 gap-3">
											<div>
												<p className="text-xs uppercase tracking-wide text-gray-500">Channel</p>
												<p className="font-semibold text-gray-900 dark:text-gray-100">{formatPayoutChannelLabel(selectedRequest.preferredReturnChannel)}</p>
											</div>
											<div>
												<p className="text-xs uppercase tracking-wide text-gray-500">Account Name</p>
												<p className="font-semibold text-gray-900 dark:text-gray-100">{selectedRequest.preferredReturnAccountName || "Not provided"}</p>
											</div>
											<div>
												<p className="text-xs uppercase tracking-wide text-gray-500">Account Ref / Number</p>
												<p className="font-semibold text-gray-900 dark:text-gray-100">{selectedRequest.preferredReturnAccountRef || "Not provided"}</p>
											</div>
										</div>
									</div>
								)}

								<div>
									<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Refund Evidence</p>
									{selectedRequest.media && selectedRequest.media.length > 0 ? (
										<div className="grid grid-cols-5 gap-3">
											{selectedRequest.media.map((src, index) => (
												<button
													key={`${selectedRequest.id}-media-${index}`}
													onClick={() => setActiveImage(src)}
													className="relative aspect-square overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
													title={isVideoEvidence(src) ? "View video" : "View image"}
												>
													{isVideoEvidence(src) ? (
														<video src={src} className="w-full h-full object-cover" muted playsInline preload="metadata" />
													) : (
														<img src={src} alt={`Refund evidence ${index + 1}`} className="w-full h-full object-cover" />
													)}
												</button>
											))}
										</div>
									) : (
										<p className="text-sm text-gray-500 dark:text-gray-400">No media attached.</p>
									)}
								</div>

								{selectedRequest.rejectionReason && (
									<div>
										<p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rejection Reason</p>
										<div className="border border-red-200 dark:border-red-800 rounded-lg p-4 text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20">
											{selectedRequest.rejectionReason}
										</div>
									</div>
								)}
							</div>
						</div>

						<div className="px-8 py-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-3">
							{selectedRequest.refundType === "repair"
								&& !isIndividualRegistration
								&& String(selectedRequest.shopOwnerStatus || "").toLowerCase() === "pending"
								&& String(selectedRequest.financeStatus || "").toLowerCase() !== "approved_initial" && (
									<p className="mr-auto text-xs font-medium text-amber-700 dark:text-amber-300">
										Awaiting finance initial approval before shop owner actions are enabled.
									</p>
							)}
							<button
								onClick={handleCloseModal}
								disabled={isActionProcessing}
								className="px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								Close
							</button>
							{canShopOwnerApprove(selectedRequest) && (
								<button
									onClick={() => handleApprove(selectedRequest)}
									disabled={isActionProcessing}
									className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
								>
									Approve
								</button>
							)}
							{isIndividualRegistration
								&& canExecuteGatewayRefund(selectedRequest)
								&& !canShopOwnerApprove(selectedRequest)
								&& !canShopOwnerReject(selectedRequest) && (
								<button
									onClick={() => handleExecuteGatewayRefund(selectedRequest)}
									disabled={isActionProcessing}
									className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{getExecuteActionLabel(selectedRequest)}
								</button>
							)}
							{canShopOwnerReject(selectedRequest) && (
								<button
									onClick={() => handleReject(selectedRequest)}
									disabled={isActionProcessing}
									className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
								>
									Reject
								</button>
							)}
						</div>
					</div>
				</div>
			)}

			{executeModalOpen && executeRequest && (
				<div className="fixed inset-0 z-[1000001] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
					<div className="absolute inset-0" onClick={closeExecuteModal} />
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden">
						<div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
							<div>
								<h3 className="text-lg font-semibold text-gray-900 dark:text-white">
									{executeMode === "gateway" ? "Execute PayMongo Refund" : "Execute Repair Refund Payout"}
								</h3>
								<p className="text-sm text-gray-500 dark:text-gray-400">Request #{executeRequest.id}</p>
							</div>
							<button
								onClick={closeExecuteModal}
								disabled={isActionProcessing}
								aria-label="Close execute modal"
								title="Close"
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 disabled:opacity-50"
							>
								<XIcon className="size-5" />
							</button>
						</div>

						<div className="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
							<div className="rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 p-4 text-sm text-gray-700 dark:text-gray-200 space-y-1.5">
								<p><span className="font-semibold">Order:</span> {executeRequest.orderNumber}</p>
								<p><span className="font-semibold">Customer:</span> {executeRequest.customerName}</p>
								<p><span className="font-semibold">Amount:</span> {executeRequest.refundAmount}</p>
								{executeMode === "manual" ? (
									<>
										<p><span className="font-semibold">Flow:</span> Manual payout execution ({resolveRepairRefundPaymentType(executeRequest) === "mixed" ? "POS-paid portion" : "walk-in POS payment"})</p>
										<p><span className="font-semibold">Customer Destination:</span> {formatPayoutChannelLabel(executeRequest.preferredReturnChannel)}{executeRequest.preferredReturnAccountRef ? ` (${executeRequest.preferredReturnAccountRef})` : ""}</p>
									</>
								) : (
									<p><span className="font-semibold">Flow:</span> Original payment method (PayMongo)</p>
								)}
							</div>

							{executeMode === "manual" && (
								<div className="space-y-4">
									<div>
										<label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Execution Channel</label>
										<div className="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
											{formatPayoutChannelLabel(executeChannel)}
										</div>
										<p className="mt-1 text-xs text-gray-500">Channel is locked to the customer-selected payout destination.</p>
									</div>

									<div>
										<label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Execution Reference</label>
										<input
											type="text"
											value={executeReference}
											onChange={(event) => setExecuteReference(event.target.value)}
											placeholder="Reference / auth code"
											className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
										/>
									</div>

									<div>
										<label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Execution Amount</label>
										<div className="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
											₱{executeAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
										</div>
										<p className="mt-1 text-xs text-gray-500">Amount is fixed based on the approved refund amount.</p>
									</div>

									<div>
										<label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Proof URLs (one per line)</label>
										<textarea
											value={executeProofUrlsText}
											onChange={(event) => setExecuteProofUrlsText(event.target.value)}
											placeholder="https://..."
											rows={4}
											className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
										/>
									</div>
								</div>
							)}

							{executeError && (
								<div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
									{executeError}
								</div>
							)}
						</div>

						<div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-3">
							<button
								onClick={closeExecuteModal}
								disabled={isActionProcessing}
								className="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 disabled:opacity-50"
							>
								Cancel
							</button>
							<button
								onClick={() => { void handleSubmitExecuteModal(); }}
								disabled={isActionProcessing}
								className="px-4 py-2 rounded-lg bg-emerald-600 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
							>
								{isActionProcessing ? "Executing..." : "Execute"}
							</button>
						</div>
					</div>
				</div>
			)}

			{activeImage && (
				<div className="fixed inset-0 z-[1000000] flex items-center justify-center bg-black/80 p-6" onClick={() => setActiveImage(null)}>
					<button
						aria-label="Close image preview"
						className="absolute top-4 right-4 text-white/80 hover:text-white"
						title="Close"
					>
						<XIcon className="size-6" />
					</button>
					{isVideoEvidence(activeImage) ? (
						<video
							src={activeImage}
							className="max-h-[85vh] max-w-[90vw] rounded-xl shadow-2xl"
							controls
							autoPlay
						/>
					) : (
						<img src={activeImage} alt="Refund evidence" className="max-h-[85vh] max-w-[90vw] rounded-xl shadow-2xl" />
					)}
				</div>
			)}
		</AppLayout_shopOwner>
	);
}
