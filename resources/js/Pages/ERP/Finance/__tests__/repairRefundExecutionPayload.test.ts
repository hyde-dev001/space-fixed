import { describe, expect, it } from "vitest";
import { buildRepairRefundExecutionPayload } from "../repairRefundExecutionPayload";

describe("buildRepairRefundExecutionPayload", () => {
  it("does not include a caller-supplied execution amount", () => {
    const payload = buildRepairRefundExecutionPayload({ executionMode: "gateway" });

    expect(payload).not.toHaveProperty("execution_amount");
  });

  it("throws when POS manual proof is incomplete", () => {
    expect(() =>
      buildRepairRefundExecutionPayload({
        executionMode: "manual",
        executionChannel: "gcash",
        executionReference: "",
        executionProofUrls: [],
      }),
    ).toThrow("Execution reference is required for manual POS refund execution");
  });
});
