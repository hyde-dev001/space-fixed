import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SupplierPaymentDialog from "../components/SupplierPaymentDialog";

const mocks = vi.hoisted(() => ({
	post: vi.fn(),
	resolveUrl: (url: string) => url,
	onChanged: vi.fn(),
	onClose: vi.fn(),
}));

vi.mock("../../../../hooks/useFinanceApi", () => ({
	useFinanceApi: () => ({ post: mocks.post, resolveUrl: mocks.resolveUrl }),
}));

const details = {
	supplier_name: "Supplier",
	po_number: "PO-2026-003",
	receipt_number: "RCV-303",
	payment_terms: "Net 30",
	due_date: "2026-09-08",
	payable_amount: "100.00",
	payment_profile: {
		bank_name: "Test Bank",
		destination_type: "bank_account",
		account_name: "Supplier",
		masked_account_number: "******7890",
		bank_code: "TBK",
		status: "verified",
	},
} as const;

beforeEach(() => {
	vi.clearAllMocks();
	mocks.onChanged.mockResolvedValue(undefined);
});

describe("SupplierPaymentDialog", () => {
	it("starts a manual payment and requires proof before verification submission", async () => {
		mocks.post.mockResolvedValueOnce({
			ok: true,
			data: {
				id: 44,
				status: "initiating",
				amount: "100.00",
				payment_method: "manual_bank_transfer",
				masked_destination: details.payment_profile,
			},
		});

		render(<SupplierPaymentDialog open mode="finance" expenseId="1" details={details} amount="100.00" onClose={mocks.onClose} onChanged={mocks.onChanged} />);
		fireEvent.click(screen.getByRole("button", { name: "Start Payment" }));

		await waitFor(() => expect(screen.getByText(/Perform the real transfer/i)).toBeInTheDocument());
		expect(mocks.post).toHaveBeenCalledWith("/api/finance/expenses/1/supplier-payment-attempts", expect.objectContaining({ payment_method: "manual_bank_transfer" }));
		expect(screen.getByRole("button", { name: "Submit for Shop Owner Verification" })).toBeDisabled();
	});

	it("lets the Shop Owner review proof and confirm without exposing a supplier login flow", async () => {
		mocks.post.mockResolvedValueOnce({
			ok: true,
			data: { id: 44, status: "succeeded", amount: "100.00", payment_method: "manual_bank_transfer", supplier_email_status: "sent" },
		});

		render(
			<SupplierPaymentDialog
				open
				mode="owner"
				expenseId="1"
				details={details}
				amount="100.00"
				initialAttempt={{
					id: 44,
					status: "awaiting_verification",
					amount: "100.00",
					payment_method: "manual_bank_transfer",
					external_transaction_reference: "BANK-001",
					initiated_by: { id: 7, name: "Finance Maker" },
					proof_media: [{ id: 9, file_name: "proof.pdf", mime_type: "application/pdf", size: 100 }],
					masked_destination: details.payment_profile,
				}}
				onClose={mocks.onClose}
				onChanged={mocks.onChanged}
			/>,
		);

		expect(screen.getByRole("button", { name: /proof\.pdf/i })).toBeInTheDocument();
		expect(screen.getByText("Finance Maker")).toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "Confirm Payment" }));

		await waitFor(() => expect(mocks.post).toHaveBeenCalledWith("/api/shop-owner/finance/supplier-payment-attempts/44/confirm", {}));
		expect(mocks.onChanged).toHaveBeenCalled();
	});
});
