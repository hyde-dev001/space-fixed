import { useEffect, useState } from "react";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { SupplierAdjustment } from "@/types/procurement";

type Props = {
	currentOwner: "procurement" | "inventory";
	onOpen: (purchaseOrderId: number) => void;
};

export default function SupplierAdjustmentQueue({ currentOwner, onOpen }: Props) {
	const [adjustments, setAdjustments] = useState<SupplierAdjustment[]>([]);

	useEffect(() => {
		void purchaseOrderApi.getSupplierAdjustments({ current_owner: currentOwner })
			.then(setAdjustments)
			.catch(() => setAdjustments([]));
	}, [currentOwner]);

	if (adjustments.length === 0) return null;

	return <section className="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900/60 dark:bg-amber-950/20">
		<div className="mb-3 flex items-center justify-between gap-3">
			<div><h2 className="text-base font-semibold text-gray-900 dark:text-white">Supplier adjustments needing your action</h2><p className="text-sm text-gray-600 dark:text-gray-300">Open the affected order to complete the next handoff.</p></div>
			<span className="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-100">{adjustments.length}</span>
		</div>
		<div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{adjustments.map((adjustment) => <button key={adjustment.id} type="button" onClick={() => adjustment.purchase_order?.id && onOpen(adjustment.purchase_order.id)} className="min-h-24 rounded-xl border border-gray-200 bg-white p-4 text-left transition-colors hover:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500 dark:border-gray-700 dark:bg-gray-900">
			<p className="font-semibold text-gray-900 dark:text-white">{adjustment.purchase_order?.number} · {adjustment.item?.product_name}</p>
			<p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{adjustment.still_unresolved_quantity ?? adjustment.affected_quantity} unit(s) unresolved</p>
			<p className="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300">Next: {adjustment.next_action}</p>
		</button>)}</div>
	</section>;
}
