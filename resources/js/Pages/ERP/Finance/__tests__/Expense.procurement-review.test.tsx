import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import Expense from "../Expense";

const mocks = vi.hoisted(() => ({
	refetch: vi.fn(),
	approve: vi.fn(),
	reject: vi.fn(),
	reviewRelease: vi.fn(),
	revealPaymentProfile: vi.fn(),
	swalFire: vi.fn(),
	status: "submitted" as "submitted" | "posted",
	paymentStatus: "unpaid" as string,
	paymentAttempt: null as Record<string, unknown> | null,
	adjustments: [] as Array<Record<string, unknown>>,
	xenditConfigured: true,
	hasPaymentProfile: true,
	paymentProfileStatus: "unverified" as "unverified" | "verified" | "disabled",
	expenseSource: "procurement" as "procurement" | "manual",
	creatorId: null as number | null,
	createdAt: null as string | null,
	ownerMode: false,
	expenseFilters: {} as Record<string, unknown>,
	categoryOptions: {
		manual: [
			{ value: "Travel", label: "Travel" },
			{ value: "Other", label: "Other" },
		],
		system: [{ value: "Procurement", label: "Procurement" }],
		filter: [
			{ value: "Travel", label: "Travel" },
			{ value: "Other", label: "Other" },
			{ value: "Procurement", label: "Procurement" },
		],
	},
}));

vi.mock("@inertiajs/react", () => ({
	usePage: () => ({ props: { auth: { user: { id: 7 }, erpActor: { id: 7, ownerMode: mocks.ownerMode } } } }),
}));
vi.mock("react-apexcharts", () => ({ default: () => null }));
vi.mock("sweetalert2", () => ({ default: { fire: mocks.swalFire } }));
vi.mock("../../../../hooks/useFinanceApi", () => ({
	useFinanceApi: () => ({ delete: vi.fn(), get: mocks.revealPaymentProfile, post: mocks.reviewRelease }),
}));
vi.mock("../../../../hooks/useFinanceQueries", () => ({
	useExpenses: (filters: Record<string, unknown>) => {
		mocks.expenseFilters = filters;

		return {
		data: [{
			id: "expense-1",
			date: "2026-08-09",
			created_at: mocks.createdAt,
			category: "Procurement",
			description: "Receipt for purchase order PO-2026-003",
			amount: 1020000,
			status: mocks.status,
			meta: mocks.creatorId === null ? undefined : { created_by: mocks.creatorId },
			procurement_details: mocks.expenseSource === "procurement" ? {
				receipt_id: 303,
				po_number: "PO-2026-003",
				receipt_number: "RCV-303",
				supplier_name: "Supplier",
				ordered_quantity: 5,
				received_quantity: 3,
				accepted_quantity: 2,
				defective_quantity: 1,
				unit_cost: "100.00",
				payable_amount: "200.00",
				payment_terms: "Net 30",
				receipt_date: "2026-08-09",
				due_date: "2026-09-08",
				expense_status: mocks.status,
				payment_status: mocks.paymentStatus,
				payment_attempt: mocks.paymentAttempt,
				adjustments: mocks.adjustments,
				payment_timing: "Overdue",
				supplier_id: 4,
				payment_profile: mocks.hasPaymentProfile ? {
					id: 8,
					recipient_type: "business",
					business_name: "Supplier Trading",
					recipient_country: "PH",
					recipient_province_state: "Cavite",
					recipient_city: "General Mariano Alvarez",
					recipient_street_line_1: "123 Test Street",
					recipient_street_line_2: null,
					recipient_postal_code: "4117",
					destination_type: "bank_account",
					bank_name: "Test Bank",
					bank_code: "TBK",
					account_name: "Supplier",
					masked_account_number: "******7890",
					status: mocks.paymentProfileStatus,
				} : null,
				xendit_configured: mocks.xenditConfigured,
			} : undefined,
		}],
		isLoading: false,
		refetch: mocks.refetch,
		};
	},
	useExpenseCategories: () => ({ data: mocks.categoryOptions, isLoading: false }),
	useTaxRates: () => ({ data: [], isLoading: false }),
	useApproveExpense: () => ({ isPending: false, mutateAsync: mocks.approve }),
	useRejectExpense: () => ({ isPending: false, mutateAsync: mocks.reject }),
}));

beforeEach(() => {
	vi.clearAllMocks();
	mocks.ownerMode = false;
	mocks.expenseFilters = {};
	mocks.createdAt = null;
	mocks.status = "submitted";
	mocks.paymentStatus = "unpaid";
	mocks.paymentAttempt = null;
	mocks.adjustments = [];
	mocks.xenditConfigured = true;
	mocks.hasPaymentProfile = true;
	mocks.paymentProfileStatus = "unverified";
	mocks.expenseSource = "procurement";
	mocks.creatorId = null;
	mocks.refetch.mockResolvedValue(undefined);
	mocks.revealPaymentProfile.mockResolvedValue({ ok: true, status: 200, data: { id: 8, account_number: "1234567890" } });
	mocks.swalFire.mockResolvedValue({ isConfirmed: true });
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

	it("uses the monochrome active state for the expense status filter", () => {
		render(<Expense />);

		expect(screen.getByRole("button", { name: "All" })).toHaveClass("bg-[#111111]", "text-white");
	});

	it("shows the shared expense pagination controls", () => {
		render(<Expense />);

		expect(screen.getByRole("button", { name: "Page 1" })).toHaveAttribute("aria-current", "page");
		expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled();
		expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled();
	});

	it("sends status, category, search, and server pagination filters to the query", () => {
		render(<Expense />);

		fireEvent.click(screen.getByRole("button", { name: "Pending" }));
		fireEvent.change(screen.getByPlaceholderText("Search category or note"), { target: { value: "travel" } });
		fireEvent.click(screen.getByRole("combobox", { name: "Filter by category" }));
		fireEvent.click(screen.getByRole("option", { name: "Travel" }));

		expect(mocks.expenseFilters).toEqual(expect.objectContaining({
			status: "submitted",
			category: "Travel",
			search: "travel",
			page: 1,
			perPage: 10,
		}));
	});

	it("renders a business date without inventing a midnight time", () => {
		render(<Expense />);

		expect(screen.getByText("Aug 09, 2026")).toBeInTheDocument();
		expect(screen.queryByText("12:00 AM")).not.toBeInTheDocument();
	});

	it("shows the recorded timestamp separately from the business date", () => {
		mocks.createdAt = "2026-08-09T01:30:00.000Z";
		render(<Expense />);

		const recordedTime = new Date(mocks.createdAt).toLocaleTimeString("en-US", {
			hour: "numeric",
			minute: "2-digit",
		});
		expect(screen.getByText(recordedTime)).toBeInTheDocument();
	});

	it("hides approval actions for an expense created by the current Finance user", () => {
		mocks.expenseSource = "manual";
		mocks.creatorId = 7;

		render(<Expense />);

		expect(screen.queryByRole("button", { name: "Approve expense" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Reject expense" })).not.toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));
		expect(screen.queryByRole("button", { name: "Approve" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Reject" })).not.toBeInTheDocument();
	});

	it("keeps the Add Expense form scrollable for long receipt previews", () => {
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "Add Expense" }));

		expect(document.querySelector(".erp-modal-backdrop .overflow-y-auto")).toBeInTheDocument();
	});

	it("uses backend categories and asks for a custom category when Other is selected", () => {
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "Add Expense" }));
		fireEvent.click(screen.getByRole("combobox", { name: "Expense category" }));
		const openOtherOption = screen.getAllByRole("option", { name: "Other" }).find(
			(option) => option.closest('[role="listbox"]')?.getAttribute("data-state") === "open",
		);
		expect(openOtherOption).toBeDefined();
		fireEvent.click(openOtherOption!);

		expect(screen.getByLabelText("Custom category")).toBeInTheDocument();
	});

	it("shows procurement review details and Review & Release only while submitted", () => {
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getAllByText("Supplier").length).toBeGreaterThan(0);
		expect(screen.getByText("RCV-303")).toBeInTheDocument();
		expect(screen.getByText("Review & Release")).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Pay Supplier" })).not.toBeInTheDocument();
		expect(document.querySelector(".erp-modal-backdrop .overflow-y-auto")).not.toBeInTheDocument();
		expect(document.querySelector(".erp-modal-backdrop")).toHaveClass("py-6");
	expect(document.querySelector(".erp-modal-backdrop > div")).toHaveClass("max-w-3xl");
	});

	it("shows only masked supplier payment details and Finance verification controls", () => {
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByText("******7890")).toBeInTheDocument();
		expect(screen.getByText("Business")).toBeInTheDocument();
		expect(screen.getByText("Supplier Trading")).toBeInTheDocument();
		expect(screen.getByText(/123 Test Street, General Mariano Alvarez, Cavite 4117, PH/)).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "Verify Payment Profile" })).toBeInTheDocument();
		expect(screen.queryByDisplayValue("1234567890")).not.toBeInTheDocument();
	});

	it("reveals and hides the supplier account only after an explicit Finance action", async () => {
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByText("******7890")).toBeInTheDocument();
		const showAccountButton = screen.getByRole("button", { name: "Show full account details" });
		expect(showAccountButton).toHaveAttribute("title", "Show full account details");
		expect(showAccountButton).not.toHaveTextContent("Show full account details");
		fireEvent.click(showAccountButton);

		await waitFor(() => expect(screen.getByText("1234567890")).toBeInTheDocument());
		expect(mocks.revealPaymentProfile).toHaveBeenCalledWith("/api/finance/suppliers/4/payment-profile/reveal");

		const hideAccountButton = screen.getByRole("button", { name: "Hide full account details" });
		expect(hideAccountButton).toHaveAttribute("title", "Hide full account details");
		expect(hideAccountButton).not.toHaveTextContent("Hide full account details");
		fireEvent.click(hideAccountButton);
		expect(screen.queryByText("1234567890")).not.toBeInTheDocument();
		expect(screen.getByText("******7890")).toBeInTheDocument();
	});

	it("uses a SweetAlert confirmation before Finance disables a payment profile", async () => {
		mocks.paymentProfileStatus = "verified";
		mocks.swalFire.mockResolvedValueOnce({ isConfirmed: false });

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));
		fireEvent.click(screen.getByRole("button", { name: "Disable Payment Profile" }));

		await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
			title: "Disable supplier payment profile?",
			showCancelButton: true,
			confirmButtonText: "Disable Payment Profile",
			cancelButtonText: "Keep Profile",
		})));
		expect(mocks.reviewRelease).not.toHaveBeenCalled();
	});

	it("shows payment readiness only after procurement release", () => {
		mocks.status = "posted";
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByText("READY FOR PAYMENT")).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "Pay Supplier" })).toBeDisabled();
		expect(screen.queryByRole("button", { name: "Review & Release" })).not.toBeInTheDocument();
	});

	it("explains when Xendit is connected but the supplier payout destination is missing", () => {
		mocks.status = "posted";
		mocks.hasPaymentProfile = false;

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByRole("status")).toHaveTextContent("Supplier payout account not ready");
		expect(screen.getByText(/Procurement must add the supplier's bank or e-wallet details/i)).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "Pay Supplier" })).toBeDisabled();
	});

	it("enables Pay Supplier after the receipt, destination, and Xendit connection are ready", () => {
		mocks.status = "posted";
		mocks.paymentProfileStatus = "verified";

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByRole("button", { name: "Pay Supplier" })).toBeEnabled();
		expect(screen.queryByText("Supplier payout account not ready")).not.toBeInTheDocument();
	});

	it("shows the backend procurement payment state in the open expense", () => {
		mocks.status = "posted";
		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getAllByText("Ready for Payment")).toHaveLength(2);
		expect(screen.queryByText("Review only")).not.toBeInTheDocument();
	});

	it("shows tenant-protected supplier refund proof links to Finance", () => {
		mocks.status = "posted";
		mocks.adjustments = [{
			id: 91,
			resolution: "refund",
			status: "awaiting_verification",
			expected_refund_amount: "100.00",
			refunded_amount: "0.00",
			supplier_refund_proof: [{ id: 901, file_name: "supplier-proof.pdf", mime_type: "application/pdf", size: 10 }],
		}];

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByRole("link", { name: "View supplier-proof.pdf" })).toHaveAttribute(
			"href",
			"/api/finance/supplier-adjustments/91/refund-proof/901",
		);
	});

	it("does not confirm an incoming supplier refund when confirmation is cancelled", async () => {
		mocks.status = "posted";
		mocks.adjustments = [{
			id: 91,
			resolution: "refund",
			status: "awaiting_verification",
			expected_refund_amount: "100.00",
			refunded_amount: "0.00",
			supplier_refund_proof: [{ id: 901, file_name: "supplier-proof.pdf", mime_type: "application/pdf", size: 10 }],
		}];
		mocks.swalFire.mockResolvedValueOnce({ isConfirmed: false });

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));
		fireEvent.click(screen.getByRole("button", { name: "Confirm incoming refund" }));
		fireEvent.change(screen.getByLabelText("Confirmed refund amount"), { target: { value: "100.00" } });
		fireEvent.change(screen.getByLabelText("Confirmed refund reference"), { target: { value: "BANK-REF-001" } });
		fireEvent.change(screen.getByLabelText("Finance refund proof"), {
			target: { files: [new File(["proof"], "finance-proof.pdf", { type: "application/pdf" })] },
		});
		fireEvent.click(screen.getByRole("button", { name: "Confirm refund" }));

		await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
			title: "Confirm incoming supplier refund?",
			showCancelButton: true,
		})));
		expect(mocks.reviewRelease).not.toHaveBeenCalled();
	});

	it("shows success feedback after Finance confirms an incoming supplier refund", async () => {
		mocks.status = "posted";
		mocks.adjustments = [{
			id: 91,
			resolution: "refund",
			status: "awaiting_verification",
			expected_refund_amount: "100.00",
			refunded_amount: "0.00",
			supplier_refund_proof: [{ id: 901, file_name: "supplier-proof.pdf", mime_type: "application/pdf", size: 10 }],
		}];
		mocks.reviewRelease.mockResolvedValueOnce({ ok: true, status: 201, data: {} });
		mocks.refetch.mockResolvedValueOnce({ data: [] });

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));
		fireEvent.click(screen.getByRole("button", { name: "Confirm incoming refund" }));
		fireEvent.change(screen.getByLabelText("Confirmed refund amount"), { target: { value: "100.00" } });
		fireEvent.change(screen.getByLabelText("Confirmed refund reference"), { target: { value: "BANK-REF-001" } });
		fireEvent.change(screen.getByLabelText("Finance refund proof"), {
			target: { files: [new File(["proof"], "finance-proof.pdf", { type: "application/pdf" })] },
		});
		fireEvent.click(screen.getByRole("button", { name: "Confirm refund" }));

		await waitFor(() => expect(mocks.reviewRelease).toHaveBeenCalledWith(
			"/api/finance/expenses/expense-1/supplier-adjustments/91/refund-confirmations",
			expect.any(FormData),
		));
		expect(mocks.swalFire).toHaveBeenCalledWith(
			"Supplier refund confirmed",
			"The Finance settlement and supplier adjustment were updated.",
			"success",
		);
	});

	it("shows the manual payment verification state instead of a duplicate pay action", () => {
		mocks.status = "posted";
		mocks.paymentStatus = "awaiting_verification";
		mocks.paymentAttempt = {
			id: 44,
			status: "awaiting_verification",
			amount: "200.00",
			payment_method: "manual_bank_transfer",
			external_transaction_reference: "BANK-001",
			supplier_email_status: "pending",
			masked_destination: { masked_account_number: "******7890" },
		};

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByText("AWAITING SHOP OWNER VERIFICATION")).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Pay Supplier" })).not.toBeInTheDocument();
	});

	it("lets Finance view submitted proof while payment awaits Shop Owner verification", () => {
		mocks.status = "posted";
		mocks.paymentStatus = "awaiting_verification";
		mocks.paymentAttempt = {
			id: 44,
			status: "awaiting_verification",
			amount: "200.00",
			payment_method: "manual_bank_transfer",
			supplier_email_status: "pending",
			proof_media: [{ id: 9, file_name: "proof.pdf", mime_type: "application/pdf", size: 100 }],
		};

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByRole("button", { name: "View Submitted Proof" })).toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "View Submitted Proof" }));
		expect(screen.getByRole("button", { name: /View proof\.pdf/i })).toBeInTheDocument();
	});

	it("lets Finance reopen a paid payment when its receipt is ready to send", () => {
		mocks.status = "posted";
		mocks.paymentStatus = "paid";
		mocks.paymentAttempt = {
			id: 44,
			status: "succeeded",
			amount: "200.00",
			payment_method: "manual_bank_transfer",
			supplier_email_status: "ready_to_send",
			proof_media: [{ id: 9, file_name: "proof.pdf", mime_type: "application/pdf", size: 100 }],
		};

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByRole("button", { name: "View Payment Proof" })).toBeInTheDocument();
		expect(screen.getByRole("button", { name: "Send Payment Receipt" })).toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "View Payment Proof" }));
		expect(screen.getByRole("button", { name: /View proof\.pdf/i })).toBeInTheDocument();
	});

	it("lets the Shop Owner reopen a paid payment review without Finance actions", () => {
		mocks.ownerMode = true;
		mocks.status = "posted";
		mocks.paymentStatus = "paid";
		mocks.paymentAttempt = {
			id: 44,
			status: "succeeded",
			amount: "200.00",
			payment_method: "manual_bank_transfer",
			supplier_email_status: "ready_to_send",
			proof_media: [{ id: 9, file_name: "proof.pdf", mime_type: "application/pdf", size: 100 }],
		};

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByRole("button", { name: "View Payment Proof" })).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Send Payment Receipt" })).not.toBeInTheDocument();
		fireEvent.click(screen.getByRole("button", { name: "View Payment Proof" }));
		expect(screen.getByRole("heading", { name: "Review Supplier Payment" })).toBeInTheDocument();
		expect(screen.getByRole("button", { name: /proof\.pdf/i })).toBeInTheDocument();
	});

	it("does not ask the Shop Owner to review a paid Xendit payout", () => {
		mocks.ownerMode = true;
		mocks.status = "posted";
		mocks.paymentStatus = "paid";
		mocks.paymentAttempt = {
			id: 45,
			status: "succeeded",
			provider: "xendit",
			amount: "200.00",
			payment_method: "xendit",
		};

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.getByText("PAID · PAYMENT VERIFIED")).toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "View Payment Proof" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Review Supplier Payment" })).not.toBeInTheDocument();
	});

	it("does not expose payment-profile controls to the Shop Owner", () => {
		mocks.ownerMode = true;
		mocks.paymentProfileStatus = "verified";

		render(<Expense />);
		fireEvent.click(screen.getByRole("button", { name: "View expense" }));

		expect(screen.queryByRole("button", { name: "Verify Payment Profile" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Disable Payment Profile" })).not.toBeInTheDocument();
		expect(screen.queryByRole("button", { name: "Show full account details" })).not.toBeInTheDocument();
	});

	it("hides expense creation from the shop owner while keeping the page readable", () => {
		mocks.ownerMode = true;

		render(<Expense />);

		expect(screen.getByRole("heading", { name: "Expense Management" })).toHaveClass("sr-only");
		expect(screen.queryByRole("button", { name: "Add Expense" })).not.toBeInTheDocument();
	});
});
