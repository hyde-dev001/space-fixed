import React from "react";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { OwnerActionCenterResult } from "../../../types/ownerActionCenter";

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

const ownerActionCenter: OwnerActionCenterResult = {
  items: [
    {
      attention_key: "expense:12:owner_approval",
      source_type: "expense",
      source_id: 12,
      category: "expense_approval",
      primary_bucket: "needs_my_decision",
      module: "finance",
      title: "Supplier expense",
      concise_summary: "Review the submitted supplier expense.",
      priority_tier: "high",
      materiality_tier: "high",
      comparable_monetary_exposure: 450,
      urgency_at: null,
      actionable_since: "2026-08-14T09:00:00+08:00",
      waiting_on: "shop_owner",
      owner_action_required: true,
      coverage_source: "expenses",
      destination_url: "/shop-owner/expense-approvals?expense=12",
    },
  ],
  coverage_counts: { refunds: 0, expenses: 1, purchase_requests: 0 },
  health: {
    enabled_adapter_keys: ["expenses"],
    healthy_adapter_keys: ["expenses"],
    failed_adapter_keys: [],
  },
  degradation_status: "none",
  bucket: "needs_my_decision",
  coverage: "all",
  pagination: { page: 1, per_page: 5, total: 1, last_page: 1 },
};

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

    expect(await screen.findByText("Required Actions \u2014 Coming in Phase 3")).toBeInTheDocument();
    expect(screen.getByText("Exceptions \u2014 Coming in Phase 3")).toBeInTheDocument();
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
    expect(screen.queryByText("Required Actions \u2014 Coming in Phase 3")).not.toBeInTheDocument();
    expect(screen.queryByText("Exceptions \u2014 Coming in Phase 3")).not.toBeInTheDocument();
  });

  it("replaces only Required Actions with the bounded decision summary", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
    };

    render(<Dashboard />);

    expect(await screen.findByText("Owner Actions")).toBeInTheDocument();
    expect(screen.getAllByText("Needs My Decision").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("Supplier expense")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /View all/i })).toHaveAttribute(
      "href",
      "/shop-owner/action-center",
    );
    expect(screen.getByText(/^Exceptions/)).toBeInTheDocument();
    expect(screen.queryByText(/^Required Actions/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /approve|reject/i })).not.toBeInTheDocument();
  });
});
