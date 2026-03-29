import type { ReactElement } from "react";

export interface RefundStageSnapshot {
	status: string;
	rawStatus?: string;
	shopOwnerStatus?: string;
	financeStatus?: string;
	isIndividualRegistration?: boolean;
	returnStatus?: string;
	refundExecutedAt?: string | null;
	refundedAt?: string | null;
}

export interface RefundStageBadgeState {
	label: string;
	className: string;
}

export const resolveRefundStageBadge = (request: RefundStageSnapshot): RefundStageBadgeState => {
	const rawStatus = String(request.rawStatus || "").toLowerCase();
	const shopOwnerStatus = String(request.shopOwnerStatus || "pending").toLowerCase();
	const financeStatus = String(request.financeStatus || "pending").toLowerCase();
	const isIndividualRegistration = request.isIndividualRegistration === true;
	const returnStatus = String(request.returnStatus || "awaiting_approval").toLowerCase();
	const requiresOwnerApproval = (request as any).requiresOwnerApproval !== false;

	if (["succeeded", "completed", "paid"].includes(rawStatus) || request.refundedAt) {
		return {
			label: "Refunded",
			className: "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300",
		};
	}

	if (rawStatus === "rejected" || shopOwnerStatus === "rejected" || financeStatus === "rejected") {
		return {
			label: "Refund Rejected",
			className: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300",
		};
	}

	if (financeStatus === "approved_initial" && shopOwnerStatus === "approved") {
		return {
			label: "Awaiting Finance Final",
			className: "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300",
		};
	}

	if (financeStatus === "approved_initial" && shopOwnerStatus !== "approved") {
		return {
			label: "Awaiting Shop Owner",
			className: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
		};
	}

	if (financeStatus === "pending" && requiresOwnerApproval) {
		return {
			label: isIndividualRegistration ? "Awaiting Shop Owner" : "Awaiting Finance Initial",
			className: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300",
		};
	}

	if (returnStatus === "pending_customer_shipment") {
		return {
			label: "Awaiting Return Shipment",
			className: "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
		};
	}

	if (returnStatus === "in_transit") {
		return {
			label: "Return In Transit",
			className: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
		};
	}

	if (returnStatus === "received") {
		if (financeStatus === "approved") {
			return {
				label: isIndividualRegistration ? "Ready for Refund Payout" : "Ready for Finance Payout",
				className: "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300",
			};
		}

		return {
			label: "Returned & Received",
			className: "bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300",
		};
	}

	if (shopOwnerStatus !== "approved" || financeStatus !== "approved") {
		return {
			label: "Awaiting Approval",
			className: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300",
		};
	}

	if (rawStatus === "processing") {
		return {
			label: "Refund Processing",
			className: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
		};
	}

	return {
		label: request.status,
		className:
			request.status === "Pending"
				? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300"
				: request.status === "Approved"
				? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
				: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300",
	};
};

interface RefundStageBadgeProps {
	request: RefundStageSnapshot;
}

export default function RefundStageBadge({ request }: RefundStageBadgeProps): ReactElement {
	const stageBadge = resolveRefundStageBadge(request);

	return <span className={`px-2 py-1 rounded-full text-xs font-semibold ${stageBadge.className}`}>{stageBadge.label}</span>;
}
