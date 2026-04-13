import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();
const axiosGetMock = vi.fn();
const axiosPostMock = vi.fn();
const axiosPatchMock = vi.fn();
const swalFireMock = vi.fn();
const swalShowValidationMessageMock = vi.fn();

let manualQueueRows: any[] = [];

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

vi.mock("../../../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import ShopOwnerPOS from "../POS";

const buildManualQueueRow = (overrides: Record<string, unknown> = {}) => ({
  id: 321,
  request_id: "REP-POS-QUEUE-001",
  customer_name: "Walk In Customer",
  phone: "09171234567",
  status: "pending",
  payment_policy: "deposit_50",
  total: 1000,
  paid: 500,
  remaining_balance: 500,
  next_due_type: "balance",
  receipt_no: "RCP-QUEUE-001",
  ...overrides,
});

describe("Shop owner POS warranty UI", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    axiosGetMock.mockReset();
    axiosPostMock.mockReset();
    axiosPatchMock.mockReset();
    swalFireMock.mockReset();
    swalShowValidationMessageMock.mockReset();
    manualQueueRows = [];

    usePageMock.mockReturnValue({
      props: {
        auth: {
          shop_owner: {
            business_type: "both",
          },
        },
      },
    });

    axiosGetMock.mockImplementation((url: string) => {
      if (url === "/api/repair-pos/manual-queue") {
        return Promise.resolve({ data: { data: manualQueueRows } });
      }

      if (
        url === "/api/repair-services"
        || url === "/api/repair-requests"
        || url === "/api/repair-packages"
        || url === "/api/repair-pos/transactions"
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

  it("shows enabled Warranty button in manual queue when receipt and phone are present", async () => {
    manualQueueRows = [buildManualQueueRow()];

    render(<ShopOwnerPOS />);

    await waitFor(() => {
      expect(screen.getByText("REP-POS-QUEUE-001")).toBeInTheDocument();
    });

    const warrantyButton = screen.getByRole("button", { name: "Warranty" });
    expect(warrantyButton).toBeInTheDocument();
    expect(warrantyButton).toBeEnabled();
  });

  it("shows disabled Warranty button in manual queue when receipt or phone is missing", async () => {
    manualQueueRows = [buildManualQueueRow({ receipt_no: null, phone: "" })];

    render(<ShopOwnerPOS />);

    await waitFor(() => {
      expect(screen.getByText("REP-POS-QUEUE-001")).toBeInTheDocument();
    });

    const warrantyButton = screen.getByRole("button", { name: "Warranty" });
    expect(warrantyButton).toBeDisabled();
  });

  it("validates shop-owner warranty modal and requires at least one evidence image", async () => {
    manualQueueRows = [buildManualQueueRow()];

    swalFireMock.mockImplementation(async (config: any) => {
      if (config?.title === "File Warranty Claim" && typeof config.preConfirm === "function") {
        const reasonCode = document.createElement("select");
        reasonCode.id = "shop_owner_pos_warranty_reason_code";
        const reasonOption = document.createElement("option");
        reasonOption.value = "issue_returned";
        reasonOption.selected = true;
        reasonCode.appendChild(reasonOption);

        const reasonDetails = document.createElement("textarea");
        reasonDetails.id = "shop_owner_pos_warranty_reason_details";

        const returnMethod = document.createElement("select");
        returnMethod.id = "shop_owner_pos_warranty_return_method";
        const methodOption = document.createElement("option");
        methodOption.value = "walk_in";
        methodOption.selected = true;
        returnMethod.appendChild(methodOption);

        const images = document.createElement("input");
        images.id = "shop_owner_pos_warranty_images";
        images.type = "file";

        document.body.append(reasonCode, reasonDetails, returnMethod, images);

        await config.preConfirm();
        return { isConfirmed: false };
      }

      return { isConfirmed: false };
    });

    render(<ShopOwnerPOS />);

    const warrantyButton = await screen.findByRole("button", { name: "Warranty" });
    fireEvent.click(warrantyButton);

    await waitFor(() => {
      expect(swalShowValidationMessageMock).toHaveBeenCalledWith("Please upload at least one image.");
    });
  });
});
