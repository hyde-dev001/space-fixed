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
      params: { repair_request_id: 123, per_page: 200 },
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

  it("posts the cashier-only manual refund action for a rejected no-account repair", async () => {
    postMock.mockResolvedValue({ data: { success: true } });

    const { repairPosHistoryApi } = await import("../../../../services/repairPosHistoryApi");
    await repairPosHistoryApi.manualRefundRejectedNoAccount({
      source_transaction_id: 88,
      receipt_no: "RCPT-TEST-001",
    });

    expect(postMock).toHaveBeenCalledWith(
      "/api/repair-pos/refunds/manual-rejected-no-account",
      {
        source_transaction_id: 88,
        receipt_no: "RCPT-TEST-001",
      },
      { withCredentials: true },
    );
  });

  it("posts warranty claim payload without evidence images", async () => {
    postMock.mockResolvedValue({ data: { success: true } });

    const { repairPosHistoryApi } = await import("../../../../services/repairPosHistoryApi");

    await repairPosHistoryApi.requestWarrantyClaim({
      repair_request_id: 321,
      receipt_no: "RCP-2001",
      walk_in_phone: "09171234567",
      reason_code: "issue_returned",
      reason_details: "Issue returned after pickup.",
      preferred_return_method: "walk_in",
    });

    const [url, body, config] = postMock.mock.calls[0];

    expect(url).toBe("/api/repair-pos/warranty-claims");
    expect(body).toEqual({
      repair_request_id: 321,
      receipt_no: "RCP-2001",
      walk_in_phone: "09171234567",
      reason_code: "issue_returned",
      reason_details: "Issue returned after pickup.",
      preferred_return_method: "walk_in",
      same_issue_confirmation: "1",
    });
    expect(config).toEqual({ withCredentials: true });
  });
});
