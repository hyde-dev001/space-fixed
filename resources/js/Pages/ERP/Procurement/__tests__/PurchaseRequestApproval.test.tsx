import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import PurchaseRequestApproval, { financeApprovalPrompt, isFinanceReviewStatus } from "../../Finance/PurchaseRequestApproval";

const { allSizeRequest } = vi.hoisted(() => ({ allSizeRequest: {
	id: 50,
	pr_number: "PR-50",
	product_name: "Chikawa",
	quantity: 50,
	unit_cost: 5100,
	total_cost: 255000,
	priority: "medium",
	status: "pending_finance",
	requested_size: null,
	requested_color: "Black",
	justification: "Restock all available sizes.",
	supplier: { name: "Chikawa" },
	inventory_item: { category: "shoes", sizes: [] },
} as any }));

vi.mock("@inertiajs/react", () => ({ Head: () => null, usePage: () => ({ props: { auth: {} } }) }));
vi.mock("axios", () => ({ default: { get: vi.fn().mockResolvedValue({ data: { data: [allSizeRequest] } }), post: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

describe("Finance purchase request stages", () => {
	it("keeps initial and final Finance reviews distinct", () => {
		expect(isFinanceReviewStatus("pending_finance")).toBe(true);
		expect(financeApprovalPrompt("pending_finance", "PR-1")).toContain("send to Shop Owner");
		expect(isFinanceReviewStatus("pending_finance_final")).toBe(true);
		expect(financeApprovalPrompt("pending_finance_final", "PR-1")).toContain("after Shop Owner approval");
		expect(isFinanceReviewStatus("pending_shop_owner")).toBe(false);
	});

	it("labels an all-size quantity as one physical total", async () => {
		render(<PurchaseRequestApproval requests={[allSizeRequest]} />);
		fireEvent.click(await screen.findByTitle("View details"));

		expect(screen.getByText("Total Quantity Across All Sizes")).toBeInTheDocument();
		expect(screen.getByText("50 units")).toBeInTheDocument();
	});
});
