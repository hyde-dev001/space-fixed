import React from "react";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  fetch: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({ props: mocks.props }),
}));

vi.mock("../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock("../../../layout/AppLayout_ERP", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock("../../../components/ecommerce/EcommerceMetrics", () => ({
  default: () => <div>Existing dashboard metrics</div>,
}));

vi.mock("../../../components/ecommerce/MonthlySalesChart", () => ({
  default: () => <div>Existing sales chart</div>,
}));

vi.mock("../../../components/ecommerce/MonthlyTarget", () => ({
  default: () => <div>Existing monthly target</div>,
}));

vi.mock("../../../components/ecommerce/StatisticsChart", () => ({
  default: () => <div>Existing statistics chart</div>,
}));

vi.mock("../../../components/ecommerce/RecentOrders", () => ({
  default: () => <div>Existing recent orders</div>,
}));

vi.mock("sweetalert2", () => ({
  default: { fire: vi.fn() },
}));

import Dashboard from "../Dashboard";

describe("canonical shop owner home placeholders", () => {
  beforeEach(() => {
    mocks.props = {
      auth: {
        shop_owner: {
          business_type: "both",
          registration_type: "company",
        },
      },
      erpMode: false,
      showPhaseThreePlaceholders: true,
    };
    mocks.fetch.mockReset();
    mocks.fetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        revenue: { this_month: 0, last_month: 0 },
        revenue_trend: [],
        recent_orders: [],
      }),
    });
    vi.stubGlobal("fetch", mocks.fetch);
  });

  it("renders subordinate informational placeholders alongside existing dashboard metrics", async () => {
    render(<Dashboard />);

    expect(await screen.findByText("Required Actions — Coming in Phase 3")).toBeInTheDocument();
    expect(screen.getByText("Exceptions — Coming in Phase 3")).toBeInTheDocument();
    expect(screen.getByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.getByText(/existing module and approval pages remain the current action surfaces/i)).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /approval/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /required actions|exceptions/i })).not.toBeInTheDocument();
  });

  it("does not render placeholders for the existing dashboard presentation", async () => {
    mocks.props = {
      ...mocks.props,
      showPhaseThreePlaceholders: false,
    };

    render(<Dashboard />);

    expect(await screen.findByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.queryByText("Required Actions — Coming in Phase 3")).not.toBeInTheDocument();
    expect(screen.queryByText("Exceptions — Coming in Phase 3")).not.toBeInTheDocument();
  });
});
