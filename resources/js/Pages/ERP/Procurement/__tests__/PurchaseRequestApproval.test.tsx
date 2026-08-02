import { describe, expect, it } from "vitest";
import { financeApprovalPrompt, isFinanceReviewStatus } from "../../Finance/PurchaseRequestApproval";

describe("Finance purchase request stages", () => {
	it("keeps initial and final Finance reviews distinct", () => {
		expect(isFinanceReviewStatus("pending_finance")).toBe(true);
		expect(financeApprovalPrompt("pending_finance", "PR-1")).toContain("send to Shop Owner");
		expect(isFinanceReviewStatus("pending_finance_final")).toBe(true);
		expect(financeApprovalPrompt("pending_finance_final", "PR-1")).toContain("after Shop Owner approval");
		expect(isFinanceReviewStatus("pending_shop_owner")).toBe(false);
	});
});
