import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SupplierAdjustmentsPanel from "../components/SupplierAdjustmentsPanel";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { PurchaseOrder } from "@/types/procurement";

vi.mock("@/services/purchaseOrderApi", () => ({
	purchaseOrderApi: {
		getSupplierAdjustments: vi.fn(),
		reportPostPaymentIssue: vi.fn(),
	},
}));

const order = {
	id: 10,
	po_number: "PO-10",
	shop_owner_id: 1,
	supplier_id: 1,
	product_name: "Shoe cleaner",
	quantity: 5,
	unit_cost: 100,
	total_cost: 500,
	payment_terms: "Net 30",
	status: "completed",
	is_historical: true,
	ordered_by: 1,
	ordered_date: "2026-08-02",
	created_at: "2026-08-02",
	updated_at: "2026-08-02",
	items: [],
	receipts: [{
		id: 30,
		purchase_order_id: 10,
		source: "manual",
		status: "posted",
		received_at: "2026-08-03",
		items: [{
			id: 40,
			purchase_order_item_id: 20,
			received_quantity: 2,
			defective_quantity: 0,
			accepted_quantity: 2,
		}],
	}],
} as PurchaseOrder;

describe("SupplierAdjustmentsPanel", () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([]);
		vi.mocked(purchaseOrderApi.reportPostPaymentIssue).mockResolvedValue({} as any);
		vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue("123e4567-e89b-12d3-a456-426614174000");
	});

	it("reports a late issue with evidence and uses tenant-protected evidence links", async () => {
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([{
			id: 90,
			issue_stage: "post_payment_issue",
			reported_quantity: 1,
			unit_cost_snapshot: "100.00",
			reason_category: "damaged",
			inventory_notes: "Found after use.",
			status: "reported",
			purchase_order: { id: 10, number: "PO-10", status: "completed" },
			receipt: { id: 30, status: "posted" },
			receipt_item_id: 40,
			evidence: [{ id: 901, file_name: "damage.jpg", mime_type: "image/jpeg", size: 10 }],
		}]);

		render(<SupplierAdjustmentsPanel order={order} canReport onChanged={vi.fn().mockResolvedValue(undefined)} />);

		expect(await screen.findByText(/Found after use\./)).toBeInTheDocument();
		expect(screen.getByRole("link", { name: "View damage.jpg" })).toHaveAttribute(
			"href",
			"/api/erp/procurement/supplier-adjustments/90/evidence/901",
		);
		expect(screen.getByRole("link", { name: "View damage.jpg" })).not.toHaveAttribute("href", expect.stringContaining("/storage/"));

		fireEvent.click(screen.getByRole("button", { name: "Report post-payment issue" }));
		fireEvent.change(screen.getByLabelText("Issue quantity"), { target: { value: "1" } });
		fireEvent.change(screen.getByLabelText("Issue category"), { target: { value: "wrong_item" } });
		fireEvent.change(screen.getByLabelText("Issue notes"), { target: { value: "Wrong variant found later." } });
		const proof = new File(["proof"], "late-issue.jpg", { type: "image/jpeg" });
		fireEvent.change(screen.getByLabelText("Issue evidence"), { target: { files: [proof] } });
		fireEvent.click(screen.getByRole("button", { name: "Submit supplier issue" }));

		await waitFor(() => expect(purchaseOrderApi.reportPostPaymentIssue).toHaveBeenCalledWith(10, 30, 40, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			reported_quantity: 1,
			reason_category: "wrong_item",
			inventory_notes: "Wrong variant found later.",
			defect_evidence: [proof],
		}));
	});
});
