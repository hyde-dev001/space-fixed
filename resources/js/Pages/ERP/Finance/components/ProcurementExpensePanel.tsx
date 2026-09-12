import type { ProcurementExpenseDetails } from "@/types/procurement";

interface ProcurementExpensePanelProps {
	details: ProcurementExpenseDetails;
	expenseStatus: string;
	amount: number | string;
	isReviewPending?: boolean;
	onReviewAndRelease?: () => void;
	canPaySupplier?: boolean;
	onPaySupplier?: () => void;
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
	expenseStatus,
	amount,
	isReviewPending = false,
	onReviewAndRelease,
	canPaySupplier = false,
	onPaySupplier,
}: ProcurementExpensePanelProps) {
	const isSubmitted = expenseStatus === "submitted";
	const isReadyForPayment = expenseStatus === "posted" && details.payment_status !== "paid";

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

			{isReadyForPayment && (
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
		</div>
	);
}
