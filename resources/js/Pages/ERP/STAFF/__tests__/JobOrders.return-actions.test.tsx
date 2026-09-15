import { describe, expect, it } from "vitest";
import {
  canArrangeReturnPickup,
  canConfirmReturnReceived,
  canSelectShopOwnedReturn,
  canStaffReviewRefund,
  isPosOrder,
} from "../JobOrders";

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

  it('allows third-party inspection after transport starts, but not before', () => {
    expect(canConfirmReturnReceived({
      latest_refund: {
        ...refund({
          status: 'processing',
          shop_owner_status: 'approved',
          finance_status: 'approved',
          return_status: 'in_transit',
          return_source: 'customer',
          return_delivery_method: 'third_party',
        }).latest_refund,
      },
    })).toBe(true);

    expect(canConfirmReturnReceived({
      latest_refund: {
        ...refund({
          status: 'processing',
          shop_owner_status: 'approved',
          finance_status: 'approved',
          return_status: 'pending_customer_shipment',
          return_source: 'customer',
          return_delivery_method: 'third_party',
        }).latest_refund,
      },
    })).toBe(false);
  });

  it('does not treat an undelivered Shop-owned return shipment as inspection-ready', () => {
    expect(canConfirmReturnReceived({
      latest_refund: {
        ...refund({
          status: 'processing',
          shop_owner_status: 'approved',
          finance_status: 'approved',
          return_status: 'in_transit',
          return_source: 'staff',
          return_delivery_method: 'shop_owned',
          return_logistics: { leg_status: 'in_transit' },
        }).latest_refund,
      },
    })).toBe(false);
  });

  it('uses explicit POS origin and coverage contracts', () => {
    expect(isPosOrder({ isPosOrder: true })).toBe(true);
    expect(isPosOrder({ isPosOrder: false })).toBe(false);
    expect(canSelectShopOwnedReturn({ available: true, reason: null, distance_km: 1, coverage_radius_km: 20 })).toBe(true);
    expect(canSelectShopOwnedReturn({ available: false, reason: 'outside_coverage', distance_km: 25, coverage_radius_km: 20 })).toBe(false);
  });
});
