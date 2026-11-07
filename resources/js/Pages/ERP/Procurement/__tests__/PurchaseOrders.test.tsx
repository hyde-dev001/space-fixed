import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import PurchaseOrderReceiptPanel from "../components/PurchaseOrderReceiptPanel";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { PurchaseOrder } from "@/types/procurement";

vi.mock("@/services/purchaseOrderApi", () => ({ purchaseOrderApi: { receive: vi.fn(), finalizeReceipt: vi.fn(), voidReceipt: vi.fn(), getSupplierAdjustments: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn().mockResolvedValue({ isConfirmed: true }) } }));

const order = (overrides: Partial<PurchaseOrder> = {}) => ({
	id: 10, po_number: "PO-10", shop_owner_id: 1, supplier_id: 1, product_name: "Mixed items", quantity: 5,
	unit_cost: 100, total_cost: 500, payment_terms: "Net 30", status: "in_transit", ordered_by: 1,
	ordered_date: "2026-08-02", created_at: "2026-08-02", updated_at: "2026-08-02",
	items: [{ id: 20, purchase_order_id: 10, product_name: "Shoe cleaner", ordered_quantity: 5, accepted_quantity: 0, remaining_quantity: 5, unit_cost: 100, line_total: 500, quantity_multiplier: 1 }],
	receipts: [], ...overrides,
}) as PurchaseOrder;

describe("PurchaseOrderReceiptPanel", () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([]);
		vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue("123e4567-e89b-12d3-a456-426614174000");
	});

	it("automatically links the in-transit replacement through the canonical receipt", async () => {
		vi.mocked(purchaseOrderApi.receive).mockResolvedValue({} as any);
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([{
			id: 90,
			issue_stage: "post_payment_issue",
			reported_quantity: 1,
			unit_cost_snapshot: "100.00",
			reason_category: "damaged",
			inventory_notes: "Found after payment.",
			status: "resolution_in_progress",
			resolution: "replacement",
			replacement_status: "in_transit",
			purchase_order: { id: 10, number: "PO-10", status: "completed" },
			purchase_order_item_id: 20,
		}] as any);
		render(<PurchaseOrderReceiptPanel order={order({
			status: "completed",
			is_historical: true,
			items: [{
				id: 20, purchase_order_id: 10, product_name: "Shoe cleaner", ordered_quantity: 1,
				accepted_quantity: 1, remaining_quantity: 0, unit_cost: 100, line_total: 100, quantity_multiplier: 1,
			} as any],
		})} onChanged={vi.fn().mockResolvedValue(undefined)} />);

		const received = await screen.findByLabelText("Received Shoe cleaner");
		expect(screen.queryByText("Receipt type")).not.toBeInTheDocument();
		fireEvent.change(received, { target: { value: "1" } });
		fireEvent.click(screen.getByRole("button", { name: "Receive replacement" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, expect.objectContaining({
			items: [expect.objectContaining({ replacement_for_adjustment_id: 90 })],
		})));
	});

	it("does not let Inventory receive another post-payment replacement before it is in transit", async () => {
		vi.mocked(purchaseOrderApi.getSupplierAdjustments).mockResolvedValue([{
			id: 90,
			issue_stage: "post_payment_issue",
			reported_quantity: 6,
			unit_cost_snapshot: "100.00",
			reason_category: "damaged",
			inventory_notes: "Found after payment.",
			status: "resolution_in_progress",
			resolution: "replacement",
			replacement_status: "received",
			purchase_order: { id: 10, number: "PO-10", status: "completed" },
			purchase_order_item_id: 20,
		}] as any);

		render(<PurchaseOrderReceiptPanel order={order({ status: "completed", is_historical: true })} onChanged={vi.fn().mockResolvedValue(undefined)} />);

		await waitFor(() => expect(purchaseOrderApi.getSupplierAdjustments).toHaveBeenCalled());
		expect(screen.queryByText("Receipt type")).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Submit receiving result" })).not.toBeInTheDocument();
	});

	it("posts the line receipt with one idempotency key", async () => {
		vi.mocked(purchaseOrderApi.receive).mockResolvedValue({} as any);
		render(<PurchaseOrderReceiptPanel order={order()} onChanged={vi.fn().mockResolvedValue(undefined)} />);
		fireEvent.change(screen.getByLabelText("Received Shoe cleaner"), { target: { value: "5" } });
		fireEvent.click(screen.getByRole("button", { name: "Submit receiving result" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			notes: undefined,
			items: [{ purchase_order_item_id: 20, received_quantity: 5, defective_quantity: 0 }],
		}));
	});

	it("collects category, notes, and private image evidence for defective units", async () => {
		vi.mocked(purchaseOrderApi.receive).mockResolvedValue({} as any);
		render(<PurchaseOrderReceiptPanel order={order()} onChanged={vi.fn().mockResolvedValue(undefined)} />);
		fireEvent.change(screen.getByLabelText("Received Shoe cleaner"), { target: { value: "5" } });
		fireEvent.change(screen.getByLabelText("Defective Shoe cleaner"), { target: { value: "1" } });

		expect(screen.getByLabelText("Defect category Shoe cleaner")).toBeInTheDocument();
		expect(screen.getByLabelText("Defect notes Shoe cleaner")).toBeInTheDocument();
		expect(screen.getByLabelText("Defect evidence Shoe cleaner")).toBeInTheDocument();

		fireEvent.change(screen.getByLabelText("Defect category Shoe cleaner"), { target: { value: "damaged" } });
		fireEvent.change(screen.getByLabelText("Defect notes Shoe cleaner"), { target: { value: "Box was crushed." } });
		const proof = new File(["proof"], "damage.jpg", { type: "image/jpeg" });
		fireEvent.change(screen.getByLabelText("Defect evidence Shoe cleaner"), { target: { files: [proof] } });
		fireEvent.click(screen.getByRole("button", { name: "Submit receiving result" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			notes: undefined,
			items: [{
				purchase_order_item_id: 20,
				received_quantity: 5,
				defective_quantity: 1,
				reason_category: "damaged",
				inventory_notes: "Box was crushed.",
				defect_evidence: [proof],
			}],
		}));
	});

	it("retains quantities after a server error and hides void for migration receipts", async () => {
		vi.mocked(purchaseOrderApi.receive).mockRejectedValue(new Error("offline"));
		render(<PurchaseOrderReceiptPanel order={order({ receipts: [{ id: 1, purchase_order_id: 10, source: "migration", status: "posted", received_at: "2026-08-02", items: [] }] })} onChanged={vi.fn().mockResolvedValue(undefined)} />);
		const input = screen.getByLabelText("Received Shoe cleaner") as HTMLInputElement;
		fireEvent.change(input, { target: { value: "5" } });
		fireEvent.click(screen.getByRole("button", { name: "Submit receiving result" }));
		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalled());
		expect(input.value).toBe("5");
		expect(screen.queryByRole("button", { name: "Void" })).not.toBeInTheDocument();
	});

	it("posts exact quantities per snapshotted shoe size", async () => {
		vi.mocked(purchaseOrderApi.receive).mockResolvedValue({} as any);
		render(<PurchaseOrderReceiptPanel order={order({
			items: [{
				id: 20, purchase_order_id: 10, product_name: "Shoe", ordered_quantity: 5,
				accepted_quantity: 0, remaining_quantity: 5, unit_cost: 100, line_total: 500,
				quantity_multiplier: 1, eligible_size_ids: [71, 72],
				inventory_item: { sizes: [
					{ id: 71, size: "7", size_system: "US" },
					{ id: 72, size: "8", size_system: "US" },
				] },
			} as any],
		})} onChanged={vi.fn().mockResolvedValue(undefined)} />);

		fireEvent.change(screen.getByLabelText("Received Shoe US 7"), { target: { value: "2" } });
		fireEvent.change(screen.getByLabelText("Received Shoe US 8"), { target: { value: "3" } });
		fireEvent.click(screen.getByRole("button", { name: "Submit receiving result" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			notes: undefined,
			items: [{
				purchase_order_item_id: 20,
				received_quantity: 5,
				defective_quantity: 0,
				size_quantities: [
					{ inventory_size_id: 71, received_quantity: 2, defective_quantity: 0 },
					{ inventory_size_id: 72, received_quantity: 3, defective_quantity: 0 },
				],
			}],
		}));
	});

	it("posts the normalized physical total when receiving every eligible size", async () => {
		vi.mocked(purchaseOrderApi.receive).mockResolvedValue({} as any);
		const sizes = ["3", "5", "7", "9"].map((size, index) => ({
			id: 80 + index,
			size,
			size_system: "US",
		}));
		render(<PurchaseOrderReceiptPanel order={order({
			quantity: 200,
			total_cost: 20000,
			items: [{
				id: 20, purchase_order_id: 10, product_name: "Shoe", ordered_quantity: 200,
				accepted_quantity: 0, remaining_quantity: 200, unit_cost: 100, line_total: 20000,
				quantity_multiplier: 1, eligible_size_ids: sizes.map((size) => size.id),
				inventory_item: { sizes },
			} as any],
		})} onChanged={vi.fn().mockResolvedValue(undefined)} />);

		for (const size of sizes) {
			fireEvent.change(screen.getByLabelText(`Received Shoe US ${size.size}`), { target: { value: "50" } });
		}
		fireEvent.click(screen.getByRole("button", { name: "Submit receiving result" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			notes: undefined,
			items: [{
				purchase_order_item_id: 20,
				received_quantity: 200,
				defective_quantity: 0,
				size_quantities: sizes.map((size) => ({
					inventory_size_id: size.id,
					received_quantity: 50,
					defective_quantity: 0,
				})),
			}],
		}));
	});

	it("caps receiving inputs at the physical order quantity", async () => {
		const sizes = ["3", "5", "7", "9"].map((size, index) => ({
			id: 90 + index,
			size,
			size_system: "US",
		}));
		render(<PurchaseOrderReceiptPanel order={order({
			quantity: 320,
			total_cost: 32000,
			items: [{
				id: 20, purchase_order_id: 10, product_name: "Shoe", ordered_quantity: 160,
				accepted_quantity: 0, remaining_quantity: 160, unit_cost: 100, line_total: 16000,
				quantity_multiplier: 1, eligible_size_ids: sizes.map((size) => size.id),
				inventory_item: { sizes },
			} as any, {
				id: 21, purchase_order_id: 10, product_name: "Single shoe", ordered_quantity: 160,
				accepted_quantity: 0, remaining_quantity: 160, unit_cost: 100, line_total: 16000,
				quantity_multiplier: 1,
			} as any],
		})} onChanged={vi.fn().mockResolvedValue(undefined)} />);

		const received = screen.getByLabelText("Received Shoe US 3") as HTMLInputElement;
		expect(received).toHaveAttribute("max", "40");
		expect(screen.getByText(/40 each/)).toBeInTheDocument();
		fireEvent.change(received, { target: { value: "80" } });
		await waitFor(() => expect(received.value).toBe("40"));

		const singleItem = screen.getByLabelText("Received Single shoe") as HTMLInputElement;
		expect(singleItem).toHaveAttribute("max", "160");
		fireEvent.change(singleItem, { target: { value: "200" } });
		await waitFor(() => expect(singleItem.value).toBe("160"));
	});

	it("hides receiving and void actions when the user lacks those permissions", () => {
		render(<PurchaseOrderReceiptPanel
			order={order({ can_finalize: true, final_payable_quantity: 5, receipts: [{ id: 1, purchase_order_id: 10, source: "manual", status: "receiving", received_at: "2026-08-02", items: [] }] })}
			canReceive={false}
			canVoid={false}
			onChanged={vi.fn().mockResolvedValue(undefined)}
		/>);

		expect(screen.queryByRole("button", { name: "Submit receiving result" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Post Final Receipt" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Void" })).not.toBeInTheDocument();
	});
});
