import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import StockRequest from "../StockRequest";

const request = vi.hoisted(() => ({
	id: 1,
	request_number: "SR-1",
	product_name: "Runner",
	sku_code: "RUN-1",
	quantity_needed: 50,
	requested_size: "",
	requested_color: "Black",
	priority: "medium",
	status: "accepted",
	requested_by: 1,
	requested_date: "2026-08-02",
	created_at: "2026-08-02",
	updated_at: "2026-08-02",
	inventory_item: {
		category: "shoes",
		sizes: [
			{ size: "3", size_system: "US", inventory_color_variant_id: 10 },
			{ size: "5", size_system: "US", inventory_color_variant_id: 10 },
		],
		color_variants: [{ id: 10, color_name: "Black" }],
	},
}));

vi.mock("@inertiajs/react", () => ({
	Head: () => null,
	usePage: () => ({ props: { auth: { user: { id: 1, shop_owner_id: 1 } }, initialRequests: { data: [request] }, initialInventoryItems: { data: [] } } }),
}));
vi.mock("@/layout/AppLayout_ERP", () => ({ default: ({ children }: any) => <>{children}</> }));
vi.mock("@/services/stockRequestApi", () => ({
	stockRequestApi: { getAllForInventory: vi.fn().mockResolvedValue({ data: [request] }) },
}));
vi.mock("@/services/inventoryAPI", () => ({ inventoryItemAPI: { getAll: vi.fn().mockResolvedValue({ data: [] }) } }));

describe("Stock Request details", () => {
	it("shows one total quantity and the included sizes for an all-size request", async () => {
		render(<StockRequest />);
		fireEvent.click(await screen.findByTitle("View request details"));

		expect(screen.getByText("Total Quantity Across All Sizes")).toBeInTheDocument();
		expect(screen.getByText("50 units")).toBeInTheDocument();
		expect(screen.getByText("US 3, US 5")).toBeInTheDocument();
	});
});
