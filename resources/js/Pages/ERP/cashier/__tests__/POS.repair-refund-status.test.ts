import { describe, expect, it } from "vitest";
import { getRepairRefundStatusPresentation } from "../POS";

describe("repair refund status presentation", () => {
	const base = {
		status: "requested",
		workflowSource: "online_myrepair",
		repairerStatus: "approved",
		financeStatus: "pending",
		shopOwnerStatus: "pending",
	};

	it("shows the repairer stage before Finance review", () => {
		expect(getRepairRefundStatusPresentation({
			...base,
			repairerStatus: "pending",
		})).toEqual({
			label: "Under Repairer Review",
			hint: "Waiting for repairer review",
		});
	});

	it("does not call an owner-pending repair refund Finance pending", () => {
		expect(getRepairRefundStatusPresentation({
			...base,
			financeStatus: "approved_initial",
		})).toEqual({
			label: "Under Owner Review",
			hint: "Pending shop owner approval",
		});
	});

	it("shows the canonical payout and terminal stages", () => {
		expect(getRepairRefundStatusPresentation({
			...base,
			status: "approved",
			financeStatus: "approved",
			shopOwnerStatus: "approved",
		})).toEqual({
			label: "Approved",
			hint: "Approved, ready for payout execution",
		});

		expect(getRepairRefundStatusPresentation({
			...base,
			status: "succeeded",
			financeStatus: "approved",
			shopOwnerStatus: "skipped",
		})).toEqual({
			label: "Refunded",
			hint: "Refund payout completed",
		});
	});
});
