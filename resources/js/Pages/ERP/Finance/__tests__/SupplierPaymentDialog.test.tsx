import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import SupplierPaymentDialog from "../components/SupplierPaymentDialog";

const mocks = vi.hoisted(() => ({
	post: vi.fn(),
	get: vi.fn(),
	confirm: vi.fn(),
	success: vi.fn(),
	resolveUrl: (url: string) => url,
	onChanged: vi.fn(),
	onClose: vi.fn(),
}));

vi.mock("../../../../hooks/useFinanceApi", () => ({
	useFinanceApi: () => ({ get: mocks.get, post: mocks.post, resolveUrl: mocks.resolveUrl }),
}));

vi.mock("../../../../utils/workflowFeedback", () => ({
	workflowFeedback: { confirm: mocks.confirm, success: mocks.success },
}));

	const details = {
	supplier_id: 4,
	supplier_name: "Supplier",
	po_number: "PO-2026-003",
	receipt_number: "RCV-303",
	payment_terms: "Net 30",
	due_date: "2026-09-08",
	payable_amount: "100.00",
	payment_profile: {
		id: 8,
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
	mocks.confirm.mockResolvedValue({ isConfirmed: true });
	mocks.success.mockResolvedValue({ isConfirmed: true });
	mocks.get.mockResolvedValue({ ok: true, status: 200, data: { id: 8, account_number: "1234567890" } });
});

describe("SupplierPaymentDialog", () => {
	it("keeps the payment details in a wide non-scrolling dialog", () => {
		render(<SupplierPaymentDialog open mode="finance" expenseId="1" details={details} amount="100.00" onClose={mocks.onClose} onChanged={mocks.onChanged} />);

		const dialog = screen.getByRole("dialog", { name: "Pay Supplier by Manual Transfer" });
		const panel = dialog.firstElementChild;

		expect(panel).toHaveClass("max-w-3xl");
		expect(panel).not.toHaveClass("max-h-[90vh]");
		expect(dialog.querySelector(".overflow-y-auto")).not.toBeInTheDocument();
	});

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

	it("starts a Xendit payout with a confirmation and no manual proof form", async () => {
		mocks.post.mockResolvedValueOnce({
			ok: true,
			data: {
				id: 45,
				status: "processing",
				payment_status: "processing",
				provider: "xendit",
				amount: "100.00",
				payment_method: "xendit",
			},
		});

		render(<SupplierPaymentDialog open mode="finance" expenseId="1" details={{ ...details, xendit_configured: true }} amount="100.00" onClose={mocks.onClose} onChanged={mocks.onChanged} />);
		fireEvent.click(screen.getByRole("button", { name: "Pay Supplier via Xendit" }));

		await waitFor(() => expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({
			title: "Send supplier payout via Xendit?",
			confirmButtonText: "Pay Supplier",
		})));
		await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
			"/api/finance/expenses/1/supplier-payment-attempts",
			expect.objectContaining({ payment_method: "xendit" }),
		));
		expect(screen.queryByLabelText("Payment method")).not.toBeInTheDocument();
		expect(screen.getByRole("status")).toHaveTextContent(/Xendit payout processing/i);
		expect(mocks.success).toHaveBeenCalledWith(expect.objectContaining({
			title: "Supplier payout submitted",
		}));
	});

	it("confirms before submitting payment proof for Shop Owner verification", async () => {
		mocks.post.mockResolvedValueOnce({
			ok: true,
			data: { id: 44, status: "awaiting_verification", amount: "100.00", payment_method: "manual_bank_transfer" },
		});

		render(
			<SupplierPaymentDialog
				open
				mode="finance"
				expenseId="1"
				details={details}
				amount="100.00"
				initialAttempt={{ id: 44, status: "initiating", amount: "100.00", payment_method: "manual_bank_transfer" }}
				onClose={mocks.onClose}
				onChanged={mocks.onChanged}
			/>,
		);

		fireEvent.change(screen.getByLabelText("External transaction/reference number"), { target: { value: "BANK-001" } });
		fireEvent.change(screen.getByLabelText(/Payment proof/), { target: { files: [new File(["proof"], "proof.jpg", { type: "image/jpeg" })] } });
		const submitButton = screen.getByRole("button", { name: "Submit for Shop Owner Verification" });
		fireEvent.submit(submitButton.closest("form")!);

		await waitFor(() => expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({
			title: "Submit payment for Shop Owner verification?",
			confirmButtonText: "Submit for Verification",
		})));
		await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
			"/api/finance/supplier-payment-attempts/44/submit",
			expect.any(FormData),
		));
		expect(mocks.success).toHaveBeenCalledWith(expect.objectContaining({
			title: "Payment submitted",
		}));
	});

	it("lets Finance reveal and hide the current supplier account for the transfer", async () => {
		render(<SupplierPaymentDialog open mode="finance" expenseId="1" details={details} amount="100.00" onClose={mocks.onClose} onChanged={mocks.onChanged} />);

		expect(screen.getByText(/\*\*\*\*\*\*7890/)).toBeInTheDocument();
		const showAccountButton = screen.getByRole("button", { name: "Show full account details" });
		expect(showAccountButton).toHaveAttribute("title", "Show full account details");
		expect(showAccountButton).not.toHaveTextContent("Show full account details");
		fireEvent.click(showAccountButton);

		await waitFor(() => expect(screen.getByText("1234567890")).toBeInTheDocument());
		expect(mocks.get).toHaveBeenCalledWith("/api/finance/suppliers/4/payment-profile/reveal");

		const hideAccountButton = screen.getByRole("button", { name: "Hide full account details" });
		expect(hideAccountButton).toHaveAttribute("title", "Hide full account details");
		expect(hideAccountButton).not.toHaveTextContent("Hide full account details");
		fireEvent.click(hideAccountButton);
		expect(screen.queryByText("1234567890")).not.toBeInTheDocument();
		expect(screen.getByText("******7890")).toBeInTheDocument();
	});

	it("lets the Shop Owner review proof and confirm without exposing a supplier login flow", async () => {
		mocks.post.mockResolvedValueOnce({
			ok: true,
			data: { id: 44, status: "succeeded", amount: "100.00", payment_method: "manual_bank_transfer", supplier_email_status: "ready_to_send" },
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
		expect(screen.queryByRole("button", { name: "Show full account details" })).not.toBeInTheDocument();
		const windowOpen = vi.spyOn(window, "open").mockImplementation(() => null);
		fireEvent.click(screen.getByRole("button", { name: /proof\.pdf/i }));
		expect(windowOpen).not.toHaveBeenCalled();
		expect(screen.getByRole("dialog", { name: "Payment proof preview" })).toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "Close payment proof preview" }));
		expect(screen.queryByRole("dialog", { name: "Payment proof preview" })).not.toBeInTheDocument();
		windowOpen.mockRestore();
		fireEvent.click(screen.getByRole("button", { name: "Confirm Payment" }));

		await waitFor(() => expect(mocks.post).toHaveBeenCalledWith("/api/shop-owner/finance/supplier-payment-attempts/44/confirm", {}));
		expect(mocks.onChanged).toHaveBeenCalled();
		expect(mocks.success).toHaveBeenCalledWith(expect.objectContaining({
			title: "Payment confirmed",
		}));
		expect(screen.queryByText(/Ready to Send/i)).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: /Send Payment Receipt|Retry Payment Receipt/i })).not.toBeInTheDocument();
	});

	it("keeps payment proof available after the payment is verified", () => {
		render(
			<SupplierPaymentDialog
				open
				mode="owner"
				expenseId="1"
				details={details}
				amount="100.00"
				initialAttempt={{
					id: 44,
					status: "succeeded",
					amount: "100.00",
					payment_method: "manual_bank_transfer",
					supplier_email_status: "ready_to_send",
					proof_media: [{ id: 9, file_name: "proof.pdf", mime_type: "application/pdf", size: 100 }],
				}}
				onClose={mocks.onClose}
				onChanged={mocks.onChanged}
			/>,
		);

		expect(screen.getByRole("button", { name: /proof\.pdf/i })).toBeInTheDocument();
	});

	it("reuses one idempotency key when initiation is retried after a network failure", async () => {
		mocks.post
			.mockResolvedValueOnce({ ok: false, error: "Network error" })
			.mockResolvedValueOnce({
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
		await waitFor(() => expect(screen.getByRole("alert")).toHaveTextContent("Network error"));
		fireEvent.click(screen.getByRole("button", { name: "Start Payment" }));

		await waitFor(() => expect(screen.getByText(/Perform the real transfer/i)).toBeInTheDocument());
		const firstRequest = mocks.post.mock.calls[0][1] as { idempotency_key: string };
		const secondRequest = mocks.post.mock.calls[1][1] as { idempotency_key: string };
		expect(secondRequest.idempotency_key).toBe(firstRequest.idempotency_key);
	});

	it("previews image proof inline and shows a safe unavailable state", () => {
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
					proof_media: [{ id: 10, file_name: "proof.png", mime_type: "image/png", size: 100 }],
					masked_destination: details.payment_profile,
				}}
				onClose={mocks.onClose}
				onChanged={mocks.onChanged}
			/>,
		);

		const thumbnail = screen.getByAltText("Payment proof proof.png");
		expect(thumbnail).toHaveClass("object-contain");
		fireEvent.click(screen.getByRole("button", { name: /proof\.png/i }));
		expect(screen.getByRole("dialog", { name: "Payment proof preview" })).toBeInTheDocument();

		fireEvent.error(screen.getAllByAltText("Payment proof proof.png").at(-1)!);
		expect(screen.getByText("Proof file unavailable.")).toBeInTheDocument();
		fireEvent.keyDown(window, { key: "Escape" });
		expect(screen.queryByRole("dialog", { name: "Payment proof preview" })).not.toBeInTheDocument();
	});

	it("does not expose supplier receipt email actions after payment", () => {
		render(
			<SupplierPaymentDialog
				open
				mode="finance"
				expenseId="1"
				details={details}
				amount="100.00"
				initialAttempt={{
					id: 44,
					status: "succeeded",
					amount: "100.00",
					payment_method: "manual_bank_transfer",
					supplier_email_status: "ready_to_send",
				}}
				onClose={mocks.onClose}
				onChanged={mocks.onChanged}
			/>,
		);

		expect(screen.getByText("Expense settlement recorded.")).toBeInTheDocument();
		expect(screen.queryByText(/Payment receipt email status/i)).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: /Send Payment Receipt|Retry Payment Receipt/i })).not.toBeInTheDocument();
	});
});
