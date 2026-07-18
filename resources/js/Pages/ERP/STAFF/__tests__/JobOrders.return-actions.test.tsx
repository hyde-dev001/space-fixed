import { describe, expect, it } from "vitest";
import { canConfirmReturnReceived } from "../JobOrders";

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
