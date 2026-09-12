import { useEffect, useMemo, useState } from "react";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type {
	PurchaseOrder,
	PurchaseOrderReceiptItem,
	SupplierAdjustment,
	SupplierAdjustmentReasonCategory,
} from "@/types/procurement";

type Props = {
	order: PurchaseOrder;
	canReport?: boolean;
	onChanged?: () => Promise<void>;
};

type IssueForm = {
	reported_quantity: string;
	reason_category: SupplierAdjustmentReasonCategory | "";
	inventory_notes: string;
	defect_evidence: File[];
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

const formatStage = (stage: string) => stage === "post_payment_issue" ? "Post-payment issue" : "Receiving defect";
const formatCategory = (category: string) => category.split("_").map((part) => part[0].toUpperCase() + part.slice(1)).join(" ");

export default function SupplierAdjustmentsPanel({ order, canReport = false, onChanged }: Props) {
	const [adjustments, setAdjustments] = useState<SupplierAdjustment[]>([]);
	const [selectedItem, setSelectedItem] = useState<{ receiptId: number; item: PurchaseOrderReceiptItem } | null>(null);
	const [form, setForm] = useState<IssueForm>(emptyForm);
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const loadAdjustments = async () => {
		setLoading(true);
		try {
			const all = await purchaseOrderApi.getSupplierAdjustments();
			setAdjustments(all.filter((adjustment) => adjustment.purchase_order?.id === order.id));
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

	const reportableItems = useMemo(() => (order.receipts ?? []).flatMap((receipt) =>
		receipt.status === "posted"
			? receipt.items.filter((item) => item.accepted_quantity > 0).map((item) => ({ receiptId: receipt.id, item }))
			: [],
	), [order.receipts]);

	const remainingQuantity = (itemId: number, acceptedQuantity: number) => {
		const claimed = adjustments
			.filter((adjustment) => adjustment.receipt_item_id === itemId && adjustment.issue_stage === "post_payment_issue" && adjustment.status !== "resolved")
			.reduce((sum, adjustment) => sum + adjustment.reported_quantity, 0);
		return Math.max(acceptedQuantity - claimed, 0);
	};

	const submit = async () => {
		if (!selectedItem) return;
		const quantity = Number(form.reported_quantity);
		if (!Number.isInteger(quantity) || quantity < 1 || quantity > remainingQuantity(selectedItem.item.id, selectedItem.item.accepted_quantity)) {
			setError("Enter a quantity within the remaining accepted units.");
			return;
		}
		if (!form.reason_category || !form.inventory_notes.trim() || form.defect_evidence.length === 0) {
			setError("Choose a category, add notes, and attach at least one image.");
			return;
		}

		setSaving(true);
		setError(null);
		try {
			await purchaseOrderApi.reportPostPaymentIssue(order.id, selectedItem.receiptId, selectedItem.item.id, {
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
		} catch (requestError: any) {
			setError(requestError?.response?.data?.message ?? "The supplier issue could not be reported.");
		} finally {
			setSaving(false);
		}
	};

	return (
		<section className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
			<div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
				<div>
					<h3 className="text-sm font-semibold text-gray-900 dark:text-white">Supplier adjustments</h3>
					<p className="text-xs text-gray-500">Receiving defects and later issues remain attached to the original receipt item.</p>
				</div>
				{canReport && reportableItems.some(({ item }) => remainingQuantity(item.id, item.accepted_quantity) > 0) && (
					<button type="button" onClick={() => { setSelectedItem(reportableItems.find(({ item }) => remainingQuantity(item.id, item.accepted_quantity) > 0) ?? null); setError(null); }} className="rounded-lg border border-amber-300 px-3 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30">
						Report post-payment issue
					</button>
				)}
			</div>

			{error && <p role="alert" className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-300">{error}</p>}

			{selectedItem && <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-900/60 dark:bg-amber-950/20">
				<div className="mb-3 flex items-start justify-between gap-3">
					<div>
						<h4 className="text-sm font-semibold text-gray-900 dark:text-white">Report a later supplier issue</h4>
						<p className="text-xs text-gray-600 dark:text-gray-400">Receipt #{selectedItem.receiptId} · {remainingQuantity(selectedItem.item.id, selectedItem.item.accepted_quantity)} accepted unit(s) remaining.</p>
					</div>
					<button type="button" onClick={() => setSelectedItem(null)} className="text-sm text-gray-500 hover:underline">Cancel</button>
				</div>
				<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
					<label className="text-xs font-medium text-gray-700 dark:text-gray-300">Issue quantity
						<input aria-label="Issue quantity" type="number" min="1" max={remainingQuantity(selectedItem.item.id, selectedItem.item.accepted_quantity)} value={form.reported_quantity} onChange={(event) => setForm((current) => ({ ...current, reported_quantity: event.target.value }))} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
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
						<span className="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">{adjustment.status}</span>
					</div>
					<p className="mt-1 text-xs text-gray-500">{formatCategory(adjustment.reason_category)} · {adjustment.inventory_notes}</p>
					{adjustment.evidence?.length ? <div className="mt-2 flex flex-wrap gap-2">{adjustment.evidence.map((media) => <a key={media.id} href={`/api/erp/procurement/supplier-adjustments/${adjustment.id}/evidence/${media.id}`} target="_blank" rel="noreferrer" className="text-xs font-medium text-blue-600 hover:underline">View {media.file_name}</a>)}</div> : null}
				</article>)}
			</div>
		</section>
	);
}
