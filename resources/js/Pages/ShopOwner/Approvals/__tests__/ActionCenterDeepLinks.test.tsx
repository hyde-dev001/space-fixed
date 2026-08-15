import { render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  fetch: vi.fn(),
  axiosGet: vi.fn(),
  axiosPost: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  router: { visit: vi.fn() },
  usePage: () => ({
    props: {
      auth: { shop_owner: { registration_type: "company", business_type: "both" } },
      erpMode: false,
    },
  }),
}));

vi.mock("sweetalert2", () => ({
  default: { fire: vi.fn() },
}));

vi.mock("axios", () => ({
  default: {
    get: mocks.axiosGet,
    post: mocks.axiosPost,
  },
}));

vi.mock("../../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock("../../../../components/refunds/RefundStageBadge", () => ({
  default: () => <span>Refund stage</span>,
}));

import ExpenseApproval from "../ExpenseApproval";
import PurchaseRequestApproval from "../PurchaseRequestApproval";
import RefundApproval from "../refundApproval";

const response = (data: unknown) => ({
  ok: true,
  status: 200,
  json: async () => ({ data }),
});

const orderRefund = (id: number) => ({
  id,
  orderNumber: `SO-2026-${id}`,
  customerName: "Ana Rivera",
  refundAmount: "₱450",
  refundMethod: "GCash",
  requestedBy: "Staff",
  requestDate: "2026-08-14",
  refundReason: "Defective item",
  reason: "Defective item",
  status: "Pending",
});

const repairRefund = (id: number) => ({
  ...orderRefund(id),
  orderNumber: `REP-${id}`,
  refundType: "repair",
});

describe("Action Center approval deep links", () => {
  beforeEach(() => {
    mocks.fetch.mockReset();
    mocks.axiosGet.mockReset();
    mocks.axiosPost.mockReset();
    vi.stubGlobal("fetch", mocks.fetch);
    window.history.replaceState({}, "", "/shop-owner/approvals?keep=1");
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it.each([
    ["order", orderRefund(12), "/shop-owner/refund-approvals?refund_type=order&refund=12&keep=1"],
    ["repair", repairRefund(12), "/shop-owner/refund-approvals?refund_type=repair&refund=12&keep=1"],
  ])("opens only the %s refund returned by the authoritative APIs", async (refundType, refund, url) => {
    window.history.replaceState({}, "", url);
    mocks.fetch
      .mockResolvedValueOnce(response(refundType === "order" ? [refund] : []))
      .mockResolvedValueOnce(response(refundType === "repair" ? [refund] : []));

    render(<RefundApproval />);

    expect(await screen.findByText("Refund Request Details")).toBeInTheDocument();
    expect(screen.getByText("Request #12")).toBeInTheDocument();
    expect(window.location.search).toBe("?keep=1");
  });

  it("opens the matching Expense after the scoped API response and preserves unrelated query state", async () => {
    window.history.replaceState({}, "", "/shop-owner/expense-approvals?expense=34&keep=1");
    mocks.axiosGet.mockResolvedValue({
      data: {
        data: [{
          id: 34,
          reference: "EXP-34",
          date: "2026-08-14",
          category: "Supplies",
          description: "Replacement supplies",
          amount: 125,
          status: "submitted",
        }],
      },
    });

    render(<ExpenseApproval />);

    expect(await screen.findByText("Expense Detail")).toBeInTheDocument();
    expect(screen.getAllByText("Supplies").length).toBeGreaterThan(0);
    expect(window.location.search).toBe("?keep=1");
  });

  it("opens the matching Purchase Request after the scoped API response", async () => {
    window.history.replaceState({}, "", "/shop-owner/purchase-request-approval?purchase_request=56");
    mocks.axiosGet.mockResolvedValue({
      data: {
        data: [{
          id: 56,
          pr_number: "PR-56",
          status: "pending_shop_owner",
          product_name: "Replacement soles",
          quantity: 2,
          unit_cost: 100,
          total_cost: 200,
          priority: "high",
          justification: "Restock the repair bench.",
        }],
      },
    });

    render(<PurchaseRequestApproval />);

    expect(await screen.findByText("Purchase Request Details")).toBeInTheDocument();
    expect(screen.getAllByText("Replacement soles").length).toBeGreaterThan(0);
    expect(window.location.search).toBe("");
  });

  it("does not manufacture a modal for a stale or absent refund", async () => {
    window.history.replaceState({}, "", "/shop-owner/refund-approvals?refund_type=order&refund=99");
    mocks.fetch
      .mockResolvedValueOnce(response([orderRefund(12)]))
      .mockResolvedValueOnce(response([]));

    render(<RefundApproval />);

    await waitFor(() => expect(screen.getByText("Refund Approvals")).toBeInTheDocument());
    expect(screen.queryByText("Refund Request Details")).not.toBeInTheDocument();
    expect(window.location.search).toBe("?refund_type=order&refund=99");
  });
});
