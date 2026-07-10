import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();
const axiosGetMock = vi.fn();
const axiosPostMock = vi.fn();
const axiosPatchMock = vi.fn();
const swalFireMock = vi.fn();
const swalShowValidationMessageMock = vi.fn();

let historyRows: any[] = [];

vi.mock("axios", () => ({
  default: {
    get: (...args: unknown[]) => axiosGetMock(...args),
    post: (...args: unknown[]) => axiosPostMock(...args),
    patch: (...args: unknown[]) => axiosPatchMock(...args),
  },
}));

vi.mock("sweetalert2", () => ({
  default: {
    fire: (...args: unknown[]) => swalFireMock(...args),
    showValidationMessage: (...args: unknown[]) => swalShowValidationMessageMock(...args),
  },
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => usePageMock(),
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import CashierPOS from "../POS";

const buildRepairHistoryRow = (overrides: Record<string, unknown> = {}) => ({
  id: 10,
  module_type: "repair",
  module_reference_id: 321,
  repair_request: { status: "received" },
  customer_type: "walk_in",
  walk_in_name: "Walk In Customer",
  walk_in_phone: "09171234567",
  due_type: "full",
  paid_amount: 500,
  subtotal: 500,
  discount_amount: 0,
  tax_amount: 0,
  total_amount: 500,
  payment_lines: [{ tender_type: "cash" }],
  refunds: [],
  receipt: {
    receipt_no: "RCP-TEST-001",
    issued_at: "2026-04-12T10:00:00.000Z",
    print_payload: {
      customer: {
        name: "Walk In Customer",
        phone: "09171234567",
      },
      items: [{ label: "Sole reglue", qty: 1, unitPrice: 500 }],
      totals: { paid: 500, subtotal: 500, discount: 0, tax: 0, total: 500 },
    },
  },
  ...overrides,
});

describe("Cashier POS warranty UI", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    axiosGetMock.mockReset();
    axiosPostMock.mockReset();
    axiosPatchMock.mockReset();
    swalFireMock.mockReset();
    swalShowValidationMessageMock.mockReset();
    historyRows = [];

    usePageMock.mockReturnValue({
      props: {
        auth: {
          user: {
            shop_owner: {
              business_type: "both",
            },
          },
        },
      },
    });

    axiosGetMock.mockImplementation((url: string) => {
      if (url === "/api/repair-pos/transactions") {
        return Promise.resolve({ data: { data: { data: historyRows } } });
      }

      if (
        url === "/api/repair-services"
        || url === "/api/repair-requests"
        || url === "/api/repair-packages"
        || url === "/api/repair-pos/manual-queue"
        || url === "/api/retail-pos/products"
      ) {
        return Promise.resolve({ data: { data: [] } });
      }

      return Promise.resolve({ data: { data: [] } });
    });

    swalFireMock.mockResolvedValue({ isConfirmed: false });
    axiosPostMock.mockResolvedValue({ data: {} });
    axiosPatchMock.mockResolvedValue({ data: {} });
  });

  it("shows Warranty button for eligible walk-in repair receipts in history", async () => {
    historyRows = [buildRepairHistoryRow()];

    render(<CashierPOS />);

    fireEvent.click(screen.getByRole("button", { name: /history/i }));

    await waitFor(() => {
      expect(screen.getByText("RCP-TEST-001")).toBeInTheDocument();
    });

    expect(screen.getByRole("button", { name: "Warranty" })).toBeInTheDocument();
  });

  it("hides Warranty button for ineligible registered-customer repair receipts", async () => {
    historyRows = [buildRepairHistoryRow({ customer_type: "registered" })];

    render(<CashierPOS />);

    fireEvent.click(screen.getByRole("button", { name: /history/i }));

    await waitFor(() => {
      expect(screen.getByText("RCP-TEST-001")).toBeInTheDocument();
    });

    expect(screen.queryByRole("button", { name: "Warranty" })).not.toBeInTheDocument();
  });

  it("validates warranty modal and requires at least one evidence image", async () => {
    historyRows = [buildRepairHistoryRow()];

    swalFireMock.mockImplementation(async (config: any) => {
      if (config?.title === "File Warranty Claim" && typeof config.preConfirm === "function") {
        const reasonCode = document.createElement("select");
        reasonCode.id = "pos_warranty_reason_code";
        const reasonOption = document.createElement("option");
        reasonOption.value = "issue_returned";
        reasonOption.selected = true;
        reasonCode.appendChild(reasonOption);

        const reasonDetails = document.createElement("textarea");
        reasonDetails.id = "pos_warranty_reason_details";

        const returnMethod = document.createElement("select");
        returnMethod.id = "pos_warranty_return_method";
        const methodOption = document.createElement("option");
        methodOption.value = "walk_in";
        methodOption.selected = true;
        returnMethod.appendChild(methodOption);

        const images = document.createElement("input");
        images.id = "pos_warranty_images";
        images.type = "file";

        document.body.append(reasonCode, reasonDetails, returnMethod, images);

        await config.preConfirm();
        return { isConfirmed: false };
      }

      return { isConfirmed: false };
    });

    render(<CashierPOS />);

    fireEvent.click(screen.getByRole("button", { name: /history/i }));

    const warrantyButton = await screen.findByRole("button", { name: "Warranty" });
    fireEvent.click(warrantyButton);

    await waitFor(() => {
      expect(swalShowValidationMessageMock).toHaveBeenCalledWith("Please upload at least one image.");
    });
  });
});
