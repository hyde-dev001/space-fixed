import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const sourcePath = resolve(__dirname, "../POS.tsx");

describe("individual repair refund execution contract", () => {
  it("uses the owner payout endpoint for eligible individual repair refunds", () => {
    const source = readFileSync(sourcePath, "utf8");

    expect(source).toContain("registration_type");
    expect(source).toContain("refund.can_execute_payout === true");
    expect(source).toContain("/api/shop-owner/repair-refunds/${refund.id}/execute");
    expect(source).not.toContain("const canExecute = false;");
  });
});
