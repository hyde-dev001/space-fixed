import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

vi.mock("../../../../services/retailPosApi", () => ({
  retailPosApi: {
    history: vi.fn().mockResolvedValue({
      data: {
        data: [
          {
            id: 101,
            order_number: "RPOS-20260408-0001",
            customer_name: "Walk In Buyer",
            payment_method: "cash",
          },
        ],
      },
    }),
  },
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      auth: {
        user: {
          name: "Retail Staff",
          shop_owner: { business_type: "both" },
        },
      },
    },
  }),
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import RetailPOSPage from "../RetailPOS";

describe("Retail POS history", () => {
  it("renders recent retail transactions from API", async () => {
    render(<RetailPOSPage />);

    expect(screen.getByText("Recent Transactions")).toBeInTheDocument();
    expect(await screen.findByText("RPOS-20260408-0001")).toBeInTheDocument();
    expect(screen.queryByText("No transactions yet.")).not.toBeInTheDocument();
  });
});
