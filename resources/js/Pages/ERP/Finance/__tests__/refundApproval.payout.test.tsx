import React from "react";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import RefundApproval from "../refundApproval";

const pageSource = readFileSync(resolve(__dirname, "../refundApproval.tsx"), "utf8");

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
  it("does not accept a manually typed repair payout amount", () => {
    expect(pageSource).toContain("Amount is fixed based on the approved refund amount.");
    expect(pageSource).not.toContain('formData.append("execution_amount"');
    expect(pageSource).not.toContain("Execution amount must be greater than zero.");
  });

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

  it("reveals and hides a COD refund destination only after an explicit Finance action", async () => {
    mocks.fetch.mockImplementation((url: string) => {
      if (url.startsWith("/api/finance/refunds?")) {
        return response({
          data: [{
            id: 13,
            orderNumber: "ORD-13",
            customerName: "John Paul Yambai",
            refundAmount: "₱1,500.00",
            refundAmountValue: 1500,
            payoutAmount: "₱1,500.00",
            payoutAmountValue: 1500,
            refundMethod: "Customer Selected",
            requestedBy: "John Paul Yambai",
            requestDate: "2026-09-19",
            refundReason: "Delivery Dispute",
            reason: "Delivery Dispute",
            status: "Approved",
            rawStatus: "pending_approval",
            isCod: true,
            shopOwnerStatus: "approved",
            financeStatus: "approved",
            returnStatus: "received",
            refundDestinationType: "e_wallet",
            refundDestination: {
              account_name: "John Paul Yambai",
              channel: "GCash",
              account_number: "*******6785",
            },
            payoutStatus: "not_started",
            canExecutePayout: true,
            media: [],
          }],
        });
      }
      if (url === "/api/finance/refunds/13/destination/reveal") {
        return response({
          destination: {
            account_name: "John Paul Yambai",
            channel: "GCash",
            channel_code: "PH_GCASH",
            account_number: "09123456785",
          },
        });
      }
      if (url.startsWith("/api/finance/repair-refunds?")) return response({ data: [] });
      if (url.startsWith("/api/finance/repair-delivery-reconciliations?")) return response({ data: [] });
      throw new Error(`Unexpected fetch ${url}`);
    });

    render(<RefundApproval />);

    fireEvent.click(await screen.findByTitle("View Details"));
    expect(screen.getByText(/account number: \*\*\*\*\*\*\*6785/)).toBeInTheDocument();
    const showButton = screen.getByRole("button", { name: "Show full refund destination" });
    fireEvent.click(showButton);

    await waitFor(() => expect(screen.getByText(/account number: 09123456785/)).toBeInTheDocument());
    expect(mocks.fetch).toHaveBeenCalledWith(
      "/api/finance/refunds/13/destination/reveal",
      expect.objectContaining({ credentials: "include", headers: { Accept: "application/json" } }),
    );

    fireEvent.click(screen.getByRole("button", { name: "Hide full refund destination" }));
    expect(screen.queryByText(/account number: 09123456785/)).not.toBeInTheDocument();
    expect(screen.getByText(/account number: \*\*\*\*\*\*\*6785/)).toBeInTheDocument();
  });
});
