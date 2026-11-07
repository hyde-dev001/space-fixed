import { useEffect, useState } from "react";
import Swal from "sweetalert2";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type {
	PurchaseOrder,
	PostPaymentIssueItem,
	SupplierAdjustment,
	SupplierAdjustmentReasonCategory,
} from "@/types/procurement";

type Props = {
	order: PurchaseOrder;
	canReport?: boolean;
	canManage?: boolean;
	onChanged?: () => Promise<void>;
};

type IssueForm = {
	reported_quantity: string;
	reason_category: SupplierAdjustmentReasonCategory | "";
	inventory_notes: string;
	defect_evidence: File[];
};

type RefundForm = {
	expected_refund_amount: string;
	supplier_reported_refund_amount: string;
	supplier_reported_refund_reference: string;
	supplier_reported_refund_date: string;
	procurement_notes: string;
	supplier_refund_proof: File | null;
};

const categories: Array<{ value: SupplierAdjustmentReasonCategory; label: string }> = [
	{ value: "manufacturing_defect", label: "Manufacturing defect" },
	{ value: "damaged", label: "Damaged" },
	{ value: "wrong_item", label: "Wrong item" },
	{ value: "incorrect_size_or_variant", label: "Incorrect size or variant" },
	{ value: "other", label: "Other" },
];

const emptyForm: IssueForm = {
	reported_quantity: "1",
	reason_category: "",
	inventory_notes: "",
	defect_evidence: [],
};

const emptyRefundForm: RefundForm = {
	expected_refund_amount: "",
	supplier_reported_refund_amount: "",
	supplier_reported_refund_reference: "",
	supplier_reported_refund_date: "",
	procurement_notes: "",
	supplier_refund_proof: null,
};

const formatStage = (stage: string) => stage === "post_payment_issue" ? "Post-payment issue" : "Receiving defect";
const formatCategory = (category: string) => category.split("_").map((part) => part[0].toUpperCase() + part.slice(1)).join(" ");

export default function SupplierAdjustmentsPanel({ order, canReport = false, canManage = false, onChanged }: Props) {
	const [adjustments, setAdjustments] = useState<SupplierAdjustment[]>([]);
	const [selectedItem, setSelectedItem] = useState<PostPaymentIssueItem | null>(null);
	const [selectedRefund, setSelectedRefund] = useState<number | null>(null);
	const [form, setForm] = useState<IssueForm>(emptyForm);
	const [refundForm, setRefundForm] = useState<RefundForm>(emptyRefundForm);
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [selectedEvidence, setSelectedEvidence] = useState<{ url: string; name: string } | null>(null);

	const loadAdjustments = async () => {
		setLoading(true);
		try {
			const all = await purchaseOrderApi.getSupplierAdjustments({ purchase_order_id: order.id });
			setAdjustments(all);
		} catch (requestError) {
			console.error("Failed to load supplier adjustments", requestError);
			setError("Supplier issue history could not be loaded.");
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		void loadAdjustments();
	}, [order.id]);

	const reportableItems = order.post_payment_issue_items ?? [];

	const submit = async () => {
		if (!selectedItem) return;
		const quantity = Number(form.reported_quantity);
		if (!Number.isInteger(quantity) || quantity < 1 || quantity > selectedItem.remaining_quantity) {
			setError("Enter a quantity within the remaining accepted units.");
			return;
		}
		if (!form.reason_category || !form.inventory_notes.trim() || form.defect_evidence.length === 0) {
			setError("Choose a category, add notes, and attach at least one image.");
			return;
		}
		const confirmation = await Swal.fire({
			title: "Submit this supplier issue?",
			text: `${quantity} unit${quantity === 1 ? "" : "s"} of ${selectedItem.product_name} will be reported for supplier resolution.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Submit issue",
			cancelButtonText: "Review details",
		});
		if (!confirmation.isConfirmed) return;

		setSaving(true);
		setError(null);
		try {
			await purchaseOrderApi.reportPostPaymentIssue(order.id, selectedItem.receipt_id, selectedItem.receipt_item_id, {
				idempotency_key: crypto.randomUUID(),
				reported_quantity: quantity,
				reason_category: form.reason_category,
				inventory_notes: form.inventory_notes.trim(),
				defect_evidence: form.defect_evidence,
			});
			setSelectedItem(null);
			setForm(emptyForm);
			await loadAdjustments();
			await onChanged?.();
			await Swal.fire("Supplier issue reported", "Procurement can now coordinate the supplier resolution.", "success");
		} catch (requestError: any) {
			const message = requestError?.response?.data?.message ?? "The supplier issue could not be reported.";
			setError(message);
			await Swal.fire("Issue not reported", message, "error");
		} finally {
			setSaving(false);
		}
	};

	const submitRefundProof = async (adjustment: SupplierAdjustment) => {
		if (!refundForm.expected_refund_amount.trim() || !refundForm.supplier_refund_proof) {
			setError("Expected refund amount and supplier proof are required.");
			return;
		}
		const confirmation = await Swal.fire({
			title: "Submit supplier refund proof?",
			text: `Finance will be asked to verify the expected ₱${Number(refundForm.expected_refund_amount).toLocaleString()} refund.`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Submit proof",
			cancelButtonText: "Review details",
		});
		if (!confirmation.isConfirmed) return;

		setSaving(true);
		setError(null);
		try {
			await purchaseOrderApi.submitSupplierRefundProof(adjustment.id, {
				expected_refund_amount: refundForm.expected_refund_amount.trim(),
				supplier_reported_refund_amount: refundForm.supplier_reported_refund_amount.trim() || undefined,
				supplier_reported_refund_reference: refundForm.supplier_reported_refund_reference.trim() || undefined,
				supplier_reported_refund_date: refundForm.supplier_reported_refund_date || undefined,
				procurement_notes: refundForm.procurement_notes.trim() || undefined,
				supplier_refund_proof: refundForm.supplier_refund_proof,
			});
			setSelectedRefund(null);
			setRefundForm(emptyRefundForm);
			await loadAdjustments();
			await onChanged?.();
			await Swal.fire("Supplier proof saved", "Finance can now verify the supplier refund evidence.", "success");
		} catch (requestError: any) {
			const message = requestError?.response?.data?.message ?? "The supplier refund proof could not be submitted.";
			setError(message);
			await Swal.fire("Proof not saved", message, "error");
		} finally {
			setSaving(false);
		}
	};

	const runWorkflowAction = async (label: string, action: () => Promise<SupplierAdjustment>, confirmed = false) => {
		if (!confirmed) {
			const confirmation = await Swal.fire({ title: `${label}?`, icon: "question", showCancelButton: true, confirmButtonText: label });
			if (!confirmation.isConfirmed) return;
		}
		setSaving(true);
		setError(null);
		try {
			const updated = await action();
			await loadAdjustments();
			await onChanged?.();
			await Swal.fire(`${label} completed`, updated.next_action ? `Next: ${updated.next_action}.` : "The supplier adjustment was refreshed.", "success");
		} catch (requestError: any) {
			const message = requestError?.response?.data?.message ?? "The supplier adjustment could not be updated.";
			setError(message);
			await Swal.fire("Action not completed", message, "error");
		} finally {
			setSaving(false);
		}
	};

	const declineReplacement = async (adjustment: SupplierAdjustment) => {
		const result = await Swal.fire({
			title: "Supplier declined replacement",
			input: "textarea",
			inputLabel: "Decline reason",
			showCancelButton: true,
			inputValidator: (value) => value?.trim() ? undefined : "A decline reason is required.",
		});
		if (!result.isConfirmed) return;
		await runWorkflowAction("Supplier decline recorded", () => purchaseOrderApi.updateSupplierReplacement(adjustment.id, "declined", { decline_reason: result.value.trim() }), true);
	};

	const declineRefund = async (adjustment: SupplierAdjustment) => {
		const result = await Swal.fire({
			title: "Supplier declined refund",
			input: "textarea",
			inputLabel: "Decline reason",
			showCancelButton: true,
			inputValidator: (value) => value?.trim() ? undefined : "A decline reason is required.",
		});
		if (!result.isConfirmed) return;
		await runWorkflowAction("Supplier refund decline recorded", () => purchaseOrderApi.declineSupplierRefund(adjustment.id, { decline_reason: result.value.trim() }), true);
	};

	return (
		<section className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
			<div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
				<div>
					<h3 className="text-sm font-semibold text-gray-900 dark:text-white">Supplier adjustments</h3>
					<p className="text-xs text-gray-500">Receiving defects and later issues remain attached to the original receipt item.</p>
				</div>
				{canReport && reportableItems.length > 0 && (
					<button type="button" onClick={() => { setSelectedItem(reportableItems[0]); setError(null); }} className="rounded-lg border border-amber-300 px-3 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30">
						Report post-payment issue
					</button>
				)}
			</div>

			{error && <p role="alert" className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-300">{error}</p>}

			{selectedItem && <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-900/60 dark:bg-amber-950/20">
				<div className="mb-3 flex items-start justify-between gap-3">
					<div>
						<h4 className="text-sm font-semibold text-gray-900 dark:text-white">Report a later supplier issue</h4>
						<p className="text-xs text-gray-600 dark:text-gray-400">{selectedItem.receipt_reference} · {selectedItem.product_name} · {selectedItem.remaining_quantity} paid accepted unit(s) remaining.</p>
					</div>
					<button type="button" onClick={() => setSelectedItem(null)} className="text-sm text-gray-500 hover:underline">Cancel</button>
				</div>
				<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
					<label className="text-xs font-medium text-gray-700 dark:text-gray-300 md:col-span-2">Paid receipt item
						<select aria-label="Paid receipt item" value={selectedItem.receipt_item_id} onChange={(event) => { setSelectedItem(reportableItems.find((item) => item.receipt_item_id === Number(event.target.value)) ?? null); setForm((current) => ({ ...current, reported_quantity: "1" })); }} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800">
							{reportableItems.map((item) => <option key={item.receipt_item_id} value={item.receipt_item_id}>{item.receipt_reference} · {item.product_name} · {item.remaining_quantity} remaining</option>)}
						</select>
					</label>
					<label className="text-xs font-medium text-gray-700 dark:text-gray-300">Issue quantity
						<input aria-label="Issue quantity" type="number" min="1" max={selectedItem.remaining_quantity} value={form.reported_quantity} onChange={(event) => setForm((current) => ({ ...current, reported_quantity: event.target.value }))} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
					</label>
					<label className="text-xs font-medium text-gray-700 dark:text-gray-300">Issue category
						<select aria-label="Issue category" value={form.reason_category} onChange={(event) => setForm((current) => ({ ...current, reason_category: event.target.value as SupplierAdjustmentReasonCategory }))} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800">
							<option value="">Choose category</option>
							{categories.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}
						</select>
					</label>
					<label className="text-xs font-medium text-gray-700 dark:text-gray-300 md:col-span-2">Issue notes
						<textarea aria-label="Issue notes" rows={3} value={form.inventory_notes} onChange={(event) => setForm((current) => ({ ...current, inventory_notes: event.target.value }))} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
					</label>
					<label className="text-xs font-medium text-gray-700 dark:text-gray-300 md:col-span-2">Issue evidence
						<input aria-label="Issue evidence" type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => setForm((current) => ({ ...current, defect_evidence: Array.from(event.target.files ?? []) }))} className="mt-1 block w-full text-xs" />
						<span className="mt-1 block text-[11px] text-gray-500">JPG, PNG, or WEBP; maximum 10 MB each.</span>
					</label>
				</div>
				<button type="button" disabled={saving} onClick={() => void submit()} className="mt-3 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{saving ? "Submitting..." : "Submit supplier issue"}</button>
			</div>}

			<div className="mt-4 space-y-3">
				{loading ? <p className="text-sm text-gray-500">Loading adjustment history...</p> : adjustments.length === 0 ? <p className="text-sm text-gray-500">No supplier adjustments reported.</p> : adjustments.map((adjustment) => <article key={adjustment.id} className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
					<div className="flex flex-wrap items-center justify-between gap-2">
						<p className="text-sm font-medium text-gray-900 dark:text-white">{formatStage(adjustment.issue_stage)} · {adjustment.reported_quantity} unit(s)</p>
						<span className="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">{adjustment.status_label ?? formatCategory(adjustment.status)}</span>
					</div>
					<p className="mt-1 text-xs text-gray-500">{formatCategory(adjustment.reason_category)} · {adjustment.inventory_notes}{adjustment.resolution_label ? ` · Resolution: ${adjustment.resolution_label}` : ""}</p>
					<div className="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-3 text-xs text-gray-700 sm:grid-cols-4 dark:bg-gray-800/50 dark:text-gray-300">
						<p><strong>PO:</strong> {adjustment.purchase_order?.number}</p><p><strong>Supplier:</strong> {adjustment.purchase_order?.supplier?.name ?? "—"}</p>
						<p><strong>Item:</strong> {adjustment.item?.product_name ?? "—"}{adjustment.item?.requested_size ? ` · ${adjustment.item.requested_size}` : ""}{adjustment.item?.requested_color ? ` · ${adjustment.item.requested_color}` : ""}</p><p><strong>Affected:</strong> {adjustment.affected_quantity ?? adjustment.reported_quantity}</p>
						<p><strong>Initially accepted:</strong> {adjustment.initially_accepted_quantity ?? 0}</p><p><strong>Replacement accepted:</strong> {adjustment.replacement_accepted_quantity ?? 0}</p>
						<p><strong>Still unresolved:</strong> {adjustment.still_unresolved_quantity ?? adjustment.reported_quantity}</p><p><strong>Last updated:</strong> {adjustment.updated_at ? new Date(adjustment.updated_at).toLocaleString() : "—"}</p>
						<p><strong>Replacement:</strong> {adjustment.replacement_status_label ?? "Not selected"}</p><p><strong>Return:</strong> {adjustment.return_status_label ?? "Not set"}</p>
					</div>
					<p className="mt-2 text-xs text-gray-700 dark:text-gray-300"><strong>Current owner:</strong> {adjustment.current_owner_label ?? "Unassigned"} · <strong>Next:</strong> {adjustment.next_action ?? "Review adjustment"}</p>
					{adjustment.decline_reason && <div className="mt-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-900 dark:border-red-900/60 dark:bg-red-950/20 dark:text-red-200">
						<p><strong>Supplier decline reason:</strong> {adjustment.decline_reason}</p>
						{adjustment.supplier_reference && <p className="mt-1"><strong>Supplier reference:</strong> {adjustment.supplier_reference}</p>}
					</div>}
					{(adjustment.procurement_notes || adjustment.return_notes) && <div className="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
						{adjustment.procurement_notes && <p><strong>Procurement notes:</strong> {adjustment.procurement_notes}</p>}
						{adjustment.return_notes && <p className={adjustment.procurement_notes ? "mt-1" : undefined}><strong>Return notes:</strong> {adjustment.return_notes}</p>}
					</div>}
					{adjustment.status !== "resolved" && <div className="mt-3 flex flex-wrap gap-2">
						{canManage && !adjustment.resolution && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Choose replacement", () => purchaseOrderApi.chooseSupplierAdjustmentResolution(adjustment.id, "replacement"))} className="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-50">Choose Replacement</button>}
						{canManage && adjustment.issue_stage === "post_payment_issue" && !adjustment.resolution && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Choose refund", () => purchaseOrderApi.chooseSupplierAdjustmentResolution(adjustment.id, "refund"))} className="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-50">Choose Refund</button>}
						{canManage && adjustment.resolution === "replacement" && ["requested", "received"].includes(adjustment.replacement_status ?? "") && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Mark replacement as sent", () => purchaseOrderApi.updateSupplierReplacement(adjustment.id, "sent"))} className="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-50">Mark as Sent</button>}
						{canManage && adjustment.resolution === "replacement" && adjustment.replacement_status === "sent" && <><button type="button" disabled={saving} onClick={() => void runWorkflowAction("Confirm supplier acceptance", () => purchaseOrderApi.updateSupplierReplacement(adjustment.id, "accepted"))} className="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-50">Accepted by Supplier</button><button type="button" disabled={saving} onClick={() => void declineReplacement(adjustment)} className="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-800 hover:bg-red-50">Supplier Declined</button></>}
						{canManage && adjustment.resolution === "replacement" && adjustment.replacement_status === "accepted_by_supplier" && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Mark replacement as in transit", () => purchaseOrderApi.updateSupplierReplacement(adjustment.id, "in-transit"))} className="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-50">Mark as In Transit</button>}
						{canManage && adjustment.replacement_status === "declined" && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Close as short fulfillment", () => purchaseOrderApi.closeShortFulfillment(adjustment.id))} className="rounded border border-purple-300 px-2 py-1 text-xs font-semibold text-purple-800 hover:bg-purple-50">Close as Short Fulfillment</button>}
						{canManage && adjustment.resolution === "refund" && adjustment.status === "awaiting_supplier" && <button type="button" disabled={saving} onClick={() => void declineRefund(adjustment)} className="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-800 hover:bg-red-50">Supplier Declined Refund</button>}
						{canManage && adjustment.resolution && !adjustment.return_status && <><button type="button" disabled={saving} onClick={() => void runWorkflowAction("Require defective return", () => purchaseOrderApi.updateSupplierReturn(adjustment.id, "required"))} className="rounded border border-orange-300 px-2 py-1 text-xs font-semibold text-orange-800 hover:bg-orange-50">Return to Supplier</button><button type="button" disabled={saving} onClick={() => void runWorkflowAction("Waive defective return", () => purchaseOrderApi.updateSupplierReturn(adjustment.id, "waived"))} className="rounded border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50">Waive Return</button></>}
						{canReport && adjustment.return_status === "required" && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Confirm defective item release", () => purchaseOrderApi.updateSupplierReturn(adjustment.id, "released"))} className="rounded border border-orange-300 px-2 py-1 text-xs font-semibold text-orange-800 hover:bg-orange-50">Confirm Defective Item Released</button>}
						{canManage && adjustment.return_status === "released" && <button type="button" disabled={saving} onClick={() => void runWorkflowAction("Confirm supplier received return", () => purchaseOrderApi.updateSupplierReturn(adjustment.id, "received_by_supplier"))} className="rounded border border-green-300 px-2 py-1 text-xs font-semibold text-green-800 hover:bg-green-50">Confirm Supplier Received Return</button>}
					</div>}
					{adjustment.evidence?.length ? <div className="mt-3 flex flex-wrap gap-2">{adjustment.evidence.map((media) => { const url = `/api/erp/procurement/supplier-adjustments/${adjustment.id}/evidence/${media.id}`; return <button key={media.id} type="button" onClick={() => setSelectedEvidence({ url, name: media.file_name })} className="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 text-left hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-800"><img src={url} alt={`Evidence: ${media.file_name}`} className="h-20 w-28 object-cover" loading="lazy" /><span className="block max-w-28 truncate px-2 py-1 text-xs text-blue-700 dark:text-blue-300">View evidence</span></button>; })}</div> : null}
					{adjustment.resolution === "refund" && <p className="mt-2 text-xs text-gray-600 dark:text-gray-400">Expected refund: <strong>₱{Number(adjustment.expected_refund_amount || 0).toLocaleString()}</strong> · Confirmed: <strong>₱{Number(adjustment.refunded_amount || 0).toLocaleString()}</strong></p>}
					{canManage && adjustment.issue_stage === "post_payment_issue" && adjustment.status !== "resolved" && adjustment.resolution === "refund" && ["waived", "received_by_supplier"].includes(adjustment.return_status ?? "") && (
						<div className="mt-3">
							{selectedRefund !== adjustment.id ? <button type="button" onClick={() => { setSelectedRefund(adjustment.id); setRefundForm({ ...emptyRefundForm, expected_refund_amount: String(adjustment.expected_refund_amount || "") }); setError(null); }} className="rounded-lg border border-blue-300 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">Submit supplier refund proof</button> : (
								<div className="space-y-3 rounded-lg border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-900/60 dark:bg-blue-950/20">
									<p className="text-xs font-semibold uppercase text-blue-800 dark:text-blue-300">Supplier refund evidence</p>
									<label className="block text-xs font-medium text-gray-700 dark:text-gray-300">Expected refund amount<input aria-label="Expected refund amount" value={refundForm.expected_refund_amount} onChange={(event) => setRefundForm((current) => ({ ...current, expected_refund_amount: event.target.value }))} className="mt-1 min-h-10 w-full rounded border border-gray-300 bg-white px-2 dark:border-gray-600 dark:bg-gray-800" /></label>
									<label className="block text-xs font-medium text-gray-700 dark:text-gray-300">Supplier reference<input aria-label="Supplier refund reference" value={refundForm.supplier_reported_refund_reference} onChange={(event) => setRefundForm((current) => ({ ...current, supplier_reported_refund_reference: event.target.value }))} className="mt-1 min-h-10 w-full rounded border border-gray-300 bg-white px-2 dark:border-gray-600 dark:bg-gray-800" /></label>
									<label className="block text-xs font-medium text-gray-700 dark:text-gray-300">Supplier proof<input aria-label="Supplier refund proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setRefundForm((current) => ({ ...current, supplier_refund_proof: event.target.files?.[0] ?? null }))} className="mt-1 block w-full text-xs" /></label>
									<label className="block text-xs font-medium text-gray-700 dark:text-gray-300">Procurement notes<textarea aria-label="Refund procurement notes" rows={2} value={refundForm.procurement_notes} onChange={(event) => setRefundForm((current) => ({ ...current, procurement_notes: event.target.value }))} className="mt-1 w-full rounded border border-gray-300 bg-white px-2 py-2 dark:border-gray-600 dark:bg-gray-800" /></label>
									<div className="flex gap-2"><button type="button" disabled={saving} onClick={() => void submitRefundProof(adjustment)} className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{saving ? "Submitting..." : "Save supplier proof"}</button><button type="button" onClick={() => setSelectedRefund(null)} className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700">Cancel</button></div>
								</div>
							)}
						</div>
					)}
				</article>)}
			</div>
			{selectedEvidence ? <div role="dialog" aria-modal="true" aria-label="Supplier adjustment evidence" className="erp-modal-backdrop fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4" onClick={() => setSelectedEvidence(null)}><div className="max-h-[90vh] max-w-4xl rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" onClick={(event) => event.stopPropagation()}><div className="mb-3 flex items-center justify-between gap-4"><p className="text-sm font-semibold text-gray-900 dark:text-white">Defect evidence</p><button type="button" onClick={() => setSelectedEvidence(null)} className="min-h-11 rounded-lg px-3 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 dark:hover:bg-gray-800">Close</button></div><img src={selectedEvidence.url} alt={`Evidence: ${selectedEvidence.name}`} className="max-h-[75vh] max-w-full rounded-lg object-contain" /></div></div> : null}
		</section>
	);
}
