import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const usePageMock = vi.fn();

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => usePageMock(),
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import CashierPOS from "../POS";

const renderWithBusinessType = (businessType: string) => {
  usePageMock.mockReturnValue({
    props: {
      auth: {
        user: {
          shop_owner: {
            business_type: businessType,
          },
        },
      },
    },
  });

  return render(<CashierPOS />);
};

describe("Cashier POS mode switch", () => {
  beforeEach(() => {
    usePageMock.mockReset();
  });

  it("prioritizes shop_owner guard business type over user guard fallback", () => {
    usePageMock.mockReturnValue({
      props: {
        auth: {
          shop_owner: {
            business_type: "retail",
          },
          user: {
            shop_owner: {
              business_type: "both",
            },
          },
        },
      },
    });

    render(<CashierPOS />);

    expect(screen.queryByRole("button", { name: /repair mode/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /retail mode/i })).toBeInTheDocument();
    expect(screen.getByTestId("retail-pos-mode")).toBeInTheDocument();
  });

  it("renders Repair and Retail tabs for both business type", () => {
    renderWithBusinessType("both");

    expect(screen.getByRole("button", { name: /repair mode/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /retail mode/i })).toBeInTheDocument();
    expect(screen.getByTestId("repair-pos-mode")).toBeInTheDocument();
  });

  it("renders only Retail tab for retail business type", () => {
    renderWithBusinessType("retail");

    expect(screen.queryByRole("button", { name: /repair mode/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /retail mode/i })).toBeInTheDocument();
    expect(screen.getByTestId("retail-pos-mode")).toBeInTheDocument();
  });
});
