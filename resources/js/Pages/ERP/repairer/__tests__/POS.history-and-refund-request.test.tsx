import { describe, expect, it, vi, beforeEach } from "vitest";

const getMock = vi.fn();
const postMock = vi.fn();

vi.mock("axios", () => ({
  default: {
    get: getMock,
    post: postMock,
  },
}));

describe("repairPosHistoryApi", () => {
  beforeEach(() => {
    getMock.mockReset();
    postMock.mockReset();
  });

  it("calls transactions endpoint with repair filter", async () => {
    getMock.mockResolvedValue({ data: { success: true } });

    const { repairPosHistoryApi } = await import("../../../../services/repairPosHistoryApi");
    await repairPosHistoryApi.listTransactions(123);

    expect(getMock).toHaveBeenCalledWith("/api/repair-pos/transactions", {
      params: { repair_request_id: 123 },
      withCredentials: true,
    });
  });

  it("posts refund request payload", async () => {
    postMock.mockResolvedValue({ data: { success: true } });

    const { repairPosHistoryApi } = await import("../../../../services/repairPosHistoryApi");
    await repairPosHistoryApi.requestRefund({
      source_transaction_id: 88,
      request_type: "full",
      requested_amount: 560,
      reason_code: "repairer_requested_refund",
      reason_notes: "Requested from test",
      receipt_no: "RCPT-TEST-001",
    });

    expect(postMock).toHaveBeenCalledWith(
      "/api/repair-pos/refunds",
      {
        source_transaction_id: 88,
        request_type: "full",
        requested_amount: 560,
        reason_code: "repairer_requested_refund",
        reason_notes: "Requested from test",
        receipt_no: "RCPT-TEST-001",
      },
      { withCredentials: true },
    );
  });
});
