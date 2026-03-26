import { render, screen } from "@testing-library/react";
import RefundStageBadge, { resolveRefundStageBadge } from "../RefundStageBadge";

describe("RefundStageBadge", () => {
	it("renders awaiting approval state before dual approval", () => {
		render(
			<RefundStageBadge
				request={{
					status: "Pending",
					shopOwnerStatus: "pending",
					financeStatus: "pending",
					returnStatus: "awaiting_approval",
				}}
			/>,
		);

		expect(screen.getByText("Awaiting Approval")).toBeInTheDocument();
	});

	it("resolves ready for finance payout after return received", () => {
		const stage = resolveRefundStageBadge({
			status: "Approved",
			shopOwnerStatus: "approved",
			financeStatus: "approved",
			returnStatus: "received",
		});

		expect(stage.label).toBe("Ready for Finance Payout");
		expect(stage.className).toContain("bg-indigo-100");
	});

	it("renders refunded state when gateway marks refund as succeeded", () => {
		render(
			<RefundStageBadge
				request={{
					status: "Approved",
					rawStatus: "succeeded",
					shopOwnerStatus: "approved",
					financeStatus: "approved",
					returnStatus: "received",
				}}
			/>,
		);

		expect(screen.getByText("Refunded")).toBeInTheDocument();
	});

	it("renders rejected state when any stage is rejected", () => {
		render(
			<RefundStageBadge
				request={{
					status: "Rejected",
					shopOwnerStatus: "approved",
					financeStatus: "rejected",
					returnStatus: "awaiting_approval",
				}}
			/>,
		);

		expect(screen.getByText("Refund Rejected")).toBeInTheDocument();
	});

	it("renders return in transit state while item is on the way back", () => {
		render(
			<RefundStageBadge
				request={{
					status: "Approved",
					shopOwnerStatus: "approved",
					financeStatus: "approved",
					returnStatus: "in_transit",
				}}
			/>,
		);

		expect(screen.getByText("Return In Transit")).toBeInTheDocument();
	});
});
