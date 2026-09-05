import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import PurchaseOrders from "../PurchaseOrders";

const mocks = vi.hoisted(() => {
	const order = {
		id: 10,
		po_number: "PO-10",
		shop_owner_id: 1,
		supplier_id: 1,
		product_name: "Shoe cleaner",
		quantity: 200,
		unit_cost: 100,
		total_cost: 20000,
		requested_size: "",
		inventory_item: { category: "shoes" },
		payment_terms: "COD",
		status: "delivered",
		ordered_by: 1,
		ordered_date: "2026-08-02",
		created_at: "2026-08-02",
		updated_at: "2026-08-02",
		items: [],
		receipts: [],
	};

	return { order, approvedPrs: [] as any[], permissions: ["procurement.complete_purchase_orders"] };
});

vi.mock("@inertiajs/react", () => ({
	Head: () => null,
	usePage: () => ({
		props: {
			initialData: { data: [mocks.order] },
			initialApprovedPRs: mocks.approvedPrs,
			auth: { permissions: mocks.permissions },
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
vi.mock("@/services/purchaseRequestApi", () => ({ purchaseRequestApi: { getApproved: vi.fn(() => Promise.resolve(mocks.approvedPrs)) } }));
vi.mock("../components/PurchaseOrderReceiptPanel", () => ({ default: () => null }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

describe("Purchase Orders lifecycle actions", () => {
	beforeEach(() => {
		mocks.approvedPrs.length = 0;
		mocks.order.status = "delivered";
		mocks.permissions.splice(0, mocks.permissions.length, "procurement.complete_purchase_orders");
	});

	it("allows an authorized user to complete a delivered order", async () => {
		render(<PurchaseOrders />);
		fireEvent.click(await screen.findByTitle("View details"));

		expect(screen.getByRole("button", { name: "Mark as Completed" })).toBeInTheDocument();
	});

	it("shows delivered orders in the awaiting-closure category", () => {
		render(<PurchaseOrders />);

		expect(screen.getByText("Awaiting Closure")).toBeInTheDocument();
		expect(screen.getByText("Delivered orders awaiting explicit administrative closure")).toBeInTheDocument();
	});

	it("counts partially received orders as active receiving work", () => {
		mocks.order.status = "partially_received";

		render(<PurchaseOrders />);

		expect(screen.getByText("Active Receiving")).toBeInTheDocument();
		expect(screen.getByText("Sent, confirmed, in-transit, or partially received orders")).toBeInTheDocument();
	});

	it("hides create when the user lacks create permission", async () => {
		render(<PurchaseOrders />);
		await screen.findByTitle("View details");

		expect(screen.queryByRole("button", { name: "+ New PO" })).not.toBeInTheDocument();
	});

	it("hides cancellation for an in-transit order", async () => {
		mocks.order.status = "in_transit";
		mocks.permissions.splice(0, mocks.permissions.length, "procurement.cancel_purchase_orders");

		render(<PurchaseOrders />);
		fireEvent.click(await screen.findByTitle("View details"));

		expect(screen.queryByRole("button", { name: "Cancel PO" })).not.toBeInTheDocument();
	});

	it("shows cancellation for a confirmed order", async () => {
		mocks.order.status = "confirmed";
		mocks.permissions.splice(0, mocks.permissions.length, "procurement.cancel_purchase_orders");

		render(<PurchaseOrders />);
		fireEvent.click(await screen.findByTitle("View details"));

		expect(screen.getByRole("button", { name: "Cancel PO" })).toBeInTheDocument();
	});

	it("describes an all-size PR quantity as one total in the PO selector", async () => {
		mocks.permissions.push("procurement.create_purchase_orders");
		mocks.approvedPrs.push({
			id: 20,
			pr_number: "PR-20",
			product_name: "Runner",
			quantity: 200,
			unit_cost: 100,
			total_cost: 820000,
			requested_size: "",
			inventory_item: { category: "shoes" },
		});

		render(<PurchaseOrders />);
		fireEvent.click(await screen.findByRole("button", { name: "+ New PO" }));

		expect(await screen.findByRole("option", { name: /Qty: 200 total units across All Sizes/ })).toBeInTheDocument();
	});

	it("labels the PO detail quantity as a total across all sizes", async () => {
		render(<PurchaseOrders />);
		fireEvent.click(await screen.findByTitle("View details"));

		expect(screen.getByText("Total Quantity Ordered Across All Sizes:")).toBeInTheDocument();
		expect(screen.getByText("200 units")).toBeInTheDocument();
	});
});
