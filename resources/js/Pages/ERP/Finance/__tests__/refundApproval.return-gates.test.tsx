import { describe, expect, it } from "vitest";
import {
  canExecuteRefundPayout,
  canFinanceAuthorizeRefund,
  getApprovalStageLabel,
  getFinanceApprovalNotice,
} from "../refundApproval";

const request = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  refundType: "order",
  orderNumber: "ORD-1",
  customerName: "Customer",
  refundAmount: "₱1,000",
  refundMethod: "PayMongo",
  requestedBy: "Customer",
  requestDate: "2026-07-22",
  reason: "Damaged",
  status: "Pending" as const,
  rawStatus: "pending_approval",
  shopOwnerStatus: "pending",
  financeStatus: "pending",
  returnStatus: "awaiting_approval",
  ...overrides,
});

describe("Finance customer refund gates", () => {
  it("waits for Staff eligibility approval before authorization", () => {
    expect(canFinanceAuthorizeRefund(request())).toBe(false);
    expect(canFinanceAuthorizeRefund(request({ shopOwnerStatus: "approved" }))).toBe(true);
  });

  it("releases money only after Staff receipt and inspection", () => {
    expect(canExecuteRefundPayout(request({
      shopOwnerStatus: "approved",
      financeStatus: "approved",
      returnStatus: "in_transit",
    }))).toBe(false);
    expect(canExecuteRefundPayout(request({
      shopOwnerStatus: "approved",
      financeStatus: "approved",
      returnStatus: "received",
    }))).toBe(true);
  });

  it("does not apply the retail return inspection message to repair refunds", () => {
    const repairNotice = getFinanceApprovalNotice({ refundType: "repair" });
    const retailNotice = getFinanceApprovalNotice({ refundType: "order" });

    expect(repairNotice).toContain("repair-service refund");
    expect(repairNotice).toContain("No retail item return");
    expect(repairNotice).not.toContain("Staff receives");
    expect(retailNotice).toContain("Staff receives");
  });

  it("labels a repair refund waiting for shop owner approval", () => {
    expect(getApprovalStageLabel(request({
      refundType: "repair",
      approvalStage: "shop_owner",
      financeStatus: "approved_initial",
      shopOwnerStatus: "pending",
    }))).toBe("Waiting for shop owner approval");
  });
});
