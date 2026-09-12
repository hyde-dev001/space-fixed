import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import PurchaseOrderReceiptPanel from "../components/PurchaseOrderReceiptPanel";
import { purchaseOrderApi } from "@/services/purchaseOrderApi";
import type { PurchaseOrder } from "@/types/procurement";

vi.mock("@/services/purchaseOrderApi", () => ({ purchaseOrderApi: { receive: vi.fn(), voidReceipt: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn().mockResolvedValue({ isConfirmed: true }) } }));

const order = (overrides: Partial<PurchaseOrder> = {}) => ({
	id: 10, po_number: "PO-10", shop_owner_id: 1, supplier_id: 1, product_name: "Mixed items", quantity: 5,
	unit_cost: 100, total_cost: 500, payment_terms: "COD", status: "in_transit", ordered_by: 1,
	ordered_date: "2026-08-02", created_at: "2026-08-02", updated_at: "2026-08-02",
	items: [{ id: 20, purchase_order_id: 10, product_name: "Shoe cleaner", ordered_quantity: 5, accepted_quantity: 0, remaining_quantity: 5, unit_cost: 100, line_total: 500, quantity_multiplier: 1 }],
	receipts: [], ...overrides,
}) as PurchaseOrder;

describe("PurchaseOrderReceiptPanel", () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue("123e4567-e89b-12d3-a456-426614174000");
	});

	it("posts the line receipt with one idempotency key", async () => {
		vi.mocked(purchaseOrderApi.receive).mockResolvedValue({} as any);
		render(<PurchaseOrderReceiptPanel order={order()} onChanged={vi.fn().mockResolvedValue(undefined)} />);
		fireEvent.change(screen.getByLabelText("Received Shoe cleaner"), { target: { value: "3" } });
		fireEvent.change(screen.getByLabelText("Defective Shoe cleaner"), { target: { value: "1" } });
		fireEvent.click(screen.getByRole("button", { name: "Post receipt" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			notes: undefined,
			items: [{ purchase_order_item_id: 20, received_quantity: 3, defective_quantity: 1 }],
		}));
	});

	it("retains quantities after a server error and hides void for migration receipts", async () => {
		vi.mocked(purchaseOrderApi.receive).mockRejectedValue(new Error("offline"));
		render(<PurchaseOrderReceiptPanel order={order({ receipts: [{ id: 1, purchase_order_id: 10, source: "migration", status: "posted", received_at: "2026-08-02", items: [] }] })} onChanged={vi.fn().mockResolvedValue(undefined)} />);
		const input = screen.getByLabelText("Received Shoe cleaner") as HTMLInputElement;
		fireEvent.change(input, { target: { value: "2" } });
		fireEvent.click(screen.getByRole("button", { name: "Post receipt" }));
		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalled());
		expect(input.value).toBe("2");
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
		fireEvent.change(screen.getByLabelText("Defective Shoe US 8"), { target: { value: "1" } });
		fireEvent.click(screen.getByRole("button", { name: "Post receipt" }));

		await waitFor(() => expect(purchaseOrderApi.receive).toHaveBeenCalledWith(10, {
			idempotency_key: "123e4567-e89b-12d3-a456-426614174000",
			notes: undefined,
			items: [{
				purchase_order_item_id: 20,
				received_quantity: 5,
				defective_quantity: 1,
				size_quantities: [
					{ inventory_size_id: 71, received_quantity: 2, defective_quantity: 0 },
					{ inventory_size_id: 72, received_quantity: 3, defective_quantity: 1 },
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
		fireEvent.click(screen.getByRole("button", { name: "Post receipt" }));

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

	it("hides receiving and void actions when the user lacks those permissions", () => {
		render(<PurchaseOrderReceiptPanel
			order={order({ receipts: [{ id: 1, purchase_order_id: 10, source: "manual", status: "posted", received_at: "2026-08-02", items: [] }] })}
			canReceive={false}
			canVoid={false}
			onChanged={vi.fn().mockResolvedValue(undefined)}
		/>);

		expect(screen.queryByRole("button", { name: "Post receipt" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Void" })).not.toBeInTheDocument();
	});
});
