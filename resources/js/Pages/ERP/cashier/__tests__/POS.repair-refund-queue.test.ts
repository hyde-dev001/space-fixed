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

	it("opens the proof form for every POS manual leg, including legacy POS refunds", () => {
		expect(source).toContain("refund.has_pos_manual_leg === true");
		expect(source).not.toContain("&& String(refund.workflow_source ?? \"\").toLowerCase() === \"shop_pos_repair\"");
	});

	it("uses the approved refund amount instead of accepting a typed payout amount", () => {
		expect(source).toContain("refund.approved_amount ?? refund.requested_amount");
		expect(source).toContain("Refund amount (fixed)");
		expect(source).not.toContain('id="repair_refund_execution_amount"');
		expect(source).not.toContain('formData.append("execution_amount"');
	});

	it("hides internal approval badges and humanizes refund reasons", () => {
		expect(source).not.toContain("F:{financeStatus}");
		expect(source).not.toContain("O:{ownerStatus}");
		expect(source).toContain("humanizeRefundReason(refund.reason_code)");
	});
});
