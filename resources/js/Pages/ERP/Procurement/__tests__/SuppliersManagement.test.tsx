import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SuppliersManagement from "../SuppliersManagement";

const mocks = vi.hoisted(() => ({
	getAll: vi.fn(),
	getPaymentProfile: vi.fn(),
	upsertPaymentProfile: vi.fn(),
	suppliers: [] as Array<Record<string, unknown>>,
}));

vi.mock("@inertiajs/react", () => ({
	Head: ({ children }: React.PropsWithChildren) => <>{children}</>,
	usePage: () => ({
		props: {
			initialData: { data: mocks.suppliers },
			auth: { erpActor: { ownerMode: false } },
			erpCapabilities: {},
		},
	}),
}));

vi.mock("@/layout/AppLayout_ERP", () => ({
	default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

vi.mock("@/services/procurementApi", () => ({
	supplierApi: {
		getAll: mocks.getAll,
		getPaymentProfile: mocks.getPaymentProfile,
		upsertPaymentProfile: mocks.upsertPaymentProfile,
	},
}));

vi.mock("@/utils/erpCapabilities", () => ({ erpUrl: () => null }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

describe("SuppliersManagement payment-term fields", () => {
	beforeEach(() => {
		mocks.suppliers = [];
		mocks.getAll.mockResolvedValue({ data: [] });
		mocks.getPaymentProfile.mockResolvedValue(null);
		mocks.upsertPaymentProfile.mockResolvedValue({});
	});

	it("exposes supplier profile fields and only supported payment terms", async () => {
		render(<SuppliersManagement />);

		fireEvent.click(await screen.findByRole("button", { name: "+ Add Supplier" }));

		expect(screen.getAllByText("City").length).toBeGreaterThan(0);
		expect(screen.getAllByText("Country").length).toBeGreaterThan(0);
		expect(screen.getAllByText("Lead Time (days)").length).toBeGreaterThan(0);
		expect(screen.getAllByText("Products Supplied").length).toBeGreaterThan(0);
		expect(screen.getAllByRole("option", { name: "COD" }).length).toBeGreaterThan(0);
		expect(screen.getAllByRole("option", { name: "Net 60" }).length).toBeGreaterThan(0);
		 expect(screen.queryByRole("option", { name: "Net 90" })).not.toBeInTheDocument();
	});

	it("loads and edits the masked supplier payment profile without exposing the account number", async () => {
		mocks.suppliers = [{
			id: 7,
			name: "Alpha Supplier",
			is_active: true,
			purchase_order_count: 0,
		}];
		mocks.getAll.mockResolvedValue({ data: mocks.suppliers });
		mocks.getPaymentProfile.mockResolvedValue({
			id: 8,
			destination_type: "bank_account",
			bank_name: "Test Bank",
			bank_code: "TBK",
			account_name: "Alpha Supplier",
			masked_account_number: "******7890",
			status: "verified",
		});

		render(<SuppliersManagement />);
		fireEvent.click(await screen.findByRole("button", { name: "Edit Alpha Supplier" }));

		expect(await screen.findByText("Payment Profile")).toBeInTheDocument();
		await waitFor(() => expect(mocks.getPaymentProfile).toHaveBeenCalledWith(7));
		expect(await screen.findByText(/Account: \*{6}7890/)).toBeInTheDocument();
		expect(screen.getByLabelText("Account Number")).toHaveValue("");
		expect(screen.queryByDisplayValue("1234567890")).not.toBeInTheDocument();
	});
});
