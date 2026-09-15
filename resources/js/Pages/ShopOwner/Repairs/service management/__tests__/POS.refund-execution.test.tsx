import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const sourcePath = resolve(__dirname, "../POS.tsx");
const source = readFileSync(sourcePath, "utf8");

describe("individual repair refund execution contract", () => {
  it("uses the owner payout endpoint for eligible individual repair refunds", () => {
    expect(source).toContain("registration_type");
    expect(source).toContain("refund.can_execute_payout === true");
    expect(source).toContain("/api/shop-owner/repair-refunds/${refund.id}/execute");
    expect(source).not.toContain("const canExecute = false;");
  });

  it("shows the shop reference and complete repair/customer details without exposing the numeric id", () => {
    expect(source).toContain("refund.refund_reference");
    expect(source).toContain("customer_phone");
    expect(source).toContain("customer_email");
    expect(source).toContain("shoe_type");
    expect(source).toContain("service_name");
    expect(source).toContain("reason_notes");
    expect(source).not.toContain("#${refund.id}");
  });
});
