import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const source = readFileSync(resolve(__dirname, "../POS.tsx"), "utf8");

describe("canonical repair refund queue", () => {
	it("opens from Repair mode on the canonical payments page", () => {
		expect(source).toContain("onClick={() => setIsRefundQueueOpen(true)}");
		expect(source).toContain("Refund Queue");
	});

	it("renders the shop reference and complete repair/customer details without exposing the numeric id", () => {
		expect(source).toContain("refund.refund_reference");
		expect(source).toContain("customer_phone");
		expect(source).toContain("customer_email");
		expect(source).toContain("shoe_type");
		expect(source).toContain("service_name");
		expect(source).toContain("reason_notes");
		expect(source).not.toContain("#${refund.id}");
	});

	it("keeps the individual owner execution action on the existing owner endpoint", () => {
		expect(source).toContain("/api/shop-owner/repair-refunds/${refund.id}/execute");
	});

	it("hides internal approval badges and humanizes refund reasons", () => {
		expect(source).not.toContain("F:{financeStatus}");
		expect(source).not.toContain("O:{ownerStatus}");
		expect(source).toContain("humanizeRefundReason(refund.reason_code)");
	});
});
