import { useState } from "react";
import { useFinanceApi } from "../../../../hooks/useFinanceApi";
import type { ProcurementExpenseDetails, SupplierAdjustment, SupplierPaymentMethod } from "@/types/procurement";

interface ProcurementExpensePanelProps {
	details: ProcurementExpenseDetails;
	expenseId: string;
	expenseStatus: string;
	amount: number | string;
	isReviewPending?: boolean;
	onReviewAndRelease?: () => void;
	isPaymentProfileActionPending?: boolean;
	onVerifyPaymentProfile?: () => void;
	onDisablePaymentProfile?: () => void;
	canPaySupplier?: boolean;
	onPaySupplier?: () => void;
	ownerMode?: boolean;
	onReviewSupplierPayment?: () => void;
	onRefundChanged?: () => void | Promise<void>;
}

const formatCurrency = (value: number | string | null | undefined) => `₱${Number(value || 0).toLocaleString()}`;

const formatDate = (value: string | null | undefined) => {
	if (!value) return "—";

	const date = new Date(value);
	return Number.isNaN(date.getTime())
		? value
		: date.toLocaleDateString("en-US", { month: "short", day: "2-digit", year: "numeric" });
};

const DetailRow = ({ label, value }: { label: string; value: string | number }) => (
	<div className="flex justify-between gap-4 text-sm text-gray-700 dark:text-gray-300">
		<span className="text-gray-500 dark:text-gray-400">{label}</span>
		<span className="font-semibold text-right">{value}</span>
	</div>
);

export default function ProcurementExpensePanel({
	details,
	expenseId,
	expenseStatus,
	amount,
	isReviewPending = false,
	onReviewAndRelease,
	isPaymentProfileActionPending = false,
	onVerifyPaymentProfile,
	onDisablePaymentProfile,
	canPaySupplier = false,
	onPaySupplier,
	ownerMode = false,
	onReviewSupplierPayment,
	onRefundChanged,
}: ProcurementExpensePanelProps) {
	const api = useFinanceApi();
	const [selectedRefund, setSelectedRefund] = useState<number | null>(null);
	const [refundAmount, setRefundAmount] = useState("");
	const [refundMethod, setRefundMethod] = useState<SupplierPaymentMethod>("manual_bank_transfer");
	const [refundReference, setRefundReference] = useState("");
	const [refundReceivedAt, setRefundReceivedAt] = useState(() => new Date().toISOString().slice(0, 16));
	const [refundNotes, setRefundNotes] = useState("");
	const [refundProof, setRefundProof] = useState<File | null>(null);
	const [refundBusy, setRefundBusy] = useState(false);
	const [refundError, setRefundError] = useState<string | null>(null);
	const isSubmitted = expenseStatus === "submitted";
	const paymentStatus = details.payment_status || "unpaid";
	const isAwaitingVerification = paymentStatus === "awaiting_verification";
	const isReadyForPayment = expenseStatus === "posted"
		&& paymentStatus !== "paid"
		&& !["initiating", "awaiting_verification"].includes(paymentStatus);
	const refundAdjustments = (details.adjustments ?? []).filter((adjustment) => adjustment.resolution === "refund" && ["awaiting_verification", "partially_refunded"].includes(adjustment.status));

	const confirmRefund = async (adjustment: SupplierAdjustment) => {
		if (!refundAmount.trim() || !refundReference.trim() || !refundProof) {
			setRefundError("Refund amount, reference, and Finance confirmation proof are required.");
			return;
		}

		setRefundBusy(true);
		setRefundError(null);
		try {
			const form = new FormData();
			form.append("amount", refundAmount.trim());
			form.append("payment_method", refundMethod);
			form.append("external_transaction_reference", refundReference.trim());
			form.append("received_at", refundReceivedAt);
			form.append("idempotency_key", typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `supplier-refund-${Date.now()}`);
			if (refundNotes.trim()) form.append("notes", refundNotes.trim());
			form.append("finance_confirmation_proof", refundProof, refundProof.name);
			const response = await api.post(`/api/finance/expenses/${expenseId}/supplier-adjustments/${adjustment.id}/refund-confirmations`, form);
			if (!response.ok) throw new Error(response.error || "The supplier refund could not be confirmed.");
			setSelectedRefund(null);
			setRefundProof(null);
			setRefundAmount("");
			setRefundReference("");
			setRefundNotes("");
			await onRefundChanged?.();
		} catch (error) {
			setRefundError(error instanceof Error ? error.message : "The supplier refund could not be confirmed.");
		} finally {
			setRefundBusy(false);
		}
	};

	return (
		<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 space-y-2">
			<p className="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">Procurement Payable</p>
			<DetailRow label="Supplier" value={details.supplier_name || "—"} />
			<DetailRow label="PO Number" value={details.po_number || "—"} />
			<DetailRow label="Receipt Number" value={details.receipt_number || (details.receipt_id ? `RCV-${details.receipt_id}` : "—")} />
			<DetailRow label="Ordered" value={details.ordered_quantity ?? details.quantity ?? "—"} />
			<DetailRow label="Received" value={details.received_quantity ?? "—"} />
			<DetailRow label="Accepted" value={details.accepted_quantity ?? "—"} />
			<DetailRow label="Defective" value={details.defective_quantity ?? "—"} />
			<DetailRow label="Unit Cost" value={details.unit_cost == null ? "—" : formatCurrency(details.unit_cost)} />
			<DetailRow label="Payable Amount" value={formatCurrency(details.payable_amount ?? amount)} />
			<DetailRow label="Payment Terms" value={details.payment_terms || "—"} />
			<DetailRow label="Receipt Date" value={formatDate(details.receipt_date ?? details.received_at)} />
			<DetailRow label="Due Date" value={formatDate(details.due_date)} />
			<DetailRow label="Expense Status" value={details.expense_status || expenseStatus} />
			<DetailRow label="Payment Status" value={details.payment_status || "unpaid"} />
			<DetailRow label="Payment Timing" value={details.payment_timing || "Not Due"} />
			{paymentStatus === "initiating" && <p className="rounded-lg bg-blue-50 p-3 text-sm font-semibold uppercase text-blue-800">PAYMENT INITIATED</p>}
			{isAwaitingVerification && (
				<div className="rounded-lg bg-amber-50 p-3 text-sm font-semibold uppercase text-amber-800">
					<p>AWAITING SHOP OWNER VERIFICATION</p>
					{ownerMode && onReviewSupplierPayment && <button type="button" onClick={onReviewSupplierPayment} className="mt-3 min-h-11 w-full rounded-lg bg-amber-700 px-3 py-2 text-white hover:bg-amber-800">Review Supplier Payment</button>}
				</div>
			)}
			{paymentStatus === "rejected" && <p className="rounded-lg bg-rose-50 p-3 text-sm font-semibold uppercase text-rose-800">PAYMENT REJECTED · NEW ATTEMPT AVAILABLE</p>}
			{paymentStatus === "cancelled" && <p className="rounded-lg bg-gray-100 p-3 text-sm font-semibold uppercase text-gray-700">PAYMENT CANCELLED · NEW ATTEMPT AVAILABLE</p>}
			{paymentStatus === "paid" && <p className="rounded-lg bg-emerald-50 p-3 text-sm font-semibold uppercase text-emerald-800">PAID · PAYMENT VERIFIED</p>}

			{refundAdjustments.length > 0 && (
				<div className="space-y-3 border-t border-gray-200 pt-3 dark:border-gray-700">
					<p className="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">Supplier refunds</p>
					{refundAdjustments.map((adjustment) => <div key={adjustment.id} className="rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-sm dark:border-amber-900/60 dark:bg-amber-950/20">
						<div className="flex justify-between gap-3"><span>Expected</span><strong>{formatCurrency(adjustment.expected_refund_amount)}</strong></div>
						<div className="flex justify-between gap-3"><span>Confirmed</span><strong>{formatCurrency(adjustment.refunded_amount)}</strong></div>
						<p className="mt-1 text-xs uppercase text-amber-800 dark:text-amber-300">{adjustment.status === "awaiting_verification" ? "Supplier proof received · Finance confirmation required" : "Partially refunded"}</p>
						{!ownerMode && <>
							{selectedRefund !== adjustment.id ? <button type="button" onClick={() => { setSelectedRefund(adjustment.id); setRefundAmount(""); setRefundReference(""); setRefundError(null); }} className="mt-3 min-h-10 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">Confirm incoming refund</button> : <div className="mt-3 space-y-2">
								{refundError && <p role="alert" className="rounded bg-rose-50 p-2 text-xs text-rose-700">{refundError}</p>}
								<input aria-label="Confirmed refund amount" value={refundAmount} onChange={(event) => setRefundAmount(event.target.value)} placeholder="Actual amount received" className="min-h-10 w-full rounded border border-gray-300 bg-white px-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
								<select aria-label="Refund payment method" value={refundMethod} onChange={(event) => setRefundMethod(event.target.value as SupplierPaymentMethod)} className="min-h-10 w-full rounded border border-gray-300 bg-white px-2 text-sm dark:border-gray-600 dark:bg-gray-800"><option value="manual_bank_transfer">Manual bank transfer</option><option value="manual_e_wallet">Manual e-wallet</option></select>
								<input aria-label="Confirmed refund reference" value={refundReference} onChange={(event) => setRefundReference(event.target.value)} placeholder="Bank/e-wallet reference" className="min-h-10 w-full rounded border border-gray-300 bg-white px-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
								<input aria-label="Confirmed refund date" type="datetime-local" value={refundReceivedAt} onChange={(event) => setRefundReceivedAt(event.target.value)} className="min-h-10 w-full rounded border border-gray-300 bg-white px-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
								<input aria-label="Finance refund proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setRefundProof(event.target.files?.[0] ?? null)} className="block w-full text-xs" />
								<textarea aria-label="Finance refund notes" rows={2} value={refundNotes} onChange={(event) => setRefundNotes(event.target.value)} placeholder="Finance notes (optional)" className="w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
								<div className="flex gap-2"><button type="button" disabled={refundBusy} onClick={() => void confirmRefund(adjustment)} className="min-h-10 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{refundBusy ? "Confirming..." : "Confirm refund"}</button><button type="button" onClick={() => setSelectedRefund(null)} className="min-h-10 rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700">Cancel</button></div>
							</div>}
						</>}
					</div>)}
				</div>
			)}

			{details.payment_profile && (
				<div className="pt-2 space-y-2 border-t border-gray-200 dark:border-gray-700">
					<p className="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">Supplier Payment Profile</p>
					<DetailRow label="Bank" value={details.payment_profile.bank_name || "—"} />
					<DetailRow label="Account" value={details.payment_profile.masked_account_number || "—"} />
					<DetailRow label="Profile Status" value={details.payment_profile.status} />
					{details.payment_profile.status === "unverified" && onVerifyPaymentProfile && (
						<button
							type="button"
							disabled={isPaymentProfileActionPending}
							className="w-full px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
							onClick={onVerifyPaymentProfile}
						>
							Verify Payment Profile
						</button>
					)}
					{details.payment_profile.status === "verified" && onDisablePaymentProfile && (
						<button
							type="button"
							disabled={isPaymentProfileActionPending}
							className="w-full px-3 py-2 rounded-lg bg-amber-600 text-white text-sm font-semibold hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed"
							onClick={onDisablePaymentProfile}
						>
							Disable Payment Profile
						</button>
					)}
				</div>
			)}

			{isSubmitted && onReviewAndRelease && (
				<button
					type="button"
					disabled={isReviewPending}
					className="w-full px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
					onClick={onReviewAndRelease}
				>
					{isReviewPending ? "Releasing…" : "Review & Release"}
				</button>
			)}

			{isReadyForPayment && !ownerMode && (
				<div className="pt-2 space-y-2 border-t border-gray-200 dark:border-gray-700">
					<p className="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">READY FOR PAYMENT</p>
					<button
						type="button"
						disabled={!canPaySupplier}
						className="w-full px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
						onClick={onPaySupplier}
					>
						Pay Supplier
					</button>
				</div>
			)}

			{!ownerMode && paymentStatus === "paid" && details.payment_attempt?.supplier_email_status === "failed" && onPaySupplier && (
				<button type="button" onClick={onPaySupplier} className="min-h-11 w-full rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">SUPPLIER EMAIL FAILED · Resend Email</button>
			)}
		</div>
	);
}
