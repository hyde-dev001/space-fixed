import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();
const axiosPostMock = vi.fn();
const swalFireMock = vi.fn();

vi.mock("axios", () => ({
  default: {
    post: (...args: unknown[]) => axiosPostMock(...args),
  },
}));

vi.mock("sweetalert2", () => ({
  default: {
    fire: (...args: unknown[]) => swalFireMock(...args),
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

describe("Cashier POS repair checkout", () => {
  beforeEach(() => {
    usePageMock.mockReset();
    axiosPostMock.mockReset();
    swalFireMock.mockReset();

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

    axiosPostMock.mockResolvedValue({
      data: {
        transaction_id: 123,
        transaction_no: "POS-TEST-123",
      },
    });

    swalFireMock.mockResolvedValue({ isConfirmed: true });
  });

  it("submits walk-in repair checkout payload to repair-pos endpoint", async () => {
    render(<CashierPOS />);

    fireEvent.change(screen.getByLabelText(/walk-in customer name/i), {
      target: { value: "Walk In Customer" },
    });

    fireEvent.change(screen.getByLabelText(/repair subtotal/i), {
      target: { value: "1000" },
    });

    fireEvent.change(screen.getByLabelText(/amount to collect/i), {
      target: { value: "500" },
    });

    fireEvent.click(screen.getByRole("button", { name: /process walk-in repair checkout/i }));

    await waitFor(() => {
      expect(axiosPostMock).toHaveBeenCalledTimes(1);
    });

    const [url, payload] = axiosPostMock.mock.calls[0] as [string, Record<string, unknown>];

    expect(url).toBe("/api/repair-pos/checkout");
    expect(payload.customer_type).toBe("walk_in");
    expect(payload.walk_in_name).toBe("Walk In Customer");
    expect(payload.manual_repair_subtotal).toBe(1000);
    expect(payload.manual_payment_policy).toBe("deposit_50");
    expect(payload.due_type).toBe("deposit");
    expect(Array.isArray(payload.payment_lines)).toBe(true);
  });
});
