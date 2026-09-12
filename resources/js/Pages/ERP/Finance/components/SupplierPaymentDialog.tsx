import { FormEvent, useEffect, useMemo, useState } from "react";
import { useFinanceApi } from "../../../../hooks/useFinanceApi";
import type { ProcurementExpenseDetails, SupplierPaymentAttemptSummary, SupplierPaymentMethod } from "@/types/procurement";

interface SupplierPaymentDialogProps {
	open: boolean;
	mode: "finance" | "owner";
	expenseId: string;
	details: ProcurementExpenseDetails;
	amount: number | string;
	initialAttempt?: SupplierPaymentAttemptSummary | null;
	onClose: () => void;
	onChanged?: () => void | Promise<void>;
}

const formatCurrency = (value: number | string | null | undefined) => `₱${Number(value || 0).toLocaleString()}`;

const initialPaidAt = () => {
	const date = new Date();
	date.setSeconds(0, 0);
	return date.toISOString().slice(0, 16);
};

const requestKey = () => {
	if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
	return `supplier-payment-${Date.now()}`;
};

export default function SupplierPaymentDialog({
	open,
	mode,
	expenseId,
	details,
	amount,
	initialAttempt = null,
	onClose,
	onChanged,
}: SupplierPaymentDialogProps) {
	const api = useFinanceApi();
	const [attempt, setAttempt] = useState<SupplierPaymentAttemptSummary | null>(initialAttempt ?? null);
	const [paymentMethod, setPaymentMethod] = useState<SupplierPaymentMethod>("manual_bank_transfer");
	const [externalReference, setExternalReference] = useState("");
	const [paidAt, setPaidAt] = useState(initialPaidAt);
	const [financeNote, setFinanceNote] = useState("");
	const [proof, setProof] = useState<File | null>(null);
	const [decisionReason, setDecisionReason] = useState("");
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		setAttempt(initialAttempt ?? null);
		setPaymentMethod((initialAttempt?.payment_method as SupplierPaymentMethod) || "manual_bank_transfer");
	}, [initialAttempt]);

	const terminalAttempt = attempt?.status === "rejected" || attempt?.status === "cancelled";
	const attemptApiBase = mode === "owner"
		? "/api/shop-owner/finance/supplier-payment-attempts"
		: "/api/finance/supplier-payment-attempts";
	const payableAmount = attempt?.amount ?? details.payable_amount ?? amount;
	const destination = attempt?.masked_destination ?? details.payment_profile;
	const proofMedia = attempt?.proof_media ?? [];

	const title = mode === "owner" ? "Review Supplier Payment" : "Pay Supplier by Manual Transfer";
	const statusLabel = useMemo(() => {
		if (!attempt) return null;
		return attempt.status.replaceAll("_", " ").toUpperCase();
	}, [attempt]);

	if (!open) return null;

	const handleError = (message?: string) => setError(message || "The supplier payment could not be updated.");

	const startAttempt = async (event: FormEvent) => {
		event.preventDefault();
		setBusy(true);
		setError(null);
		try {
			const response = await api.post(`/api/finance/expenses/${expenseId}/supplier-payment-attempts`, {
				payment_method: paymentMethod,
				idempotency_key: requestKey(),
			});
			if (!response.ok) throw new Error(response.error);
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const submitProof = async (event: FormEvent) => {
		event.preventDefault();
		if (!attempt || !proof) {
			handleError("Payment proof is required before sending for verification.");
			return;
		}

		setBusy(true);
		setError(null);
		try {
			const form = new FormData();
			form.append("payment_method", paymentMethod);
			form.append("amount", String(payableAmount));
			form.append("external_transaction_reference", externalReference.trim());
			form.append("externally_paid_at", paidAt);
			form.append("payment_proof", proof);
			if (financeNote.trim()) form.append("finance_note", financeNote.trim());
			const response = await api.post(`/api/finance/supplier-payment-attempts/${attempt.id}/submit`, form);
			if (!response.ok) throw new Error(response.error);
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const cancelAttempt = async () => {
		if (!attempt || !decisionReason.trim()) {
			handleError("A cancellation reason is required.");
			return;
		}
		setBusy(true);
		setError(null);
		try {
			const response = await api.post(`/api/finance/supplier-payment-attempts/${attempt.id}/cancel`, { reason: decisionReason.trim() });
			if (!response.ok) throw new Error(response.error);
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const confirmAttempt = async () => {
		if (!attempt) return;
		setBusy(true);
		setError(null);
		try {
			const response = await api.post(`${attemptApiBase}/${attempt.id}/confirm`, {});
			if (!response.ok) throw new Error(response.error);
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const rejectAttempt = async () => {
		if (!attempt || !decisionReason.trim()) {
			handleError("A rejection reason is required.");
			return;
		}
		setBusy(true);
		setError(null);
		try {
			const response = await api.post(`${attemptApiBase}/${attempt.id}/reject`, { reason: decisionReason.trim() });
			if (!response.ok) throw new Error(response.error);
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const resendEmail = async () => {
		if (!attempt) return;
		setBusy(true);
		setError(null);
		try {
			const response = await api.post(`/api/finance/supplier-payment-attempts/${attempt.id}/resend-confirmation`, {});
			if (!response.ok) throw new Error(response.error);
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const startNewAttempt = () => {
		setAttempt(null);
		setError(null);
		setDecisionReason("");
	};

	const openProof = (mediaId: number) => {
		window.open(api.resolveUrl(`${attemptApiBase}/${attempt?.id}/proof/${mediaId}`), "_blank", "noopener,noreferrer");
	};

	return (
		<div className="fixed inset-0 z-[1000000] flex items-center justify-center bg-black/60 px-4 py-6 erp-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="supplier-payment-dialog-title">
			<div className="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900">
				<div className="flex items-start justify-between gap-4">
					<div>
						<p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Manual supplier payment</p>
						<h2 id="supplier-payment-dialog-title" className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{title}</h2>
					</div>
					<button type="button" aria-label="Close supplier payment dialog" onClick={onClose} className="min-h-11 min-w-11 rounded-lg text-xl text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">×</button>
				</div>

				<div className="mt-5 grid gap-2 rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
					<div className="flex justify-between gap-4"><span className="text-gray-500">Supplier</span><strong>{details.supplier_name || "—"}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">PO / Receipt</span><strong>{details.po_number || "—"} / {details.receipt_number || "—"}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">Full outstanding amount</span><strong>{formatCurrency(payableAmount)}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">Payment terms / due date</span><strong>{details.payment_terms || "—"} / {details.due_date || "—"}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">Destination</span><strong>{destination?.bank_name || destination?.destination_type || "—"} · {destination?.masked_account_number || "—"}</strong></div>
					{statusLabel && <div className="flex justify-between gap-4"><span className="text-gray-500">Payment state</span><strong className="uppercase">{statusLabel}</strong></div>}
				</div>

				{error && <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm font-medium text-rose-700" role="alert">{error}</p>}

				{mode === "finance" && (!attempt || terminalAttempt) && (
					<form className="mt-5 space-y-4" onSubmit={startAttempt}>
						{terminalAttempt && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Previous attempt {attempt?.status} {attempt?.rejection_reason || attempt?.cancellation_reason ? `: ${attempt.rejection_reason || attempt.cancellation_reason}` : ""}. Start a new attempt.</p>}
						<div>
							<label htmlFor="supplier-payment-method" className="mb-1 block text-sm font-medium">Payment method</label>
							<select id="supplier-payment-method" value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value as SupplierPaymentMethod)} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-800">
								<option value="manual_bank_transfer">Manual bank transfer</option>
								<option value="manual_e_wallet">Manual e-wallet</option>
							</select>
						</div>
						<button type="submit" disabled={busy} className="min-h-11 w-full rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{busy ? "Starting…" : "Start Payment"}</button>
					</form>
				)}

				{mode === "finance" && attempt?.status === "initiating" && (
					<form className="mt-5 space-y-4" onSubmit={submitProof}>
						<p className="rounded-lg bg-blue-50 p-3 text-sm text-blue-800">Perform the real transfer in your bank or e-wallet app, then submit the exact amount and proof below.</p>
						<div className="grid gap-4 sm:grid-cols-2">
							<div><label htmlFor="supplier-paid-amount" className="mb-1 block text-sm font-medium">Transferred amount</label><input id="supplier-paid-amount" value={String(payableAmount)} readOnly className="min-h-11 w-full rounded-lg border border-gray-300 bg-gray-100 px-3 dark:border-gray-700 dark:bg-gray-800" /></div>
							<div><label htmlFor="supplier-paid-at" className="mb-1 block text-sm font-medium">Actual paid date/time</label><input id="supplier-paid-at" type="datetime-local" value={paidAt} onChange={(event) => setPaidAt(event.target.value)} required className="min-h-11 w-full rounded-lg border border-gray-300 px-3 dark:border-gray-700 dark:bg-gray-800" /></div>
						</div>
						<div><label htmlFor="supplier-external-reference" className="mb-1 block text-sm font-medium">External transaction/reference number</label><input id="supplier-external-reference" value={externalReference} onChange={(event) => setExternalReference(event.target.value)} required maxLength={160} className="min-h-11 w-full rounded-lg border border-gray-300 px-3 dark:border-gray-700 dark:bg-gray-800" /></div>
						<div><label htmlFor="supplier-payment-proof" className="mb-1 block text-sm font-medium">Payment proof (JPG, PNG, or PDF)</label><input id="supplier-payment-proof" type="file" accept="image/jpeg,image/png,application/pdf" onChange={(event) => setProof(event.target.files?.[0] ?? null)} required className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800" /></div>
						<div><label htmlFor="supplier-finance-note" className="mb-1 block text-sm font-medium">Finance note (optional)</label><textarea id="supplier-finance-note" value={financeNote} onChange={(event) => setFinanceNote(event.target.value)} rows={3} className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800" /></div>
						<div className="flex flex-col gap-3 sm:flex-row">
							<button type="submit" disabled={busy || !proof} className="min-h-11 flex-1 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{busy ? "Submitting…" : "Submit for Shop Owner Verification"}</button>
							<button type="button" disabled={busy} onClick={cancelAttempt} className="min-h-11 rounded-lg border border-rose-300 px-4 py-2 font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">Cancel Attempt</button>
						</div>
						<label htmlFor="supplier-cancel-reason" className="block text-sm font-medium">Cancellation reason (required only when cancelling)</label>
						<textarea id="supplier-cancel-reason" value={decisionReason} onChange={(event) => setDecisionReason(event.target.value)} rows={2} className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800" />
					</form>
				)}

				{mode === "finance" && attempt?.status === "awaiting_verification" && (
					<p className="mt-5 rounded-lg bg-amber-50 p-4 text-sm font-semibold uppercase text-amber-800">Awaiting Shop Owner Verification</p>
				)}

				{mode === "owner" && attempt?.status === "awaiting_verification" && (
					<div className="mt-5 space-y-4">
						<div className="rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
							<div className="flex justify-between gap-4"><span className="text-gray-500">Transferred amount</span><strong>{formatCurrency(attempt.amount)}</strong></div>
							<div className="flex justify-between gap-4"><span className="text-gray-500">Reference</span><strong>{attempt.external_transaction_reference || "—"}</strong></div>
							<div className="flex justify-between gap-4"><span className="text-gray-500">Finance initiator</span><strong>{attempt.initiated_by?.name || "Finance staff"}</strong></div>
							{attempt.finance_note && <p className="mt-3 text-gray-600">{attempt.finance_note}</p>}
						</div>
						<div className="space-y-2">
							<p className="text-sm font-semibold">Payment proof</p>
							{proofMedia.length === 0 ? <p className="text-sm text-rose-700">No proof available.</p> : proofMedia.map((media) => <button type="button" key={media.id} onClick={() => openProof(media.id)} className="block min-h-11 text-left text-sm font-semibold text-blue-700 underline">View {media.file_name}</button>)}
						</div>
						<label htmlFor="supplier-rejection-reason" className="block text-sm font-medium">Rejection reason (required only when rejecting)</label>
						<textarea id="supplier-rejection-reason" value={decisionReason} onChange={(event) => setDecisionReason(event.target.value)} rows={3} className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-800" />
						<div className="flex flex-col gap-3 sm:flex-row">
							<button type="button" disabled={busy} onClick={confirmAttempt} className="min-h-11 flex-1 rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">{busy ? "Confirming…" : "Confirm Payment"}</button>
							<button type="button" disabled={busy} onClick={rejectAttempt} className="min-h-11 flex-1 rounded-lg bg-rose-600 px-4 py-2 font-semibold text-white hover:bg-rose-700 disabled:opacity-50">Reject Payment</button>
						</div>
					</div>
				)}

				{attempt?.status === "succeeded" && (
					<div className="mt-5 space-y-3 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">
						<p className="font-semibold uppercase">Payment Verified</p>
						<p>Expense settlement recorded. Supplier email: {(attempt.supplier_email_status || "pending").toUpperCase()}</p>
						{mode === "finance" && attempt.supplier_email_status === "failed" && <button type="button" disabled={busy} onClick={resendEmail} className="min-h-11 rounded-lg border border-emerald-700 px-4 py-2 font-semibold hover:bg-emerald-100 disabled:opacity-50">Resend Payment Confirmation</button>}
					</div>
				)}

				{mode === "finance" && terminalAttempt && <button type="button" onClick={startNewAttempt} className="mt-5 min-h-11 w-full rounded-lg border border-blue-300 px-4 py-2 font-semibold text-blue-700 hover:bg-blue-50">Start New Payment Attempt</button>}
			</div>
		</div>
	);
}
