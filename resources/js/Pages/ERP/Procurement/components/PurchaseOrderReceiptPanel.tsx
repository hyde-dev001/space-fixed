import { Fragment, useEffect, useState } from "react";
import Swal from "sweetalert2";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { PurchaseOrder, SupplierAdjustment, SupplierAdjustmentReasonCategory } from "@/types/procurement";

type Quantities = Record<number, { received: string; defective: string }>;
type SizeQuantities = Record<string, { received: string; defective: string }>;
type DefectDetails = {
	reason_category: SupplierAdjustmentReasonCategory | "";
	inventory_notes: string;
	defect_evidence: File[];
};

const defectCategories: Array<{ value: SupplierAdjustmentReasonCategory; label: string }> = [
	{ value: "manufacturing_defect", label: "Manufacturing defect" },
	{ value: "damaged", label: "Damaged" },
	{ value: "wrong_item", label: "Wrong item" },
	{ value: "incorrect_size_or_variant", label: "Incorrect size or variant" },
	{ value: "other", label: "Other" },
];

const clampQuantity = (value: string, maximum: number) => {
	if (value === "") return "";
	const quantity = Number(value);
	if (!Number.isFinite(quantity)) return "";
	return String(Math.min(Math.max(Math.trunc(quantity), 0), maximum));
};

type Props = {
	order: PurchaseOrder;
	onChanged: () => Promise<void>;
	canReceive?: boolean;
	canVoid?: boolean;
};

export default function PurchaseOrderReceiptPanel({ order, onChanged, canReceive: mayReceive = true, canVoid = true }: Props) {
	const [quantities, setQuantities] = useState<Quantities>({});
	const [sizeQuantities, setSizeQuantities] = useState<SizeQuantities>({});
	const [defectDetails, setDefectDetails] = useState<Record<number, DefectDetails>>({});
	const [replacementAdjustments, setReplacementAdjustments] = useState<SupplierAdjustment[]>([]);
	const [notes, setNotes] = useState("");
	const [idempotencyKey, setIdempotencyKey] = useState<string | null>(null);
	const [saving, setSaving] = useState(false);
	useEffect(() => {
		if (!mayReceive || typeof purchaseOrderApi.getSupplierAdjustments !== "function") return;
		void purchaseOrderApi.getSupplierAdjustments({ purchase_order_id: order.id }).then((all) => {
			const relevant = all.filter((adjustment) =>
				adjustment.purchase_order?.id === order.id
				&& adjustment.status !== "resolved"
				&& adjustment.resolution !== "refund"
			);
			setReplacementAdjustments((current) => relevant.length > 0 || current.length > 0 ? relevant : current);
		}).catch(() => setReplacementAdjustments([]));
	}, [mayReceive, order.id]);

	const pendingReceipt = (order.receipts ?? []).find((receipt) => receipt.status === "receiving");
	const canFinalize = mayReceive && order.can_finalize === true;
	const finalPayableQuantity = order.final_payable_quantity ?? 0;
	const canReceiveNormally = !pendingReceipt && !order.is_historical && ["in_transit", "partially_received"].includes(order.status);
	const inTransitReplacements = replacementAdjustments.filter((adjustment) => adjustment.resolution === "replacement" && adjustment.replacement_status === "in_transit");
	const canReceiveReplacement = ["delivered", "completed"].includes(order.status) && inTransitReplacements.length > 0;
	const canReceivePendingReplacement = Boolean(pendingReceipt) && inTransitReplacements.length > 0;
	const canReceive = mayReceive && (canReceiveNormally || canReceiveReplacement || canReceivePendingReplacement);
	const receivingReplacement = !canReceiveNormally && inTransitReplacements.length > 0;

	const setQuantity = (itemId: number, field: "received" | "defective", value: string, maximum: number) => {
		setQuantities((current) => ({
			...current,
			[itemId]: { received: current[itemId]?.received ?? "", defective: current[itemId]?.defective ?? "", [field]: clampQuantity(value, maximum) },
			}));
			setIdempotencyKey(null);
	};
	const setSizeQuantity = (itemId: number, sizeId: number, field: "received" | "defective", value: string, maximum: number) => {
		const key = `${itemId}:${sizeId}`;
		setSizeQuantities((current) => ({
			...current,
			[key]: { received: current[key]?.received ?? "", defective: current[key]?.defective ?? "", [field]: clampQuantity(value, maximum) },
		}));
		setIdempotencyKey(null);
		};
	const setDefectDetail = (itemId: number, field: keyof DefectDetails, value: string | File[]) => {
		setDefectDetails((current) => ({
			...current,
			[itemId]: {
				reason_category: current[itemId]?.reason_category ?? "",
				inventory_notes: current[itemId]?.inventory_notes ?? "",
				defect_evidence: current[itemId]?.defect_evidence ?? [],
				[field]: value,
			},
		}));
		setIdempotencyKey(null);
	};

	const receive = async () => {
		const items = (order.items ?? []).map((item) => {
			const replacementTargets = receivingReplacement
				? inTransitReplacements.filter((adjustment) => adjustment.purchase_order_item_id === item.id)
				: [];
			const replacementAdjustmentId = replacementTargets.length === 1 ? replacementTargets[0].id : undefined;
			const eligible = (item.inventory_item?.sizes ?? []).filter((size) => item.eligible_size_ids?.includes(size.id));
			const receivedQuantity = eligible.length > 1
				? eligible.reduce((sum, size) => sum + Number(sizeQuantities[`${item.id}:${size.id}`]?.received || 0), 0)
				: Number(quantities[item.id]?.received || 0);
			const defectiveQuantity = eligible.length > 1
				? eligible.reduce((sum, size) => sum + Number(sizeQuantities[`${item.id}:${size.id}`]?.defective || 0), 0)
				: Number(quantities[item.id]?.defective || 0);
			const details = defectDetails[item.id];
			if (eligible.length > 1) {
				const allocations = eligible.map((size) => ({
					inventory_size_id: size.id,
					received_quantity: Number(sizeQuantities[`${item.id}:${size.id}`]?.received || 0),
					defective_quantity: Number(sizeQuantities[`${item.id}:${size.id}`]?.defective || 0),
				}));
				return {
					purchase_order_item_id: item.id,
					...(replacementAdjustmentId ? { replacement_for_adjustment_id: replacementAdjustmentId } : {}),
						received_quantity: receivedQuantity,
						defective_quantity: defectiveQuantity,
						size_quantities: allocations,
						...(defectiveQuantity > 0 ? {
							reason_category: details?.reason_category || undefined,
							inventory_notes: details?.inventory_notes.trim() || undefined,
							defect_evidence: details?.defect_evidence ?? [],
						} : {}),
					};
			}

			return {
				purchase_order_item_id: item.id,
				...(replacementAdjustmentId ? { replacement_for_adjustment_id: replacementAdjustmentId } : {}),
				received_quantity: receivedQuantity,
				defective_quantity: defectiveQuantity,
				...(defectiveQuantity > 0 ? {
					reason_category: details?.reason_category || undefined,
					inventory_notes: details?.inventory_notes.trim() || undefined,
					defect_evidence: details?.defect_evidence ?? [],
				} : {}),
			};
		}).filter((item) => item.received_quantity > 0);

		if (!items.length || items.some((item) => item.defective_quantity > item.received_quantity)) {
			await Swal.fire("Invalid quantities", "Enter a received quantity and keep defects at or below it.", "warning");
			return;
		}
		if (receivingReplacement && items.some((item) => inTransitReplacements.filter((adjustment) => adjustment.purchase_order_item_id === item.purchase_order_item_id).length !== 1)) {
			await Swal.fire("Replacement unavailable", "The system could not identify one in-transit replacement for this item. Ask Procurement to review the supplier adjustment.", "warning");
			return;
		}
		const incompleteDefect = items.find((item) => item.defective_quantity > 0 && (
			!item.reason_category || !item.inventory_notes || item.defect_evidence.length === 0
		));
		if (incompleteDefect) {
			await Swal.fire("Defect details required", "Choose a category, add notes, and attach at least one image for every defective line.", "warning");
			return;
		}
		const replacementMode = items.some((item) => Boolean(item.replacement_for_adjustment_id));
		if (!canReceiveNormally && items.some((item) => !item.replacement_for_adjustment_id)) {
			await Swal.fire("Choose a replacement", "This order is already fully received. Select the in-transit supplier replacement before submitting.", "warning");
			return;
		}
		if (!replacementMode && items.length !== (order.items ?? []).length) {
			await Swal.fire("Complete delivery required", "Account for every purchase-order item in one receiving result.", "warning");
			return;
		}
		if (replacementMode && items.some((item) => !item.replacement_for_adjustment_id)) {
			await Swal.fire("Choose one receiving mode", "Submit the original delivery or a supplier replacement, not both together.", "warning");
			return;
		}
		const accepted = items.reduce((sum, item) => sum + item.received_quantity - item.defective_quantity, 0);
		const confirmation = await Swal.fire({
			title: replacementMode ? "Receive this supplier replacement?" : "Submit this receiving result?",
			text: accepted > 0
				? `${accepted} accepted unit${accepted === 1 ? "" : "s"} will be added to usable stock. Finance remains blocked until the final receipt is posted.`
				: "All entered units are defective, so no usable stock or Finance expense will be created.",
			icon: "question",
			showCancelButton: true,
			confirmButtonText: replacementMode ? "Receive replacement" : "Submit result",
		});
		if (!confirmation.isConfirmed) return;

		const key = idempotencyKey ?? crypto.randomUUID();
		setIdempotencyKey(key);
		setSaving(true);
		try {
			await purchaseOrderApi.receive(order.id, { idempotency_key: key, notes: notes.trim() || undefined, items });
			setQuantities({});
			setSizeQuantities({});
			setDefectDetails({});
			setNotes("");
			setIdempotencyKey(null);
			await onChanged();
			await Swal.fire(replacementMode ? "Replacement received" : "Receiving result submitted", accepted > 0 ? "Usable stock was updated. Post the one final receipt after every supplier adjustment is resolved." : "The defective delivery was recorded without changing usable stock or Finance.", "success");
		} catch (error: any) {
			await Swal.fire("Receipt not posted", error?.response?.data?.message ?? "Check the quantities and try again.", "error");
		} finally {
			setSaving(false);
		}
	};

	const finalizeReceipt = async () => {
		if (!pendingReceipt) return;
		const confirmation = await Swal.fire({ title: "Post the final receipt?", text: `This creates the one Finance payable for ${finalPayableQuantity} accepted unit${finalPayableQuantity === 1 ? "" : "s"}.`, icon: "question", showCancelButton: true, confirmButtonText: "Post Final Receipt" });
		if (!confirmation.isConfirmed) return;
		setSaving(true);
		try {
			await purchaseOrderApi.finalizeReceipt(order.id, pendingReceipt.id);
			await onChanged();
			await Swal.fire("Final receipt posted", "The one final receipt was posted and Finance can now review the payable expense.", "success");
		} catch (error: any) {
			await Swal.fire("Not ready to finalize", error?.response?.data?.message ?? "Resolve all supplier adjustments and required returns first.", "error");
		} finally {
			setSaving(false);
		}
	};

	const voidReceipt = async (receiptId: number) => {
		const result = await Swal.fire({
			title: "Void this receipt?",
			input: "textarea",
			inputLabel: "Reason",
			showCancelButton: true,
			inputValidator: (value) => value?.trim() ? undefined : "A reason is required.",
		});
		if (!result.isConfirmed) return;
		try {
			await purchaseOrderApi.voidReceipt(order.id, receiptId, result.value.trim());
			await onChanged();
			await Swal.fire("Receipt voided", "Its stock movement was reversed and the pending expense was rejected.", "success");
		} catch (error: any) {
			await Swal.fire("Receipt not voided", error?.response?.data?.message ?? "This receipt can no longer be voided.", "error");
		}
	};

	return (
		<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-4">
			<div>
				<h3 className="text-sm font-semibold text-gray-900 dark:text-white">Receiving</h3>
				<p className="text-xs text-gray-500">Record actual arrivals. Defective units do not enter usable stock.</p>
			</div>

			<div className="overflow-x-auto">
				<table className="min-w-full text-sm">
					<thead><tr className="text-left text-xs text-gray-500"><th className="py-2 pr-3">Item</th><th className="px-2">Ordered</th><th className="px-2">Accounted</th><th className="px-2">Accepted</th><th className="px-2">Defective</th><th className="px-2">Unresolved</th>{canReceive && <><th className="px-2">Received now</th><th className="px-2">Defective now</th></>}</tr></thead>
					<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
						{(order.items ?? []).map((item) => {
							const eligible = (item.inventory_item?.sizes ?? []).filter((size) => item.eligible_size_ids?.includes(size.id));
							const perSize = eligible.length > 1;
							const perSizeLimit = perSize ? Math.ceil(item.ordered_quantity / eligible.length) : item.ordered_quantity;
							const summary = order.receiving_items?.find((line) => line.purchase_order_item_id === item.id);
			return (
			<Fragment key={item.id}>
			<tr>
								<td className="py-2 pr-3 text-gray-900 dark:text-white">{item.product_name}{perSize && <div className="text-xs text-gray-500">{eligible.map((size) => `${size.size_system ?? "US"} ${size.size}`).join(", ")} · {perSizeLimit} each</div>}</td>
								<td className="px-2">{item.ordered_quantity}</td><td className="px-2">{summary?.accounted_quantity ?? 0}</td><td className="px-2">{summary?.final_payable_quantity ?? 0}</td><td className="px-2">{summary?.defective_quantity ?? 0}</td><td className="px-2">{summary?.still_unresolved_quantity ?? item.ordered_quantity}</td>
										{canReceive && <>
										<td className="px-2">{perSize ? <div className="space-y-1">{eligible.map((size) => { const key = `${item.id}:${size.id}`; const sizeLabel = `${size.size_system ?? "US"} ${size.size}`; const name = `${item.product_name} ${sizeLabel}`; return <label key={size.id} className="flex items-center gap-2"><span className="text-xs text-gray-500">{sizeLabel}</span><input aria-label={`Received ${name}`} type="number" min="0" max={perSizeLimit} step="1" value={sizeQuantities[key]?.received ?? ""} onChange={(event) => setSizeQuantity(item.id, size.id, "received", event.target.value, perSizeLimit)} className="block w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" /></label>; })}</div> : <input aria-label={`Received ${item.product_name}`} type="number" min="0" max={item.ordered_quantity} step="1" value={quantities[item.id]?.received ?? ""} onChange={(event) => setQuantity(item.id, "received", event.target.value, item.ordered_quantity)} className="w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" />}</td>
										<td className="px-2">{perSize ? <div className="space-y-1">{eligible.map((size) => { const key = `${item.id}:${size.id}`; const sizeLabel = `${size.size_system ?? "US"} ${size.size}`; const name = `${item.product_name} ${sizeLabel}`; return <label key={size.id} className="flex items-center gap-2"><span className="text-xs text-gray-500">{sizeLabel}</span><input aria-label={`Defective ${name}`} type="number" min="0" max={perSizeLimit} step="1" value={sizeQuantities[key]?.defective ?? ""} onChange={(event) => setSizeQuantity(item.id, size.id, "defective", event.target.value, perSizeLimit)} className="block w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" /></label>; })}</div> : <input aria-label={`Defective ${item.product_name}`} type="number" min="0" max={item.ordered_quantity} step="1" value={quantities[item.id]?.defective ?? ""} onChange={(event) => setQuantity(item.id, "defective", event.target.value, item.ordered_quantity)} className="block w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" />}</td>
									</>}
									</tr>
									{canReceive && ((perSize
										? eligible.reduce((sum, size) => sum + Number(sizeQuantities[`${item.id}:${size.id}`]?.defective || 0), 0)
										: Number(quantities[item.id]?.defective || 0)) > 0) && <tr key={`${item.id}-defect-details`}>
										<td colSpan={8} className="bg-amber-50/70 px-3 py-3 dark:bg-amber-950/20">
											<fieldset className="grid grid-cols-1 gap-3 md:grid-cols-3">
												<legend className="sr-only">Defect details for {item.product_name}</legend>
												<label className="text-xs font-medium text-gray-700 dark:text-gray-300">Defect category {item.product_name}
													<select aria-label={`Defect category ${item.product_name}`} value={defectDetails[item.id]?.reason_category ?? ""} onChange={(event) => setDefectDetail(item.id, "reason_category", event.target.value)} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800">
														<option value="">Choose category</option>
														{defectCategories.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}
													</select>
												</label>
												<label className="text-xs font-medium text-gray-700 dark:text-gray-300">Defect notes {item.product_name}
													<textarea aria-label={`Defect notes ${item.product_name}`} value={defectDetails[item.id]?.inventory_notes ?? ""} onChange={(event) => setDefectDetail(item.id, "inventory_notes", event.target.value)} rows={2} className="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
												</label>
												<label className="text-xs font-medium text-gray-700 dark:text-gray-300">Defect evidence {item.product_name}
													<input aria-label={`Defect evidence ${item.product_name}`} type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => setDefectDetail(item.id, "defect_evidence", Array.from(event.target.files ?? []))} className="mt-1 block w-full text-xs" />
													<span className="mt-1 block text-[11px] text-gray-500">JPG, PNG, or WEBP; maximum 10 MB each.</span>
												</label>
											</fieldset>
										</td>
									</tr>}
								</Fragment>
								);})}
					</tbody>
				</table>
			</div>

			{pendingReceipt && <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-200">
				<p className="font-semibold">{canFinalize ? "Ready to finalize" : "Awaiting supplier resolution"}</p>
				<p className="mt-1">Initial accepted: {order.initial_accepted_quantity ?? 0} · Replacement accepted: {order.replacement_accepted_quantity ?? 0} · Short fulfillment: {order.short_fulfillment_quantity ?? 0} · Final payable quantity: {finalPayableQuantity}</p>
				{!canFinalize && mayReceive && order.finalization_blockers?.length ? <ul className="mt-2 list-disc pl-5 text-xs">{order.finalization_blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}</ul> : null}
				{canFinalize && <button type="button" disabled={saving} onClick={() => void finalizeReceipt()} className="mt-3 rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">Post Final Receipt</button>}
			</div>}

			{canReceive && <div className="flex flex-col sm:flex-row gap-2">
				<input value={notes} onChange={(event) => { setNotes(event.target.value); setIdempotencyKey(null); }} placeholder="Optional receipt notes" className="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm" />
				<button type="button" disabled={saving} onClick={receive} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{saving ? "Submitting..." : receivingReplacement ? "Receive replacement" : "Submit receiving result"}</button>
			</div>}

			<div className="space-y-2">
				<h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Receipt history</h4>
				{(order.receipts ?? []).length === 0 ? <p className="text-sm text-gray-500">No receipts yet.</p> : (order.receipts ?? []).map((receipt) => (
					<div key={receipt.id} className="flex items-center justify-between gap-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 p-3">
						<div><p className="text-sm font-medium">{receipt.status === "receiving" ? "Receiving result" : (receipt.receipt_reference ?? `Receipt #${receipt.id}`)} · {receipt.status}</p><p className="text-xs text-gray-500">{new Date(receipt.received_at).toLocaleString()} · {receipt.items.reduce((sum, item) => sum + item.accepted_quantity, 0)} accepted</p></div>
						{canVoid && receipt.source === "manual" && receipt.status === "posted" && order.status !== "completed" && <button type="button" onClick={() => voidReceipt(receipt.id)} className="text-sm font-medium text-red-600 hover:underline">Void</button>}
					</div>
				))}
			</div>
		</div>
	);
}
