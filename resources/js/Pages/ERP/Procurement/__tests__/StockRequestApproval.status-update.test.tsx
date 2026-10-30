import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import StockRequestApproval from "../StockRequestApproval";

const mocks = vi.hoisted(() => {
	const request = {
		id: 1,
		request_number: "SR-1",
		product_name: "Runner",
		sku_code: "RUN-1",
		quantity_needed: 10,
		priority: "medium",
		status: "pending",
		requested_by: 1,
		requested_date: "2026-09-16T08:00:00Z",
		created_at: "2026-09-16T08:00:00Z",
		updated_at: "2026-09-16T08:00:00Z",
		request_source: "manual",
		requester: { name: "Inventory Staff" },
	};

	return {
		request,
		approve: vi.fn(),
		confirm: vi.fn(),
	};
});

vi.mock("@inertiajs/react", () => ({
	Head: () => null,
	usePage: () => ({
		props: {
			auth: { erpActor: { ownerMode: false } },
			initialData: { data: [mocks.request] },
		},
	}),
}));
vi.mock("@/layout/AppLayout_ERP", () => ({ default: ({ children }: any) => <>{children}</> }));
vi.mock("@/services/stockRequestApi", () => ({
	stockRequestApi: {
		approve: mocks.approve,
		reject: vi.fn(),
		getAll: vi.fn(),
		getMetrics: vi.fn(),
	},
}));
vi.mock("@/utils/workflowFeedback", () => ({
	workflowFeedback: {
		confirm: mocks.confirm,
		success: vi.fn().mockResolvedValue({}),
		warning: vi.fn().mockResolvedValue({}),
		error: vi.fn().mockResolvedValue({}),
		errorWithRetry: vi.fn().mockResolvedValue(false),
		alert: vi.fn(),
	},
}));

describe("Procurement stock request approval", () => {
	it("updates the seeded row and metrics immediately after approval", async () => {
		mocks.confirm.mockResolvedValueOnce({ isConfirmed: true });
		mocks.approve.mockResolvedValueOnce({ ...mocks.request, status: "accepted" });

		render(<StockRequestApproval />);
		fireEvent.click(screen.getByTitle("View request details"));
		fireEvent.click(screen.getByRole("button", { name: "Approve" }));

		await waitFor(() => expect(mocks.approve).toHaveBeenCalledWith(1));
		const row = screen.getByText("Runner").closest("tr");
		await waitFor(() => expect(within(row!).getByText("Approved")).toBeInTheDocument());
		expect(screen.getByText("Pending Review").parentElement).toHaveTextContent("0");
		expect(screen.getByText("Approved Requests").parentElement).toHaveTextContent("1");
	});
});
