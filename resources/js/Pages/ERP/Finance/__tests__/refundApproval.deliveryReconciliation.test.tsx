import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import RefundApproval from "../refundApproval";

const mocks = vi.hoisted(() => ({
	fetch: vi.fn(),
	swal: vi.fn(),
	canCredit: true,
}));

vi.mock("@inertiajs/react", () => ({
	Head: () => null,
	usePage: () => ({
		props: {
			auth: {
				user: { role: "Finance" },
				permissions: ["access-refund-approval"],
			},
		},
	}),
}));

vi.mock("sweetalert2", () => ({
	default: { fire: mocks.swal },
}));

const jsonResponse = (data: unknown, status = 200) =>
	Promise.resolve({
		ok: status >= 200 && status < 300,
		status,
		json: async () => data,
	});

beforeEach(() => {
	vi.clearAllMocks();
	mocks.canCredit = true;
	mocks.swal.mockResolvedValue({ isConfirmed: true });
	mocks.fetch.mockImplementation((url: string, options?: RequestInit) => {
		if (url.startsWith("/api/finance/refunds?")) {
			return jsonResponse({ data: [] });
		}
		if (url.startsWith("/api/finance/repair-refunds?")) {
			return jsonResponse({ data: [] });
		}
		if (url.startsWith("/api/finance/repair-delivery-reconciliations?")) {
			return jsonResponse({
				data: [
					{
						repair_id: 77,
						request_id: "REP-77",
						customer_name: "Rina Santos",
						compensation_key: "intake:version:lock",
						phase: "intake",
						reason: "intake_outside_coverage",
						amount: 212,
						status: "pending",
						can_credit_balance: mocks.canCredit,
						created_at: "2026-07-26T08:00:00.000Z",
					},
				],
			});
		}
		if (url === "/api/finance/repair-delivery-reconciliations/77/resolve" && options?.method === "POST") {
			return jsonResponse({ success: true, data: { status: "resolved" } });
		}
		throw new Error(`Unexpected fetch ${url}`);
	});
	vi.stubGlobal("fetch", mocks.fetch);
});

afterEach(() => {
	cleanup();
	vi.unstubAllGlobals();
});

describe("refund approval delivery reconciliation", () => {
	it("shows the affected leg clearly and resolves it as a service-balance credit", async () => {
		render(<RefundApproval />);

		expect(await screen.findByRole("heading", { name: "Delivery fee adjustments" })).toBeInTheDocument();
		expect(screen.getByText("REP-77")).toBeInTheDocument();
		expect(screen.getByText("Rina Santos")).toBeInTheDocument();
		expect(screen.getByText("Pickup delivery")).toBeInTheDocument();
		expect(screen.getByText("₱212.00")).toBeInTheDocument();

		fireEvent.click(screen.getByRole("button", { name: "Credit balance" }));

		await waitFor(() => expect(mocks.fetch).toHaveBeenCalledWith(
			"/api/finance/repair-delivery-reconciliations/77/resolve",
			expect.objectContaining({
				method: "POST",
				body: JSON.stringify({
					compensation_key: "intake:version:lock",
					action: "credit_balance",
				}),
			}),
		));
	});

	it("disables credit when it cannot be exact but keeps original-channel refund available", async () => {
		mocks.canCredit = false;
		render(<RefundApproval />);

		expect(await screen.findByRole("button", { name: "Credit balance" })).toBeDisabled();
		expect(screen.getByText("The remaining service balance is lower than this fee.")).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "Refund original channel" })).toBeEnabled();
	});
});
