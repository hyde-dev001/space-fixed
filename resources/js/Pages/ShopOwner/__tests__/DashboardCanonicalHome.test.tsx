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

const ownerUrgentExceptions: OwnerActionCenterResult = {
  ...ownerActionCenter,
  items: [{
    ...ownerActionCenter.items[0],
    attention_key: "compliance_document:9:document_expiry",
    source_type: "compliance_document",
    source_id: 9,
    category: "document_expiry",
    primary_bucket: "urgent_exceptions",
    module: "compliance",
    title: "Mayor's Permit expiry",
    concise_summary: "No renewal is currently assigned.",
    comparable_monetary_exposure: null,
    waiting_on: "none",
    owner_action_required: false,
    coverage_source: "compliance",
    destination_url: "/shop-owner/settings/policies-compliance",
  }],
  coverage_counts: { compliance: 1, refunds: 0, logistics: 0 },
  health: {
    enabled_adapter_keys: ["compliance_documents"],
    healthy_adapter_keys: ["compliance_documents"],
    failed_adapter_keys: [],
  },
  bucket: "urgent_exceptions",
};

const ownerWaitingOnOthers: OwnerActionCenterResult = {
  ...ownerActionCenter,
  items: [1, 2, 3, 4].map((id) => ({
    ...ownerActionCenter.items[0],
    attention_key: `order_refund:${id}:refund_recovery_waiting`,
    source_type: "order_refund" as const,
    source_id: id,
    category: "refund_recovery_waiting",
    primary_bucket: "waiting_on_others" as const,
    title: `Refund recovery ${id}`,
    concise_summary: "Payment recovery owns the next step.",
    waiting_on: "payment_recovery" as const,
    owner_action_required: false,
    coverage_source: "refunds" as const,
    destination_url: `/shop-owner/refund-approvals?refund=${id}`,
  })),
  coverage_counts: { compliance: 0, refunds: 4, logistics: 0 },
  health: {
    enabled_adapter_keys: ["waiting_order_refund_recovery"],
    healthy_adapter_keys: ["waiting_order_refund_recovery"],
    failed_adapter_keys: [],
  },
  bucket: "waiting_on_others",
  pagination: { page: 1, per_page: 3, total: 4, last_page: 2 },
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

  it("renders decisions and urgent exceptions as separate bounded summaries", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
      ownerUrgentExceptions,
    };

    render(<Dashboard />);

    expect(await screen.findByText("Owner Actions")).toBeInTheDocument();
    expect(screen.getAllByText("Needs My Decision").length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText("Urgent Exceptions").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("Supplier expense")).toBeInTheDocument();
    expect(screen.getByText("Mayor's Permit expiry")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /View all urgent exceptions/i })).toHaveAttribute(
      "href",
      expect.stringContaining("bucket=urgent_exceptions"),
    );
    expect(screen.queryByText(/^Exceptions \u2014 Coming in Phase 3$/)).not.toBeInTheDocument();
  });

  it("renders three independent summaries with no more than three rows per bucket", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
      ownerUrgentExceptions,
      ownerWaitingOnOthers,
    };

    render(<Dashboard />);

    expect((await screen.findAllByText("Waiting on Others")).length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText("Refund recovery 1")).toBeInTheDocument();
    expect(screen.getByText("Refund recovery 2")).toBeInTheDocument();
    expect(screen.getByText("Refund recovery 3")).toBeInTheDocument();
    expect(screen.queryByText("Refund recovery 4")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: /View all waiting items/i })).toHaveAttribute(
      "href",
      expect.stringContaining("bucket=waiting_on_others"),
    );
  });

  it("renders a Logistics exception in the urgent summary without adding an owner action", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
      ownerUrgentExceptions: {
        ...ownerUrgentExceptions,
        items: [{
          ...ownerUrgentExceptions.items[0],
          attention_key: "logistics_failure:17:unowned_delivery_failure",
          source_type: "logistics_failure",
          source_id: 17,
          category: "unowned_delivery_failure",
          module: "logistics",
          title: "Failed delivery needs escalation",
          concise_summary: "Delivery recovery is exhausted and has no active responsible party.",
          coverage_source: "logistics",
          destination_url: "/shop-owner/logistics/shipments?shipment=8&leg=17",
        }],
        coverage_counts: { compliance: 0, refunds: 0, logistics: 1 },
        health: {
          enabled_adapter_keys: ["unowned_logistics_failures"],
          healthy_adapter_keys: ["unowned_logistics_failures"],
          failed_adapter_keys: [],
        },
      },
    };

    render(<Dashboard />);

    expect(await screen.findByText("Failed delivery needs escalation")).toBeInTheDocument();
    expect(screen.getByText("Logistics Failure")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /Open workflow/i }).some((link) => (
      link.getAttribute("href") === "/shop-owner/logistics/shipments?shipment=8&leg=17"
    ))).toBe(true);
    expect(screen.queryByRole("button", { name: /dismiss|hide|acknowledge|snooze|resolve/i })).not.toBeInTheDocument();
  });
});
