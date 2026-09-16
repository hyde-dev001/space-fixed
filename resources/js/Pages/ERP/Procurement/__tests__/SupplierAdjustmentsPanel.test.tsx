import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import Swal from "sweetalert2";
import SupplierAdjustmentsPanel from "../components/SupplierAdjustmentsPanel";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { PurchaseOrder } from "@/types/procurement";

vi.mock("sweetalert2", () => ({
	default: { fire: vi.fn().mockResolvedValue({ isConfirmed: true }) },
}));

vi.mock("@/services/purchaseOrderApi", () => ({
	purchaseOrderApi: {
		getSupplierAdjustments: vi.fn(),
		reportPostPaymentIssue: vi.fn(),
		submitSupplierRefundProof: vi.fn(),
		chooseSupplierAdjustmentResolution: vi.fn(),
		updateSupplierReplacement: vi.fn(),
		closeShortFulfillment: vi.fn(),
		updateSupplierReturn: vi.fn(),
		declineSupplierRefund: vi.fn(),
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
	post_payment_issue_items: [{
		receipt_id: 30,
		receipt_reference: "RCV-2026-0001",
		receipt_item_id: 40,
		purchase_order_item_id: 20,
		product_name: "Shoe cleaner",
		accepted_quantity: 2,
		remaining_quantity: 2,
	}],
} as PurchaseOrder;

describe("SupplierAdjustmentsPanel", () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([]);
		vi.mocked(purchaseOrderApi.reportPostPaymentIssue).mockResolvedValue({} as any);
		vi.mocked(purchaseOrderApi.submitSupplierRefundProof).mockResolvedValue({} as any);
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
		const thumbnail = screen.getByRole("img", { name: "Evidence: damage.jpg" });
		expect(thumbnail).toHaveAttribute("src", "/api/erp/procurement/supplier-adjustments/90/evidence/901");
		fireEvent.click(screen.getByRole("button", { name: /Evidence: damage.jpg/ }));
		expect(screen.getByRole("dialog", { name: "Supplier adjustment evidence" })).toBeInTheDocument();

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

	it("does not report a post-payment issue when confirmation is cancelled", async () => {
		vi.mocked(Swal.fire).mockResolvedValueOnce({ isConfirmed: false } as never);
		render(<SupplierAdjustmentsPanel order={order} canReport />);

		fireEvent.click(screen.getByRole("button", { name: "Report post-payment issue" }));
		fireEvent.change(screen.getByLabelText("Issue quantity"), { target: { value: "1" } });
		fireEvent.change(screen.getByLabelText("Issue category"), { target: { value: "wrong_item" } });
		fireEvent.change(screen.getByLabelText("Issue notes"), { target: { value: "Wrong variant found later." } });
		fireEvent.change(screen.getByLabelText("Issue evidence"), {
			target: { files: [new File(["proof"], "late-issue.jpg", { type: "image/jpeg" })] },
		});
		fireEvent.click(screen.getByRole("button", { name: "Submit supplier issue" }));

		await waitFor(() => expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({
			title: "Submit this supplier issue?",
			showCancelButton: true,
		})));
		expect(purchaseOrderApi.reportPostPaymentIssue).not.toHaveBeenCalled();
	});

	it("does not submit supplier refund proof when confirmation is cancelled", async () => {
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([{
			id: 91,
			issue_stage: "post_payment_issue",
			reported_quantity: 1,
			unit_cost_snapshot: "100.00",
			reason_category: "damaged",
			inventory_notes: "Found after payment.",
			status: "reported",
			resolution: "refund",
			return_status: "waived",
			purchase_order: { id: 10, number: "PO-10", status: "completed" },
			receipt: { id: 30, status: "posted" },
			receipt_item_id: 40,
		}] as any);
		vi.mocked(Swal.fire).mockResolvedValueOnce({ isConfirmed: false } as never);
		render(<SupplierAdjustmentsPanel order={order} canManage />);

		await screen.findByText(/Found after payment\./);
		fireEvent.click(screen.getByRole("button", { name: "Submit supplier refund proof" }));
		fireEvent.change(screen.getByLabelText("Expected refund amount"), { target: { value: "100.00" } });
		fireEvent.change(screen.getByLabelText("Supplier refund proof"), {
			target: { files: [new File(["proof"], "supplier-proof.pdf", { type: "application/pdf" })] },
		});
		fireEvent.click(screen.getByRole("button", { name: "Save supplier proof" }));

		await waitFor(() => expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({
			title: "Submit supplier refund proof?",
			showCancelButton: true,
		})));
		expect(purchaseOrderApi.submitSupplierRefundProof).not.toHaveBeenCalled();
	});

	it("lets Procurement choose a post-payment resolution before refund proof is available", async () => {
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([{
			id: 91,
			issue_stage: "post_payment_issue",
			reported_quantity: 1,
			unit_cost_snapshot: "100.00",
			reason_category: "damaged",
			inventory_notes: "Found after payment.",
			status: "reported",
			purchase_order: { id: 10, number: "PO-10", status: "completed" },
			receipt: { id: 30, status: "posted" },
			receipt_item_id: 40,
		}] as any);

		const { rerender } = render(<SupplierAdjustmentsPanel order={order} canReport />);
		await screen.findByText(/Found after payment\./);
		expect(screen.queryByRole("button", { name: "Submit supplier refund proof" })).not.toBeInTheDocument();

		rerender(<SupplierAdjustmentsPanel order={order} canManage />);
		expect(await screen.findByRole("button", { name: "Choose Replacement" })).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "Choose Refund" })).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Submit supplier refund proof" })).not.toBeInTheDocument();
	});

	it("hides post-payment reporting when the server has no paid reportable items", async () => {
		render(<SupplierAdjustmentsPanel order={{ ...order, post_payment_issue_items: [] }} canReport />);

		await screen.findByText("No supplier adjustments reported.");
		expect(screen.queryByRole("button", { name: "Report post-payment issue" })).not.toBeInTheDocument();
	});

	it("shows the supplier replacement decline reason and reference", async () => {
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([{
			id: 92,
			issue_stage: "receiving_defect",
			reported_quantity: 1,
			unit_cost_snapshot: "100.00",
			reason_category: "damaged",
			inventory_notes: "Damaged on arrival.",
			status: "resolution_in_progress",
			resolution: "replacement",
			replacement_status: "declined",
			decline_reason: "The requested variant is discontinued.",
			supplier_reference: "SUP-DECLINE-42",
			procurement_notes: "Close the remaining quantity as short fulfillment.",
			return_notes: "Supplier waived the physical return.",
			purchase_order: { id: 10, number: "PO-10", status: "completed" },
			receipt: { id: 30, status: "posted" },
			receipt_item_id: 40,
		}] as any);

		render(<SupplierAdjustmentsPanel order={order} canManage />);

		expect(await screen.findByText("The requested variant is discontinued.")).toBeInTheDocument();
		expect(screen.getByText("SUP-DECLINE-42")).toBeInTheDocument();
		expect(screen.getByText("Close the remaining quantity as short fulfillment.")).toBeInTheDocument();
		expect(screen.getByText("Supplier waived the physical return.")).toBeInTheDocument();
	});

	it("lets Inventory choose the exact paid receipt item on historical orders", async () => {
		const historicalOrder = {
			...order,
			post_payment_issue_items: [
				...order.post_payment_issue_items!,
				{
					receipt_id: 31,
					receipt_reference: "RCV-2026-0002",
					receipt_item_id: 41,
					purchase_order_item_id: 21,
					product_name: "Leather conditioner",
					accepted_quantity: 3,
					remaining_quantity: 3,
				},
			],
		} as PurchaseOrder;

		render(<SupplierAdjustmentsPanel order={historicalOrder} canReport />);
		await screen.findByText("No supplier adjustments reported.");
		fireEvent.click(screen.getByRole("button", { name: "Report post-payment issue" }));
		fireEvent.change(screen.getByLabelText("Paid receipt item"), { target: { value: "41" } });

		expect(screen.getByLabelText("Paid receipt item")).toHaveValue("41");
		expect(screen.getByText(/RCV-2026-0002 · Leather conditioner · 3 paid accepted/)).toBeInTheDocument();
		expect(screen.getByLabelText("Issue quantity")).toHaveAttribute("max", "3");
	});
});
