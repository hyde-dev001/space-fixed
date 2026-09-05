import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import Expense from "../Expense";

const mocks = vi.hoisted(() => ({
	refetch: vi.fn(),
	approve: vi.fn(),
	reject: vi.fn(),
	ownerMode: false,
}));

vi.mock("@inertiajs/react", () => ({
	usePage: () => ({ props: { auth: { erpActor: { ownerMode: mocks.ownerMode } } } }),
}));
vi.mock("react-apexcharts", () => ({ default: () => null }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));
vi.mock("../../../../hooks/useFinanceApi", () => ({
	useFinanceApi: () => ({ delete: vi.fn(), post: vi.fn() }),
}));
vi.mock("../../../../hooks/useFinanceQueries", () => ({
	useExpenses: () => ({
		data: [{
			id: "expense-1",
			date: "2026-08-09",
			category: "Procurement",
			description: "Receipt for purchase order PO-2026-003",
			amount: 1020000,
			status: "submitted",
			procurement_details: {
				receipt_id: 303,
				po_number: "PO-2026-003",
				supplier_name: "Supplier",
			},
		}],
		isLoading: false,
		refetch: mocks.refetch,
	}),
	useTaxRates: () => ({ data: [], isLoading: false }),
	useApproveExpense: () => ({ isPending: false, mutateAsync: mocks.approve }),
	useRejectExpense: () => ({ isPending: false, mutateAsync: mocks.reject }),
}));

beforeEach(() => {
	vi.clearAllMocks();
	mocks.ownerMode = false;
	mocks.refetch.mockResolvedValue(undefined);
});

afterEach(() => {
	cleanup();
});

describe("Finance procurement expenses", () => {
	it("shows the receipt for review without approval actions", () => {
		render(<Expense />);

		expect(screen.getByText("Receipt for purchase order PO-2026-003")).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "View expense" })).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Approve expense" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Reject expense" })).not.toBeInTheDocument();
		expect(mocks.approve).not.toHaveBeenCalled();
		expect(mocks.reject).not.toHaveBeenCalled();
	});

	it("hides expense creation from the shop owner while keeping the page readable", () => {
		mocks.ownerMode = true;

		render(<Expense />);

		expect(screen.getByText("Review team spending across the ERP suite.")).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Add Expense" })).not.toBeInTheDocument();
	});
});
