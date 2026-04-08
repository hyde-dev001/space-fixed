import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      auth: {
        user: {
          name: "Retail Staff",
          shop_owner: { business_type: "retail" },
        },
      },
    },
  }),
}));

vi.mock("../../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import RetailPOSPage from "../RetailPOS";

describe("Retail POS page", () => {
  it("renders retail-only content", () => {
    render(<RetailPOSPage />);

    expect(screen.getByText("Point of Sale")).toBeInTheDocument();
    expect(screen.getByText("Retail Catalog")).toBeInTheDocument();
    expect(screen.queryByText("Attach From Repair Orders")).not.toBeInTheDocument();
  });
});
