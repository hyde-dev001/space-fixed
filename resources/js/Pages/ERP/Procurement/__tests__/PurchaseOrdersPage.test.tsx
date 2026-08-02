import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import PurchaseOrders from "../PurchaseOrders";

const mocks = vi.hoisted(() => {
	const order = {
		id: 10,
		po_number: "PO-10",
		shop_owner_id: 1,
		supplier_id: 1,
		product_name: "Shoe cleaner",
		quantity: 1,
		unit_cost: 100,
		total_cost: 100,
		payment_terms: "COD",
		status: "delivered",
		ordered_by: 1,
		ordered_date: "2026-08-02",
		created_at: "2026-08-02",
		updated_at: "2026-08-02",
		items: [],
		receipts: [],
	};

	return { order };
});

vi.mock("@inertiajs/react", () => ({
	Head: () => null,
	usePage: () => ({
		props: {
			initialData: { data: [mocks.order] },
			initialApprovedPRs: [],
			auth: { permissions: ["procurement.complete_purchase_orders"] },
		},
	}),
}));
vi.mock("@/layout/AppLayout_ERP", () => ({ default: ({ children }: any) => <>{children}</> }));
vi.mock("@/services/purchaseOrderApi", () => ({
	purchaseOrderApi: {
		getAll: vi.fn().mockResolvedValue({ data: [mocks.order] }),
		getMetrics: vi.fn().mockResolvedValue({ total_purchase_orders: 1, active_orders: 0, completed_orders: 0 }),
		getById: vi.fn().mockResolvedValue(mocks.order),
		updateStatus: vi.fn(),
		create: vi.fn(),
		cancel: vi.fn(),
	},
}));
vi.mock("@/services/purchaseRequestApi", () => ({ purchaseRequestApi: { getApproved: vi.fn().mockResolvedValue([]) } }));
vi.mock("../components/PurchaseOrderReceiptPanel", () => ({ default: () => null }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

describe("Purchase Orders lifecycle actions", () => {
	it("allows an authorized user to complete a delivered order", async () => {
		render(<PurchaseOrders />);
		fireEvent.click(await screen.findByTitle("View details"));

		expect(screen.getByRole("button", { name: "Mark as Completed" })).toBeInTheDocument();
	});

	it("hides create when the user lacks create permission", async () => {
		render(<PurchaseOrders />);
		await screen.findByTitle("View details");

		expect(screen.queryByRole("button", { name: "+ New PO" })).not.toBeInTheDocument();
	});
});
