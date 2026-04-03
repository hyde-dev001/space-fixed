import { Head, usePage } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import { hasPermission } from "../../../utils/permissions";

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
}

const isSameRefundRequest = (left: RefundRequest, right: RefundRequest): boolean => {
	return left.id === right.id && (left.refundType || "order") === (right.refundType || "order");
};

const getFinanceActionBase = (request: RefundRequest): string => {
	return request.refundType === "repair" ? "/api/finance/repair-refunds" : "/api/finance/refunds";
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
	const userRole = auth?.user?.role;

	const [requests, setRequests] = useState<RefundRequest[]>([]);
	const [currentPage, setCurrentPage] = useState(1);
	const [viewModalOpen, setViewModalOpen] = useState(false);
	const [selectedRequest, setSelectedRequest] = useState<RefundRequest | null>(null);
	const [activeImage, setActiveImage] = useState<string | null>(null);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState("Pending");
	const [isActionProcessing, setIsActionProcessing] = useState(false);
	const [isLoading, setIsLoading] = useState(false);

	const isVideoEvidence = (src: string): boolean => {
		return /\.(mp4|mov|avi|mkv|webm)(\?.*)?$/i.test(src);
	};

	useEffect(() => {
		const hasRoleAccess = userRole === "Manager" || userRole === "Finance";
		const hasPermissionAccess = hasPermission(auth, "access-refund-approval");

		if (!hasRoleAccess && !hasPermissionAccess) {
			Swal.fire({
				icon: "error",
				title: "Access Denied",
				text: "You do not have permission to access refund approvals. This page is restricted to Finance and Manager roles.",
				confirmButtonColor: "#000000",
			}).then(() => {
				window.history.back();
			});
		}
	}, []);

	const fetchRefundRequests = async () => {
		setIsLoading(true);
		try {
			const params = new URLSearchParams();
			params.append("status", statusFilter);
			if (searchQuery.trim()) {
				params.append("search", searchQuery.trim());
			}

			const [orderResponse, repairResponse] = await Promise.all([
				fetch(`/api/finance/refunds?${params.toString()}`, {
					credentials: "include",
					headers: {
						Accept: "application/json",
					},
				}),
				fetch(`/api/finance/repair-refunds?${params.toString()}`, {
					credentials: "include",
					headers: {
						Accept: "application/json",
					},
				}),
			]);

			const [orderData, repairData] = await Promise.all([orderResponse.json(), repairResponse.json()]);
			if (!orderResponse.ok) {
				throw new Error(orderData?.message || "Failed to load refund requests");
			}
			if (!repairResponse.ok) {
				throw new Error(repairData?.message || "Failed to load repair refund requests");
			}

			const normalizedOrderRefunds: RefundRequest[] = (Array.isArray(orderData?.data) ? orderData.data : []).map((item) => ({
				...item,
				refundType: "order",
			}));

			const normalizedRepairRefunds: RefundRequest[] = (Array.isArray(repairData?.data) ? repairData.data : []).map((item) => ({
				...item,
				refundType: "repair",
			}));

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
		fetchRefundRequests();
	}, [statusFilter, searchQuery]);

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

	const canFinanceApprove = (request: RefundRequest): boolean => {
		const rawStatus = String(request.rawStatus || "").toLowerCase();
		const financeStatus = String(request.financeStatus || "").toLowerCase();
		const shopOwnerStatus = String(request.shopOwnerStatus || "").toLowerCase();
		const requiresOwnerApproval = (request as any).requiresOwnerApproval !== false;

		if (request.refundType === "repair") {
			return request.status === "Pending"
				&& financeStatus === "pending"
				&& !["rejected", "failed", "succeeded", "completed", "paid"].includes(rawStatus);
		}

		return request.status === "Pending"
			&& (
				financeStatus === "pending"
				|| (requiresOwnerApproval && financeStatus === "approved_initial" && shopOwnerStatus === "approved")
			)
			&& !["rejected", "failed", "succeeded", "completed", "paid"].includes(rawStatus);
	};

	const canFinanceReject = (request: RefundRequest): boolean => {
		const financeStatus = String(request.financeStatus || "").toLowerCase();
		
		return request.status === "Pending"
			&& ["pending", "approved_initial"].includes(financeStatus);
	};

	const handleApprove = async (request: RefundRequest) => {
		if (!canFinanceApprove(request)) {
			await Swal.fire({
				title: "Approval Not Allowed",
				text: "This refund has already been approved in the finance stage or is no longer approvable.",
				icon: "info",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		setViewModalOpen(false);
		setSelectedRequest(null);
		setActiveImage(null);

		const result = await Swal.fire({
			title: (request as any).approvalStage === "finance_final" ? "Finalize Refund Approval?" : "Approve Refund?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Order:</strong> ${request.orderNumber}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Customer:</strong> ${request.customerName}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Amount:</strong> ${request.refundAmount}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Method:</strong> ${request.refundMethod}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Requested by:</strong> ${request.requestedBy}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Reason:</strong> ${request.reason}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Action:</strong> ${(request as any).approvalStage === "finance_final" ? "Finance final approval" : "Finance initial approval"}</p>
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
				const response = await fetch(`${getFinanceActionBase(request)}/${request.id}/approve`, {
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
				setSelectedRequest(updatedRefund);
				
				Swal.fire({
					title: "Approved!",
					text: data?.message || "The refund request approval was recorded.",
					icon: "success",
					confirmButtonColor: "#2563eb",
				});
				await fetchRefundRequests();
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
		setViewModalOpen(false);
		setSelectedRequest(null);
		setActiveImage(null);

		const { value: reason } = await Swal.fire({
			title: "Reject Refund",
			html: `
				<div style="text-align: left; margin-bottom: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Order:</strong> ${request.orderNumber}</p>
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
				const response = await fetch(`${getFinanceActionBase(request)}/${request.id}/reject`, {
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
		const rawStatus = String(request.rawStatus || "").toLowerCase();
		const financeStatus = String(request.financeStatus || "").toLowerCase();
		const shopOwnerStatus = String(request.shopOwnerStatus || "").toLowerCase();
		const returnStatus = String(request.returnStatus || "").toLowerCase();

		if (request.refundType === "repair") {
			return financeStatus === "approved"
				&& ["approved", "skipped"].includes(shopOwnerStatus)
				&& !["processing", "succeeded", "failed", "rejected"].includes(rawStatus);
		}

		return financeStatus === "approved"
			&& shopOwnerStatus === "approved"
			&& ["in_transit", "received"].includes(returnStatus)
			&& !["processing", "succeeded", "failed", "rejected"].includes(rawStatus);
	};

	const handleExecuteGatewayRefund = async (request: RefundRequest) => {
		setViewModalOpen(false);
		setSelectedRequest(null);
		setActiveImage(null);

		const result = await Swal.fire({
			title: "Execute Refund Payout?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Order:</strong> ${request.orderNumber}</p>
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

		setIsActionProcessing(true);
		try {
			const response = await fetch(
				request.refundType === "repair"
					? `/api/finance/repair-refunds/${request.id}/execute`
					: `/api/finance/refunds/${request.id}/execute-gateway-refund`,
				{
				method: "POST",
				credentials: "include",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
				},
				body: JSON.stringify(request.refundType === "repair" ? { execution_mode: "manual" } : {}),
			},
			);

			const data = await response.json();
			if (!response.ok) {
				throw new Error(data?.message || "Failed to execute refund payout.");
			}

			Swal.fire({
				title: "Payout Execution Started",
				text: data?.message || "Refund payout execution has started.",
				icon: "success",
				confirmButtonColor: "#2563eb",
			});

			await fetchRefundRequests();
		} catch (error) {
			Swal.fire({
				title: "Failed",
				text: error instanceof Error ? error.message : "Unable to execute refund payout.",
				icon: "error",
				confirmButtonColor: "#2563eb",
			});
		} finally {
			setIsActionProcessing(false);
		}
	};

	return (
		<>
			<Head title="Refund Approvals - Solespace ERP" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1 text-gray-900 dark:text-white">Refund Approvals</h1>
						<p className="text-gray-600 dark:text-gray-400">Review and approve refund requests from customers</p>
					</div>
					<div className="flex flex-wrap items-center justify-end gap-3">
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
							Finance Only
						</span>
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200">
							Approval Required
						</span>
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
									<th className="pb-3 font-medium">Order</th>
									<th className="pb-3 font-medium">Customer</th>
									<th className="pb-3 font-medium">Amount</th>
									<th className="pb-3 font-medium">Method</th>
									<th className="pb-3 font-medium">Requested By</th>
									<th className="pb-3 font-medium">Status</th>
									<th className="pb-3 font-medium text-right">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
								{paginatedRequests.map((request) => (
									<tr key={`${request.refundType || "order"}-${request.id}`} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
										<td className="py-4">
											<p className="font-medium text-gray-900 dark:text-white">{request.orderNumber}</p>
										</td>
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
									<p className="text-sm text-gray-500 dark:text-gray-400">{selectedRequest.orderNumber}</p>
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
							<button
								onClick={handleCloseModal}
								disabled={isActionProcessing}
								className="px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								Close
							</button>
							{canFinanceApprove(selectedRequest) && (
								<button
									onClick={() => handleApprove(selectedRequest)}
									disabled={!canFinanceApprove(selectedRequest) || isActionProcessing}
									className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{String(selectedRequest.financeStatus || "").toLowerCase() === "approved_initial" ? "Finalize Approval" : "Approve"}
								</button>
							)}
							<button
								onClick={() => handleExecuteGatewayRefund(selectedRequest)}
								disabled={!canExecuteGatewayRefund(selectedRequest) || isActionProcessing}
								className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								Execute Payout
							</button>
							{canFinanceReject(selectedRequest) && (
								<button
									onClick={() => handleReject(selectedRequest)}
									disabled={!canFinanceReject(selectedRequest) || isActionProcessing}
									className="px-5 py-2.5 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
								>
									Reject
								</button>
							)}
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
		</>
	);
}
