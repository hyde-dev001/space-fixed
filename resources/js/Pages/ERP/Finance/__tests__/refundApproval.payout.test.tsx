import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import RefundApproval from "../refundApproval";

const mocks = vi.hoisted(() => ({
  fetch: vi.fn(),
  swal: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      auth: {
        user: { role: "Finance" },
        permissions: ["access-refund-approval"],
      },
    },
  }),
}));

vi.mock("sweetalert2", () => ({ default: { fire: mocks.swal } }));

const response = (data: unknown, status = 200) => Promise.resolve({
  ok: status >= 200 && status < 300,
  status,
  json: async () => data,
});

beforeEach(() => {
  vi.clearAllMocks();
  mocks.swal.mockResolvedValue({ isConfirmed: false });
  mocks.fetch.mockImplementation((url: string) => {
    if (url.startsWith("/api/finance/refunds?")) {
      return response({
        data: [{
          id: 11,
          orderNumber: "ORD-11",
          customerName: "Miguel Dela Rosa",
          refundAmount: "₱2,607.00",
          refundAmountValue: 2607,
          payoutAmount: "₱2,499.00",
          payoutAmountValue: 2499,
          refundMethod: "Original Payment Method",
          requestedBy: "Miguel Dela Rosa",
          requestDate: "2026-07-30",
          refundReason: "Product defective or damaged",
          reason: "Product defective or damaged",
          status: "Approved",
          rawStatus: "pending_approval",
          shopOwnerStatus: "approved",
          financeStatus: "approved",
          returnStatus: "received",
          media: [],
        }],
      });
    }
    if (url.startsWith("/api/finance/repair-refunds?")) return response({ data: [] });
    if (url.startsWith("/api/finance/repair-delivery-reconciliations?")) return response({ data: [] });
    throw new Error(`Unexpected fetch ${url}`);
  });
  vi.stubGlobal("fetch", mocks.fetch);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("Finance canonical retail refund payout", () => {
  it("uses the shipping-excluded payout in the list, details, and execution confirmation", async () => {
    render(<RefundApproval />);

    const viewButton = await screen.findByTitle("View Details");
    expect(screen.getByText("₱2,499.00")).toBeInTheDocument();
    expect(screen.queryByText("₱2,607.00")).not.toBeInTheDocument();

    expect(screen.getByText("Executable Payouts")).toBeInTheDocument();
    expect(screen.getByText("Payout Ready")).toBeInTheDocument();

    fireEvent.click(viewButton);
    expect(await screen.findByRole("heading", { name: "Refund Request Details" })).toBeInTheDocument();
    expect(screen.getAllByText("₱2,499.00").length).toBeGreaterThanOrEqual(2);

    fireEvent.click(screen.getByRole("button", { name: "Execute Payout" }));
    await waitFor(() => expect(mocks.swal).toHaveBeenCalled());
    const confirmation = mocks.swal.mock.calls.at(-1)?.[0];
    expect(confirmation.html).toContain("₱2,499.00");
    expect(confirmation.html).not.toContain("₱2,607.00");
  });

  it("shows the service-only repair refund breakdown without either shipping leg", async () => {
    mocks.fetch.mockImplementation((url: string) => {
      if (url.startsWith("/api/finance/refunds?")) return response({ data: [] });
      if (url.startsWith("/api/finance/repair-refunds?")) {
        return response({
          data: [{
            id: 12,
            orderNumber: "REP-12",
            customerName: "Miguel Dela Rosa",
            refundType: "repair",
            refundAmount: "₱300.00",
            refundAmountValue: 300,
            refundMethod: "GCash",
            requestedBy: "Miguel Dela Rosa",
            requestDate: "2026-08-01",
            refundReason: "Repair refund",
            reason: "Repair refund",
            status: "Refunded",
            rawStatus: "succeeded",
            refundPaymentType: "pure_online",
            repairerStatus: "approved",
            financeStatus: "approved",
            shopOwnerStatus: "skipped",
            financeExecution: { execution_amount: 300 },
            refundComponents: {
              repair_service: { label: "Repair / Service Refund", refunded_amount: 300, status: "refunded" },
              pickup_intake: { label: "Pickup / Intake Fee Refund", refunded_amount: 0, status: "not_refunded" },
              return_delivery: { label: "Return / Delivery Fee Refund", refunded_amount: 0, status: "not_refunded" },
            },
          }],
        });
      }
      if (url.startsWith("/api/finance/repair-delivery-reconciliations?")) return response({ data: [] });
      throw new Error(`Unexpected fetch ${url}`);
    });

    render(<RefundApproval />);

    expect(await screen.findByText("₱300.00")).toBeInTheDocument();
    fireEvent.click(await screen.findByTitle("View Details"));

    expect(await screen.findByText("Refund Components")).toBeInTheDocument();
    expect(screen.getByText("Repair / Service Refund")).toBeInTheDocument();
    expect(screen.getByText("Pickup / Intake Fee Refund")).toBeInTheDocument();
    expect(screen.getByText("Return / Delivery Fee Refund")).toBeInTheDocument();
  });
});
