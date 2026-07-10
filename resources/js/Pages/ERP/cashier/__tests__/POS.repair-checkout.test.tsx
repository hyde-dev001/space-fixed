import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();
const axiosGetMock = vi.fn();
const axiosPostMock = vi.fn();
const swalFireMock = vi.fn();

vi.mock("axios", () => ({
  default: {
    get: (...args: unknown[]) => axiosGetMock(...args),
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
    axiosGetMock.mockReset();
    axiosPostMock.mockReset();
    swalFireMock.mockReset();

    usePageMock.mockReturnValue({
      props: {
        auth: {
          user: {
            shop_owner: {
              business_type: "both",
              repair_payment_policy: "deposit_50",
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
    axiosGetMock.mockImplementation((url: string) => Promise.resolve({
      data: {
        data: url === "/api/repair-services" ? [{ id: 1, name: "Sole reglue", price: 1000, category: "Repair" }] : [],
      },
    }));

    swalFireMock.mockResolvedValue({ isConfirmed: true });
  });

  it("submits walk-in repair checkout payload to repair-pos endpoint", async () => {
    render(<CashierPOS />);

    fireEvent.change(screen.getByTitle(/customer name/i), {
      target: { value: "Walk In Customer" },
    });

    fireEvent.change(screen.getByTitle(/customer phone number/i), { target: { value: "09171234567" } });
    fireEvent.click(await screen.findByRole("button", { name: /sole reglue/i }));
    fireEvent.change(screen.getByTitle(/cash received/i), { target: { value: "500" } });
    fireEvent.click(screen.getByRole("button", { name: "Pay" }));

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
