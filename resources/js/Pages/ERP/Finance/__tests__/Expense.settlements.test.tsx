import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

describe("Finance expense settlement contract", () => {
  const source = readFileSync(resolve(__dirname, "../Expense.tsx"), "utf8");

  it("exposes paid-now and pay-later creation semantics", () => {
    expect(source).toContain("payment_mode");
    expect(source).toContain("paid_now");
    expect(source).toContain("pay_later");
    expect(source).toContain("due_date");
    expect(source).toContain("payment_method");
  });

  it("renders backend settlement state and reconciliation warnings", () => {
    expect(source).toContain("settlement_state");
    expect(source).toContain("integrity_warnings");
    expect(source).toContain("/api/finance/session/expenses");
  });
});
