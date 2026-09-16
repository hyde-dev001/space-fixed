import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { useFinanceApi } from "../../../../hooks/useFinanceApi";
import { workflowFeedback } from "../../../../utils/workflowFeedback";
import type { ProcurementExpenseDetails, RevealedSupplierPaymentProfile, SupplierPaymentAttemptSummary, SupplierPaymentMethod } from "@/types/procurement";

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

const EyeIcon = ({ crossed = false }: { crossed?: boolean }) => (
	<svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
		<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
		<circle cx="12" cy="12" r="3" />
		{crossed && <path strokeLinecap="round" d="m4 4 16 16" />}
	</svg>
);

const initialPaidAt = () => {
	const date = new Date();
	date.setSeconds(0, 0);
	return date.toISOString().slice(0, 16);
};

const requestKey = () => {
	if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
	return `supplier-payment-${Date.now()}`;
};

type ProofMedia = NonNullable<SupplierPaymentAttemptSummary["proof_media"]>[number];

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
	const [selectedProof, setSelectedProof] = useState<ProofMedia | null>(null);
	const [proofUnavailable, setProofUnavailable] = useState(false);
	const [revealedPaymentProfile, setRevealedPaymentProfile] = useState<RevealedSupplierPaymentProfile | null>(null);
	const [isPaymentProfileRevealPending, setIsPaymentProfileRevealPending] = useState(false);
	const [paymentProfileRevealError, setPaymentProfileRevealError] = useState<string | null>(null);
	const initiationKey = useRef<string | null>(null);

	useEffect(() => {
		setAttempt(initialAttempt ?? null);
		setPaymentMethod((initialAttempt?.payment_method as SupplierPaymentMethod) || "manual_bank_transfer");
		setSelectedProof(null);
		setProofUnavailable(false);
		setRevealedPaymentProfile(null);
		setPaymentProfileRevealError(null);
		initiationKey.current = null;
	}, [initialAttempt]);

	useEffect(() => {
		setRevealedPaymentProfile(null);
		setPaymentProfileRevealError(null);
	}, [details.payment_profile?.id, mode, open]);

	useEffect(() => {
		if (!selectedProof) return;

		const handleKeyDown = (event: KeyboardEvent) => {
			if (event.key === "Escape") setSelectedProof(null);
		};

		window.addEventListener("keydown", handleKeyDown);
		return () => window.removeEventListener("keydown", handleKeyDown);
	}, [selectedProof]);

	const isXenditAttempt = attempt?.provider === "xendit" || attempt?.payment_method === "xendit";
	const isXenditFlow = mode === "finance" && (isXenditAttempt || (!attempt && details.xendit_configured === true));
	const terminalAttempt = ["failed", "rejected", "reversed", "cancelled"].includes(attempt?.status || "");
	const attemptApiBase = mode === "owner"
		? "/api/shop-owner/finance/supplier-payment-attempts"
		: "/api/finance/supplier-payment-attempts";
	const payableAmount = attempt?.amount ?? details.payable_amount ?? amount;
	const destination = attempt?.masked_destination ?? details.payment_profile;
	const destinationLabel = destination?.wallet_provider || destination?.bank_name || destination?.destination_type || "—";
	const isPaymentProfileRevealed = Boolean(mode === "finance" && details.payment_profile && revealedPaymentProfile && revealedPaymentProfile.id === details.payment_profile.id);
	const revealedAccount = details.payment_profile?.destination_type === "e_wallet"
		? revealedPaymentProfile?.account_identifier
		: revealedPaymentProfile?.account_number;
	const destinationAccount = isPaymentProfileRevealed
		? revealedAccount || "—"
		: destination?.masked_account_identifier || destination?.masked_account_number || "—";
	const proofMedia = attempt?.proof_media ?? [];

	const title = mode === "owner" ? "Review Supplier Payment" : isXenditFlow ? "Pay Supplier via Xendit" : "Pay Supplier by Manual Transfer";
	const statusLabel = useMemo(() => {
		if (!attempt) return null;
		return attempt.status.replaceAll("_", " ").toUpperCase();
	}, [attempt]);

	if (!open) return null;

	const handleError = (message?: string) => setError(message || "The supplier payment could not be updated.");

	const togglePaymentProfileReveal = async () => {
		if (isPaymentProfileRevealed) {
			setRevealedPaymentProfile(null);
			setPaymentProfileRevealError(null);
			return;
		}

		const supplierId = details.supplier_id;
		if (!supplierId) {
			setPaymentProfileRevealError("The supplier payment destination is unavailable.");
			return;
		}

		setIsPaymentProfileRevealPending(true);
		setPaymentProfileRevealError(null);
		try {
			const response = await api.get<RevealedSupplierPaymentProfile>(`/api/finance/suppliers/${supplierId}/payment-profile/reveal`);
			if (!response.ok || !response.data) {
				throw new Error(response.error || "The supplier payment destination could not be revealed.");
			}

			setRevealedPaymentProfile(response.data);
		} catch (caught) {
			setPaymentProfileRevealError(caught instanceof Error ? caught.message : "The supplier payment destination could not be revealed.");
		} finally {
			setIsPaymentProfileRevealPending(false);
		}
	};

	const startAttempt = async (event: FormEvent) => {
		event.preventDefault();
		if (isXenditFlow) {
			const confirmation = await workflowFeedback.confirm({
				title: "Send supplier payout via Xendit?",
				text: `${formatCurrency(payableAmount)} will be sent from this shop's connected Xendit account. The expense is recorded as paid only after Xendit's webhook confirms success.`,
				confirmButtonText: "Pay Supplier",
				cancelButtonText: "Review details",
			});
			if (!confirmation.isConfirmed) return;
		}
		setBusy(true);
		setError(null);
		try {
			const idempotencyKey = initiationKey.current ?? requestKey();
			initiationKey.current = idempotencyKey;
			const response = await api.post(`/api/finance/expenses/${expenseId}/supplier-payment-attempts`, {
				payment_method: isXenditFlow ? "xendit" : paymentMethod,
				idempotency_key: idempotencyKey,
			});
			if (!response.ok) throw new Error(response.error);
			initiationKey.current = null;
			setAttempt(response.data as SupplierPaymentAttemptSummary);
			await onChanged?.();
			if (isXenditFlow) {
				await workflowFeedback.success({
					title: "Supplier payout submitted",
					text: "Xendit is processing the payout. The expense will be marked paid after webhook confirmation.",
				});
			}
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

		const confirmation = await workflowFeedback.confirm({
			title: "Submit payment for Shop Owner verification?",
			text: "The Shop Owner will review the payment details and proof before the payment is finalized.",
			confirmButtonText: "Submit for Verification",
			cancelButtonText: "Cancel",
		});
		if (!confirmation.isConfirmed) return;

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
			await workflowFeedback.success({
				title: "Payment submitted",
				text: "Waiting for Shop Owner verification.",
			});
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
			await workflowFeedback.success({
				title: "Payment confirmed",
				text: "Finance can now send the supplier payment receipt.",
			});
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
			const response = await api.post(`/api/finance/supplier-payment-attempts/${attempt.id}/send-receipt`, {});
			if (!response.ok) throw new Error(response.error);
			const updatedAttempt = response.data as SupplierPaymentAttemptSummary;
			setAttempt(updatedAttempt);
			await onChanged?.();
			if (updatedAttempt.supplier_email_status === "dispatched") {
				await workflowFeedback.success({
					title: "Receipt sent",
					text: "The supplier payment receipt was dispatched by the mail server.",
				});
			}
		} catch (caught) {
			handleError(caught instanceof Error ? caught.message : undefined);
		} finally {
			setBusy(false);
		}
	};

	const startNewAttempt = () => {
		setAttempt(null);
		setError(null);
		setExternalReference("");
		setPaidAt(initialPaidAt());
		setFinanceNote("");
		setProof(null);
		setDecisionReason("");
		setRevealedPaymentProfile(null);
		setPaymentProfileRevealError(null);
		initiationKey.current = null;
	};

	const openProof = (media: ProofMedia) => {
		setProofUnavailable(false);
		setSelectedProof(media);
	};
	const selectedProofUrl = selectedProof && attempt
		? api.resolveUrl(`${attemptApiBase}/${attempt.id}/proof/${selectedProof.id}`)
		: "";

	return (
		<div className="fixed inset-0 z-[1000000] flex min-h-full items-start justify-center overflow-y-auto bg-black/60 px-4 py-6 erp-modal-backdrop sm:items-center sm:py-8" role="dialog" aria-modal="true" aria-labelledby="supplier-payment-dialog-title">
			<div className="my-auto flex w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
				<div className="flex shrink-0 items-start justify-between gap-4 px-6 pb-2 pt-4">
					<div>
						<p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{mode === "owner" ? "Supplier payment review" : isXenditFlow ? "Xendit supplier payout" : "Manual supplier payment"}</p>
						<h2 id="supplier-payment-dialog-title" className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{title}</h2>
					</div>
					<button type="button" aria-label="Close supplier payment dialog" onClick={onClose} className="min-h-11 min-w-11 rounded-lg text-xl text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">×</button>
				</div>

				<div className="px-6 pb-4">
				<div className="mt-3 grid gap-1.5 rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
					<div className="flex justify-between gap-4"><span className="text-gray-500">Supplier</span><strong>{details.supplier_name || "—"}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">PO / Receipt</span><strong>{details.po_number || "—"} / {details.receipt_number || "—"}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">Full outstanding amount</span><strong>{formatCurrency(payableAmount)}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">Payment terms / due date</span><strong>{details.payment_terms || "—"} / {details.due_date || "—"}</strong></div>
					<div className="flex justify-between gap-4"><span className="text-gray-500">Destination</span><span className="flex items-center gap-2 text-right"><strong>{destinationLabel} · <span>{destinationAccount}</span></strong>{mode === "finance" && details.payment_profile && <span className="flex flex-col items-end"><button type="button" disabled={isPaymentProfileRevealPending} aria-label={isPaymentProfileRevealPending ? "Loading account details" : isPaymentProfileRevealed ? "Hide full account details" : "Show full account details"} title={isPaymentProfileRevealPending ? "Loading account details" : isPaymentProfileRevealed ? "Hide full account details" : "Show full account details"} aria-pressed={isPaymentProfileRevealed} onClick={() => void togglePaymentProfileReveal()} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-300 text-gray-700 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"><EyeIcon crossed={isPaymentProfileRevealed} /></button>{paymentProfileRevealError && <span role="alert" className="mt-1 block text-xs text-rose-700 dark:text-rose-300">{paymentProfileRevealError}</span>}</span>}</span></div>
					{statusLabel && <div className="flex justify-between gap-4"><span className="text-gray-500">Payment state</span><strong className="uppercase">{statusLabel}</strong></div>}
				</div>

				{error && <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm font-medium text-rose-700" role="alert">{error}</p>}

				{mode === "finance" && (!attempt || terminalAttempt) && (
					<form className="mt-4 space-y-3" onSubmit={startAttempt}>
						{terminalAttempt && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Previous attempt {attempt?.status} {attempt?.rejection_reason || attempt?.cancellation_reason ? `: ${attempt.rejection_reason || attempt.cancellation_reason}` : ""}. Start a new attempt.</p>}
						{isXenditFlow ? (
							<div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
								<p className="font-semibold">Pay the full outstanding amount through this shop&apos;s Xendit account.</p>
								<p className="mt-1">Amount: <strong>{formatCurrency(payableAmount)}</strong>. Finance cannot change the amount or choose another shop account.</p>
							</div>
						) : (
							<div>
								<label htmlFor="supplier-payment-method" className="mb-1 block text-sm font-medium">Payment method</label>
								<select id="supplier-payment-method" value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value as SupplierPaymentMethod)} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-800">
									<option value="manual_bank_transfer">Manual bank transfer</option>
									<option value="manual_e_wallet">Manual e-wallet</option>
								</select>
							</div>
						)}
						<button type="submit" disabled={busy} className="min-h-11 w-full rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{busy ? "Starting…" : isXenditFlow ? "Pay Supplier via Xendit" : "Start Payment"}</button>
					</form>
				)}

				{mode === "finance" && attempt?.status === "initiating" && !isXenditAttempt && (
					<form className="mt-4 space-y-3" onSubmit={submitProof}>
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
			<p className="mt-4 rounded-lg bg-amber-50 p-3 text-sm font-semibold uppercase text-amber-800">Awaiting Shop Owner Verification</p>
				)}

				{isXenditAttempt && attempt && ["processing", "pending_compliance"].includes(attempt.status) && (
					<div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900" role="status" aria-live="polite">
						<p className="font-semibold">{attempt.status === "pending_compliance" ? "Xendit payout pending compliance review" : "Xendit payout processing"}</p>
						<p className="mt-1">The expense will be marked paid only after Xendit confirms the payout. Do not submit another payout.</p>
					</div>
				)}

		{!isXenditAttempt && ["awaiting_verification", "succeeded"].includes(attempt?.status || "") && (
			<div className="mt-4 space-y-2">
				<p className="text-sm font-semibold">Payment proof</p>
				{proofMedia.length === 0 ? <p className="text-sm text-rose-700">No proof available.</p> : proofMedia.map((media) => {
					const isImage = media.mime_type.startsWith("image/");

					return (
						<button type="button" key={media.id} onClick={() => openProof(media)} className="block min-h-11 w-full rounded-lg border border-gray-200 p-2 text-left text-sm font-semibold text-blue-700 hover:bg-blue-50 dark:border-gray-700 dark:hover:bg-gray-800">
							{isImage ? (
								<img
									src={api.resolveUrl(`${attemptApiBase}/${attempt?.id}/proof/${media.id}`)}
									alt={`Payment proof ${media.file_name}`}
									className="mb-2 h-24 w-full object-contain"
									onError={() => setProofUnavailable(true)}
								/>
							) : null}
							<span>View {media.file_name}</span>
						</button>
					);
				})}
			</div>
		)}

		{mode === "owner" && attempt?.status === "awaiting_verification" && (
			<div className="mt-4 space-y-3">
						<div className="rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
							<div className="flex justify-between gap-4"><span className="text-gray-500">Transferred amount</span><strong>{formatCurrency(attempt.amount)}</strong></div>
							<div className="flex justify-between gap-4"><span className="text-gray-500">Reference</span><strong>{attempt.external_transaction_reference || "—"}</strong></div>
							<div className="flex justify-between gap-4"><span className="text-gray-500">Finance initiator</span><strong>{attempt.initiated_by?.name || "Finance staff"}</strong></div>
							{attempt.finance_note && <p className="mt-3 text-gray-600">{attempt.finance_note}</p>}
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
					<div className="mt-4 space-y-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">
						<p className="font-semibold uppercase">Payment Verified</p>
						<p>Expense settlement recorded. Payment receipt email status: {(attempt.supplier_email_status || "pending").replaceAll("_", " ").toUpperCase()}</p>
						{attempt.supplier_email_status === "dispatched" && <p className="text-xs">Dispatched means the configured mail transport accepted the message; it does not confirm inbox delivery.</p>}
						{mode === "finance" && ["ready_to_send", "failed"].includes(attempt.supplier_email_status || "") && (
							<button type="button" disabled={busy} onClick={resendEmail} className="min-h-11 rounded-lg border border-emerald-700 px-4 py-2 font-semibold hover:bg-emerald-100 disabled:opacity-50">
								{attempt.supplier_email_status === "failed" ? "Retry Payment Receipt" : "Send Payment Receipt"}
							</button>
						)}
					</div>
				)}

				{mode === "finance" && terminalAttempt && <button type="button" onClick={startNewAttempt} className="mt-4 min-h-11 w-full rounded-lg border border-blue-300 px-4 py-2 font-semibold text-blue-700 hover:bg-blue-50">Start New Payment Attempt</button>}
				</div>
				<div className="flex shrink-0 justify-end border-t border-gray-200 px-6 py-3 dark:border-gray-700">
					<button type="button" onClick={onClose} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Close</button>
				</div>
			</div>

			{selectedProof && (
				<div className="fixed inset-0 z-[1000001] flex items-center justify-center bg-black/80 p-4 erp-modal-backdrop" role="dialog" aria-modal="true" aria-label="Payment proof preview" onClick={() => setSelectedProof(null)}>
					<div className="relative max-h-[85vh] w-full max-w-4xl rounded-xl bg-gray-950 p-4" onClick={(event) => event.stopPropagation()}>
						<button type="button" aria-label="Close payment proof preview" onClick={() => setSelectedProof(null)} className="absolute right-3 top-3 z-10 min-h-11 min-w-11 rounded-lg bg-white/90 text-xl text-gray-900">Ã—</button>
						{proofUnavailable ? (
							<p className="flex min-h-48 items-center justify-center text-sm font-semibold text-white">Proof file unavailable.</p>
						) : selectedProof.mime_type.startsWith("image/") ? (
							<img src={selectedProofUrl} alt={`Payment proof ${selectedProof.file_name}`} className="max-h-[75vh] w-full object-contain" onError={() => setProofUnavailable(true)} />
						) : selectedProof.mime_type === "application/pdf" ? (
							<iframe title={`Payment proof ${selectedProof.file_name}`} src={selectedProofUrl} className="h-[75vh] w-full rounded-lg bg-white" onError={() => setProofUnavailable(true)} />
						) : (
							<p className="flex min-h-48 items-center justify-center text-sm font-semibold text-white">Proof file unavailable.</p>
						)}
					</div>
				</div>
			)}
		</div>
	);
}
