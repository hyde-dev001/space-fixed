import { describe, expect, it } from "vitest";
import { canArrangeReturnPickup, canConfirmReturnReceived, canStaffReviewRefund } from "../JobOrders";

describe("staff failed-delivery return actions", () => {
  it("does not offer manual confirmation for failed-delivery refunds", () => {
    expect(canConfirmReturnReceived({
      latest_refund: {
        id: 1,
        status: "requested",
        reason_code: "delivery_attempts_exhausted",
        shop_owner_status: "approved",
        finance_status: "pending",
        return_status: "in_transit",
        return_source: "staff",
        staff_return_carrier: "Shop-owned logistics",
        flow_type: "request_approval",
      },
    })).toBe(false);
  });
});

describe("staff customer refund actions", () => {
  const refund = (overrides: Record<string, unknown> = {}) => ({
    latest_refund: {
      id: 2,
      status: "pending_approval",
      shop_owner_status: "pending",
      finance_status: "pending",
      return_status: "awaiting_approval",
      flow_type: "request_approval",
      ...overrides,
    },
  });

  it("offers Staff eligibility review before Finance authorization", () => {
    expect(canStaffReviewRefund(refund())).toBe(true);
    expect(canArrangeReturnPickup(refund())).toBe(false);
  });

  it("offers pickup only after both approvals in the exact pre-pickup state", () => {
    expect(canArrangeReturnPickup(refund({
      shop_owner_status: "approved",
      finance_status: "approved",
      return_status: "pending_customer_shipment",
    }))).toBe(true);
    expect(canArrangeReturnPickup(refund({
      shop_owner_status: "approved",
      finance_status: "approved",
      return_status: "in_transit",
    }))).toBe(false);
  });
});
