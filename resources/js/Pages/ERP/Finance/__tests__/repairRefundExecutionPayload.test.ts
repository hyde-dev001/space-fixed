import { describe, expect, it } from "vitest";
import { buildRepairRefundExecutionPayload } from "../repairRefundExecutionPayload";

describe("buildRepairRefundExecutionPayload", () => {
  it("throws when POS manual proof is incomplete", () => {
    expect(() =>
      buildRepairRefundExecutionPayload({
        executionMode: "manual",
        executionChannel: "gcash",
        executionReference: "",
        executionAmount: 500,
        executionProofUrls: [],
      }),
    ).toThrow("Execution reference is required for manual POS refund execution");
  });
});
