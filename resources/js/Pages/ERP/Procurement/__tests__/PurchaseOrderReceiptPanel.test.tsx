import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import PurchaseOrderReceiptPanel from "../components/PurchaseOrderReceiptPanel";
import type { PurchaseOrder } from "@/types/procurement";

vi.mock("@/services/purchaseOrderApi", () => ({ purchaseOrderApi: { receive: vi.fn(), voidReceipt: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

describe("PurchaseOrderReceiptPanel size labels", () => {
	it("visibly identifies every multi-size receiving input", () => {
		const order = {
			id: 10, po_number: "PO-10", shop_owner_id: 1, supplier_id: 1, product_name: "Runner", quantity: 5,
			unit_cost: 100, total_cost: 500, payment_terms: "COD", status: "in_transit", ordered_by: 1,
			ordered_date: "2026-08-02", created_at: "2026-08-02", updated_at: "2026-08-02", receipts: [],
			items: [{
				id: 20, purchase_order_id: 10, product_name: "Runner", ordered_quantity: 5,
				accepted_quantity: 0, remaining_quantity: 5, unit_cost: 100, line_total: 500,
				quantity_multiplier: 1, eligible_size_ids: [31, 35],
				inventory_item: { sizes: [
					{ id: 31, size: "3", size_system: "US" },
					{ id: 35, size: "5", size_system: "US" },
				] },
			}],
		} as PurchaseOrder;

		render(<PurchaseOrderReceiptPanel order={order} canReceive canVoid={false} onChanged={vi.fn().mockResolvedValue(undefined)} />);

		expect(screen.getAllByText("US 3")).toHaveLength(2);
		expect(screen.getAllByText("US 5")).toHaveLength(2);
		expect(screen.getByText("US 3, US 5 · 3 each")).toBeInTheDocument();
		expect(screen.getByRole("spinbutton", { name: "Received Runner US 3" })).toHaveAttribute("max", "3");
		expect(screen.getByRole("spinbutton", { name: "Defective Runner US 3" })).toBeInTheDocument();
	});
});
