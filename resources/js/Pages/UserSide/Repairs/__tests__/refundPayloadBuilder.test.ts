import { describe, expect, it } from "vitest";
import { buildRepairRefundPayload } from "../refundPayloadBuilder";

describe("buildRepairRefundPayload", () => {
  it("includes POS payout preference and allows at least one media file", () => {
    const payload = buildRepairRefundPayload({
      sourceTransactionId: 99,
      requestedAmount: 560,
      reasonCode: "service_issue",
      reasonNotes: "Need refund",
      preferredReturnChannel: "gcash",
      preferredReturnAccountName: "Juan Dela Cruz",
      preferredReturnAccountRef: "09171234567",
      customerPayoutConsent: true,
      evidence: [{ type: "photo", url: "https://evidence.local/p1.jpg" }],
    });

    expect(payload.preferred_return_channel).toBe("gcash");
    expect(payload.customer_payout_consent).toBe(true);
    expect(payload.evidence).toHaveLength(1);
  });
});
