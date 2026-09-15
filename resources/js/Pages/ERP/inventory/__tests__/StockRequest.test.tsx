import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";
import StockRequest from "../StockRequest";

const source = readFileSync(resolve("resources/js/Pages/ERP/inventory/StockRequest.tsx"), "utf8");

const mocks = vi.hoisted(() => ({
	createFromInventory: vi.fn(),
	request: {
	id: 1,
	request_number: "SR-1",
	product_name: "Runner",
	sku_code: "RUN-1",
	quantity_needed: 200,
	requested_size: "",
	requested_color: "Black",
	priority: "medium",
	status: "accepted",
	requested_by: 1,
	requested_date: "2026-08-02",
	created_at: "2026-08-02",
	updated_at: "2026-08-02",
	is_auto_generated: true,
	source_label: "Automatic Stock Request",
	source_reason: "Stock reached reorder level",
	inventory_item: {
		category: "shoes",
		sizes: [
			{ size: "3", size_system: "US", inventory_color_variant_id: 10 },
			{ size: "5", size_system: "US", inventory_color_variant_id: 10 },
		],
		color_variants: [{ id: 10, color_name: "Black" }],
	},
},
	inventoryItem: {
		id: 2,
		name: "Create Runner",
		sku: "CREATE-RUNNER",
		category: "shoes",
		unit: "pairs",
		available_quantity: 0,
		sizes: [
			{ id: 21, size: "3", size_system: "US", quantity: 0 },
			{ id: 22, size: "5", size_system: "US", quantity: 0 },
			{ id: 23, size: "7", size_system: "US", quantity: 0 },
			{ id: 24, size: "9", size_system: "US", quantity: 0 },
		],
		color_variants: [],
	},
}));

vi.mock("@inertiajs/react", () => ({
	Head: () => null,
	usePage: () => ({ props: { auth: { user: { id: 1, shop_owner_id: 1 } }, initialRequests: { data: [mocks.request] }, initialInventoryItems: { data: [mocks.inventoryItem] } } }),
}));
vi.mock("@/layout/AppLayout_ERP", () => ({ default: ({ children }: any) => <>{children}</> }));
vi.mock("@/services/stockRequestApi", () => ({
	stockRequestApi: {
		getAllForInventory: vi.fn().mockResolvedValue({ data: [mocks.request] }),
		createFromInventory: mocks.createFromInventory,
	},
}));
vi.mock("@/services/inventoryAPI", () => ({ inventoryItemAPI: { getAll: vi.fn().mockResolvedValue({ data: [mocks.inventoryItem] }) } }));
vi.mock("@/utils/workflowFeedback", () => ({
	workflowFeedback: {
		warning: vi.fn().mockResolvedValue({}),
		error: vi.fn().mockResolvedValue({}),
		success: vi.fn().mockResolvedValue({}),
		confirm: vi.fn().mockResolvedValue({ isConfirmed: false }),
	},
}));

describe("Stock Request details", () => {
	 beforeEach(() => {
		mocks.createFromInventory.mockReset();
		mocks.createFromInventory.mockResolvedValue(mocks.request);
	});

	it("shows one total quantity and the included sizes for an all-size request", async () => {
		render(<StockRequest />);
		fireEvent.click(await screen.findByTitle("View request details"));

		expect(screen.getByText("Total Quantity (All Sizes)")).toBeInTheDocument();
		expect(screen.getByText("200 units")).toBeInTheDocument();
		expect(screen.getByText("US 3, US 5")).toBeInTheDocument();
	});

	it("renders the server-provided automatic request source", async () => {
		render(<StockRequest />);

		expect(await screen.findByText("Automatic Stock Request")).toBeInTheDocument();
		expect(screen.getByText("Stock reached reorder level")).toBeInTheDocument();
	});

	it("previews the physical total and submits per-size basis for a new all-size request", async () => {
		render(<StockRequest />);
		fireEvent.click(await screen.findByRole("button", { name: "+ New Request" }));
		fireEvent.click(screen.getByRole("button", { name: /Select a product/ }));
		fireEvent.click(screen.getByRole("button", { name: /Create Runner/ }));

		fireEvent.change(screen.getByRole("spinbutton"), { target: { value: "50" } });
		expect(screen.getByText("4 eligible sizes x 50 = 200 total units")).toBeInTheDocument();

		fireEvent.change(screen.getByPlaceholderText("Add request reason, size breakdown, or branch allocation..."), {
			target: { value: "Restock all shoe sizes." },
		});
		fireEvent.click(screen.getByRole("button", { name: "Submit Request" }));

		await waitFor(() => expect(mocks.createFromInventory).toHaveBeenCalledWith(expect.objectContaining({
			quantity_needed: 50,
			quantity_basis: "per_size",
		})));
	});

	it("keeps stock request UI surfaces neutral without changing their values", () => {
		const createModal = source.slice(source.indexOf("{/* ── Create Request Modal ── */}"), source.indexOf("{viewingRequest && ("));
		const detailsModal = source.slice(source.indexOf("{viewingRequest && ("), source.indexOf("{/* ── Product Picker Modal ── */}"));
		const productPicker = source.slice(source.indexOf("{/* ── Product Picker Modal ── */}"));

		expect(createModal).toContain("border border-gray-200 bg-gray-50");
		expect(createModal).toContain("dark:border-gray-700 dark:bg-gray-800/40");
		expect(createModal).toContain("Current stock:");
		expect(createModal).toContain("Variant stock:");
		expect(createModal).not.toContain("bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 px-3 py-2 text-sm");
		expect(createModal).not.toContain("bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-sm");

		expect(detailsModal).toContain("rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900");
		expect(detailsModal).toContain("Requested Size");
		expect(detailsModal).toContain("Available Sizes");
		expect(detailsModal).toContain("Requested Color");
		expect(detailsModal).not.toContain("bg-indigo-50");
		expect(detailsModal).not.toContain("bg-blue-50");
		expect(detailsModal).not.toContain("bg-purple-50");

		expect(productPicker).toContain("hover:bg-gray-50 focus:bg-gray-50");
		expect(productPicker).toContain("dark:hover:bg-gray-800/60 dark:focus:bg-gray-800/60");
		expect(productPicker).toContain("border border-gray-950 bg-gray-950 px-3 py-1 dark:border-gray-300 dark:bg-gray-100");
		expect(productPicker).toContain("text-xs font-semibold text-white dark:text-gray-900");
		expect(productPicker).not.toContain("hover:bg-blue-100");
		expect(productPicker).not.toContain("bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800");
	});
});
