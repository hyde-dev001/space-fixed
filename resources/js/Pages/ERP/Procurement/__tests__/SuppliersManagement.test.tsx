import React from "react";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SuppliersManagement from "../SuppliersManagement";

const mocks = vi.hoisted(() => ({
	getAll: vi.fn(),
	create: vi.fn(),
	update: vi.fn(),
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
		create: mocks.create,
		update: mocks.update,
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
		mocks.create.mockResolvedValue({ data: {} });
		mocks.update.mockResolvedValue({ data: {} });
		mocks.getPaymentProfile.mockResolvedValue(null);
		mocks.upsertPaymentProfile.mockResolvedValue({});
	});

	it("submits an explicit e-wallet profile when adding a supplier", async () => {
		render(<SuppliersManagement />);
		fireEvent.click(await screen.findByRole("button", { name: "+ Add Supplier" }));

		expect(document.querySelector(".erp-modal-backdrop .overflow-y-auto")).not.toBeInTheDocument();
		expect(document.querySelector(".fixed.inset-0.z-50")).toHaveClass("py-6");
		expect(document.querySelector(".erp-modal-backdrop")?.nextElementSibling).toHaveClass("max-w-5xl");
		fireEvent.change(screen.getByLabelText("Supplier Name *"), { target: { value: "Wallet Supplier" } });
		fireEvent.change(screen.getByLabelText("Destination Type"), { target: { value: "e_wallet" } });
		fireEvent.change(screen.getByLabelText("Wallet Provider"), { target: { value: "GCash" } });
		fireEvent.change(screen.getByLabelText("Account Name"), { target: { value: "Wallet Supplier" } });
		fireEvent.change(screen.getByLabelText("Mobile / Account Number"), { target: { value: "09171234567" } });
		const showAccountButton = screen.getByRole("button", { name: "Show account number" });
		expect(showAccountButton).toHaveAttribute("title", "Show account number");
		expect(showAccountButton).not.toHaveTextContent("Show");
		fireEvent.click(showAccountButton);

		expect(screen.getByLabelText("Mobile / Account Number")).toHaveAttribute("type", "text");
		const hideAccountButton = screen.getByRole("button", { name: "Hide account number" });
		expect(hideAccountButton).toHaveAttribute("title", "Hide account number");
		expect(hideAccountButton).not.toHaveTextContent("Hide");
		fireEvent.click(screen.getByRole("button", { name: "Add Supplier" }));

		await waitFor(() => expect(mocks.create).toHaveBeenCalledWith(expect.objectContaining({
			payment_profile: expect.objectContaining({
				recipient_type: "business",
				business_name: "Wallet Supplier",
				destination_type: "e_wallet",
				wallet_provider: "GCash",
				account_name: "Wallet Supplier",
				account_identifier: "09171234567",
			}),
		})));
	});

	it("exposes supplier profile fields and only supported payment terms", async () => {
		render(<SuppliersManagement />);

		fireEvent.click(await screen.findByRole("button", { name: "+ Add Supplier" }));

		expect(screen.getAllByText("City")).toHaveLength(1);
		expect(screen.getAllByText("Country")).toHaveLength(1);
		expect(screen.getAllByText("Lead Time (days)")).toHaveLength(1);
		expect(screen.getAllByText("Products Supplied")).toHaveLength(1);
		expect(screen.queryByRole("option", { name: "COD" })).not.toBeInTheDocument();
		expect(screen.queryByRole("option", { name: "50% down, 50% on delivery" })).not.toBeInTheDocument();
		expect(screen.getAllByRole("option", { name: "Net 60" }).length).toBeGreaterThan(0);
		expect(screen.queryByRole("option", { name: "Net 90" })).not.toBeInTheDocument();
	});

	it("defaults a new payout recipient to Business and swaps individual identity fields", async () => {
		render(<SuppliersManagement />);
		fireEvent.click(await screen.findByRole("button", { name: "+ Add Supplier" }));

		expect(screen.getByLabelText("Recipient Type")).toHaveValue("business");
		expect(screen.getByLabelText("Business Name")).toBeInTheDocument();
		fireEvent.change(screen.getByLabelText("Recipient Type"), { target: { value: "individual" } });
		expect(screen.queryByLabelText("Business Name")).not.toBeInTheDocument();
		expect(screen.getByLabelText("Given Name")).toBeInTheDocument();
		expect(screen.getByLabelText("Surname")).toBeInTheDocument();
		expect(screen.getByLabelText("Province / State")).toBeInTheDocument();
		expect(screen.getByLabelText("Postal Code")).toBeInTheDocument();
	});

	it("shows and submits the canonical supplier fields when editing", async () => {
		mocks.suppliers = [{
			id: 21,
			name: "Editable Supplier",
			contact_person: "Initial Contact",
			email: "initial@example.com",
			phone: "09171234567",
			address: "Initial Address",
			city: "Manila",
			country: "Philippines",
			payment_terms: "Net 30",
			lead_time_days: 7,
			products_supplied: "Running shoes",
			notes: "Initial notes",
			is_active: true,
			purchase_order_count: 0,
		}];
		mocks.getAll.mockResolvedValue({ data: mocks.suppliers });

		render(<SuppliersManagement />);
		fireEvent.click(await screen.findByRole("button", { name: "Edit Editable Supplier" }));

		expect(screen.getByLabelText("City")).toHaveValue("Manila");
		expect(screen.getByLabelText("Country")).toHaveValue("Philippines");
		expect(screen.getByLabelText("Payment Terms")).toHaveValue("Net 30");
		expect(screen.getByLabelText("Lead Time (days)")).toHaveValue(7);
		expect(screen.getByLabelText("Products Supplied")).toHaveValue("Running shoes");

		fireEvent.change(screen.getByLabelText("City"), { target: { value: "Cebu" } });
		fireEvent.change(screen.getByLabelText("Payment Terms"), { target: { value: "Net 60" } });
		fireEvent.change(screen.getByLabelText("Lead Time (days)"), { target: { value: "14" } });
		fireEvent.change(screen.getByLabelText("Products Supplied"), { target: { value: "Boots" } });
		fireEvent.click(screen.getByRole("button", { name: "Save Changes" }));

		await waitFor(() => expect(mocks.update).toHaveBeenCalledWith(21, expect.objectContaining({
			city: "Cebu",
			payment_terms: "Net 60",
			lead_time_days: 14,
			products_supplied: "Boots",
		})));
	});

	it("shows payment-profile verification status in the supplier list", async () => {
		mocks.suppliers = [
			{ id: 11, name: "Verified Supplier", is_active: true, purchase_order_count: 0, payment_profile_status: "verified" },
			{ id: 12, name: "Unverified Supplier", is_active: true, purchase_order_count: 0, payment_profile_status: "unverified" },
			{ id: 13, name: "No Profile Supplier", is_active: true, purchase_order_count: 0, payment_profile_status: null },
		];
		mocks.getAll.mockResolvedValue({ data: mocks.suppliers });

		render(<SuppliersManagement />);

		expect(await screen.findByText("Payment Verified")).toBeInTheDocument();
		expect(screen.getByText("Payment Unverified")).toBeInTheDocument();
		expect(screen.getByText("Payment Not Set")).toBeInTheDocument();
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
		expect(document.querySelector(".erp-modal-backdrop .overflow-y-auto")).not.toBeInTheDocument();
	});

	it("does not resubmit a legacy unsupported payment term while editing a supplier", async () => {
		mocks.suppliers = [{
			id: 9,
			name: "Legacy Supplier",
			payment_terms: "COD",
			is_active: true,
			purchase_order_count: 0,
		}];
		mocks.getAll.mockResolvedValue({ data: mocks.suppliers });

		render(<SuppliersManagement />);
		fireEvent.click(await screen.findByRole("button", { name: "Edit Legacy Supplier" }));
		fireEvent.click(screen.getByRole("button", { name: "Save Changes" }));

		await waitFor(() => expect(mocks.update).toHaveBeenCalledWith(9, expect.objectContaining({
			payment_terms: "",
		})));
	});

	it("keeps view and edit dialogs inside the viewport with one scrollable body", async () => {
		mocks.suppliers = [{ id: 31, name: "Tall Supplier", is_active: true, purchase_order_count: 0 }];
		mocks.getAll.mockResolvedValue({ data: mocks.suppliers });
		render(<SuppliersManagement />);

		fireEvent.click(await screen.findByRole("button", { name: "View details for Tall Supplier" }));
		let dialog = screen.getByRole("dialog", { name: "Supplier Details" });
		expect(dialog).toHaveClass("max-h-[calc(100dvh-2rem)]", "overflow-hidden");
		expect(dialog.querySelector(".min-h-0.flex-1.overflow-y-auto")).toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "Close view supplier modal" }));

		fireEvent.click(screen.getByRole("button", { name: "Edit Tall Supplier" }));
		dialog = await screen.findByRole("dialog", { name: "Edit Supplier" });
		expect(dialog).toHaveClass("max-h-[calc(100dvh-2rem)]", "overflow-hidden");
		expect(dialog.querySelector(".min-h-0.flex-1.overflow-y-auto")).toBeInTheDocument();
	});
});
