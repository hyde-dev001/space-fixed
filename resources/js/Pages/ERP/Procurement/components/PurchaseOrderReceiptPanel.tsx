import { useState } from "react";
import Swal from "sweetalert2";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { PurchaseOrder } from "@/types/procurement";

type Quantities = Record<number, { received: string; defective: string }>;
type SizeQuantities = Record<string, { received: string; defective: string }>;

type Props = {
	order: PurchaseOrder;
	onChanged: () => Promise<void>;
	canReceive?: boolean;
	canVoid?: boolean;
};

export default function PurchaseOrderReceiptPanel({ order, onChanged, canReceive: mayReceive = true, canVoid = true }: Props) {
	const [quantities, setQuantities] = useState<Quantities>({});
	const [sizeQuantities, setSizeQuantities] = useState<SizeQuantities>({});
	const [notes, setNotes] = useState("");
	const [idempotencyKey, setIdempotencyKey] = useState<string | null>(null);
	const [saving, setSaving] = useState(false);
	const canReceive = mayReceive && !order.is_historical && ["in_transit", "partially_received"].includes(order.status);

	const setQuantity = (itemId: number, field: "received" | "defective", value: string) => {
		setQuantities((current) => ({
			...current,
			[itemId]: { received: current[itemId]?.received ?? "", defective: current[itemId]?.defective ?? "", [field]: value },
		}));
		setIdempotencyKey(null);
	};
	const setSizeQuantity = (itemId: number, sizeId: number, field: "received" | "defective", value: string) => {
		const key = `${itemId}:${sizeId}`;
		setSizeQuantities((current) => ({
			...current,
			[key]: { received: current[key]?.received ?? "", defective: current[key]?.defective ?? "", [field]: value },
		}));
		setIdempotencyKey(null);
	};

	const receive = async () => {
		const items = (order.items ?? []).map((item) => {
			const eligible = (item.inventory_item?.sizes ?? []).filter((size) => item.eligible_size_ids?.includes(size.id));
			if (eligible.length > 1) {
				const allocations = eligible.map((size) => ({
					inventory_size_id: size.id,
					received_quantity: Number(sizeQuantities[`${item.id}:${size.id}`]?.received || 0),
					defective_quantity: Number(sizeQuantities[`${item.id}:${size.id}`]?.defective || 0),
				}));
				return {
					purchase_order_item_id: item.id,
					received_quantity: allocations.reduce((sum, row) => sum + row.received_quantity, 0),
					defective_quantity: allocations.reduce((sum, row) => sum + row.defective_quantity, 0),
					size_quantities: allocations,
				};
			}

			return {
				purchase_order_item_id: item.id,
				received_quantity: Number(quantities[item.id]?.received || 0),
				defective_quantity: Number(quantities[item.id]?.defective || 0),
			};
		}).filter((item) => item.received_quantity > 0);

		if (!items.length || items.some((item) => item.defective_quantity > item.received_quantity)) {
			await Swal.fire("Invalid quantities", "Enter a received quantity and keep defects at or below it.", "warning");
			return;
		}
		const accepted = items.reduce((sum, item) => sum + item.received_quantity - item.defective_quantity, 0);
		const confirmation = await Swal.fire({
			title: "Post this receipt?",
			text: accepted > 0
				? `${accepted} accepted unit${accepted === 1 ? "" : "s"} will be added to stock and submitted to Finance as a pending expense.`
				: "All entered units are defective, so no stock or expense will be posted.",
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Post receipt",
		});
		if (!confirmation.isConfirmed) return;

		const key = idempotencyKey ?? crypto.randomUUID();
		setIdempotencyKey(key);
		setSaving(true);
		try {
			await purchaseOrderApi.receive(order.id, { idempotency_key: key, notes: notes.trim() || undefined, items });
			setQuantities({});
			setSizeQuantities({});
			setNotes("");
			setIdempotencyKey(null);
			await onChanged();
			await Swal.fire("Receipt posted", accepted > 0 ? "Stock was updated and the expense is pending Finance review." : "The defective delivery was recorded without changing stock or Finance.", "success");
		} catch (error: any) {
			await Swal.fire("Receipt not posted", error?.response?.data?.message ?? "Check the quantities and try again.", "error");
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
					<thead><tr className="text-left text-xs text-gray-500"><th className="py-2 pr-3">Item</th><th className="px-2">Ordered</th><th className="px-2">Accepted</th><th className="px-2">Remaining</th>{canReceive && <><th className="px-2">Received now</th><th className="px-2">Defective</th></>}</tr></thead>
					<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
						{(order.items ?? []).map((item) => {
							const eligible = (item.inventory_item?.sizes ?? []).filter((size) => item.eligible_size_ids?.includes(size.id));
							const perSize = eligible.length > 1;
							const perSizeLimit = perSize ? Math.ceil(item.ordered_quantity / eligible.length) : undefined;
							return (
							<tr key={item.id}>
								<td className="py-2 pr-3 text-gray-900 dark:text-white">{item.product_name}{perSize && <div className="text-xs text-gray-500">{eligible.map((size) => `${size.size_system ?? "US"} ${size.size}`).join(", ")} · {perSizeLimit} each</div>}</td>
								<td className="px-2">{item.ordered_quantity}</td><td className="px-2">{item.accepted_quantity}</td><td className="px-2">{item.remaining_quantity}</td>
								{canReceive && <>
									<td className="px-2">{perSize ? <div className="space-y-1">{eligible.map((size) => { const key = `${item.id}:${size.id}`; const sizeLabel = `${size.size_system ?? "US"} ${size.size}`; const name = `${item.product_name} ${sizeLabel}`; return <label key={size.id} className="flex items-center gap-2"><span className="text-xs text-gray-500">{sizeLabel}</span><input aria-label={`Received ${name}`} type="number" min="0" max={perSizeLimit} value={sizeQuantities[key]?.received ?? ""} onChange={(event) => setSizeQuantity(item.id, size.id, "received", event.target.value)} className="block w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" /></label>; })}</div> : <input aria-label={`Received ${item.product_name}`} type="number" min="0" value={quantities[item.id]?.received ?? ""} onChange={(event) => setQuantity(item.id, "received", event.target.value)} className="w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" />}</td>
									<td className="px-2">{perSize ? <div className="space-y-1">{eligible.map((size) => { const key = `${item.id}:${size.id}`; const sizeLabel = `${size.size_system ?? "US"} ${size.size}`; const name = `${item.product_name} ${sizeLabel}`; return <label key={size.id} className="flex items-center gap-2"><span className="text-xs text-gray-500">{sizeLabel}</span><input aria-label={`Defective ${name}`} type="number" min="0" max={perSizeLimit} value={sizeQuantities[key]?.defective ?? ""} onChange={(event) => setSizeQuantity(item.id, size.id, "defective", event.target.value)} className="block w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" /></label>; })}</div> : <input aria-label={`Defective ${item.product_name}`} type="number" min="0" value={quantities[item.id]?.defective ?? ""} onChange={(event) => setQuantity(item.id, "defective", event.target.value)} className="block w-20 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1" />}</td>
								</>}
							</tr>
						);})}
					</tbody>
				</table>
			</div>

			{canReceive && <div className="flex flex-col sm:flex-row gap-2">
				<input value={notes} onChange={(event) => { setNotes(event.target.value); setIdempotencyKey(null); }} placeholder="Optional receipt notes" className="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm" />
				<button type="button" disabled={saving} onClick={receive} className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{saving ? "Posting..." : "Post receipt"}</button>
			</div>}

			<div className="space-y-2">
				<h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Receipt history</h4>
				{(order.receipts ?? []).length === 0 ? <p className="text-sm text-gray-500">No receipts yet.</p> : (order.receipts ?? []).map((receipt) => (
					<div key={receipt.id} className="flex items-center justify-between gap-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 p-3">
						<div><p className="text-sm font-medium">Receipt #{receipt.id} · {receipt.status}</p><p className="text-xs text-gray-500">{new Date(receipt.received_at).toLocaleString()} · {receipt.items.reduce((sum, item) => sum + item.accepted_quantity, 0)} accepted</p></div>
						{canVoid && receipt.source === "manual" && receipt.status === "posted" && order.status !== "completed" && <button type="button" onClick={() => voidReceipt(receipt.id)} className="text-sm font-medium text-red-600 hover:underline">Void</button>}
					</div>
				))}
			</div>
		</div>
	);
}
