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

describe("canonical shop owner home approval summary", () => {
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

  it("keeps the approval center out of the canonical home", async () => {
    render(<Dashboard />);

    expect(await screen.findByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.queryByText("Required Actions \u2014 Coming in Phase 3")).not.toBeInTheDocument();
    expect(screen.queryByText(/Urgent Exceptions/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Waiting on Others/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /urgent exceptions|waiting items/i })).not.toBeInTheDocument();
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

  it("does not render an approval summary when the legacy prop is present", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
    };

    render(<Dashboard />);

    expect(await screen.findByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.queryByText("Owner Actions")).not.toBeInTheDocument();
    expect(screen.queryByText("Supplier expense")).not.toBeInTheDocument();
    expect(screen.queryByText(/^Exceptions/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Urgent Exceptions/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Waiting on Others/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/^Required Actions/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /approve|reject/i })).not.toBeInTheDocument();
  });

  it("does not render a stale urgent-exception prop", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
      ownerUrgentExceptions,
    };

    render(<Dashboard />);

    expect(await screen.findByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.queryByText("Mayor's Permit expiry")).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /View all urgent exceptions/i })).not.toBeInTheDocument();
    expect(screen.queryByText(/^Exceptions \u2014 Coming in Phase 3$/)).not.toBeInTheDocument();
  });

  it("does not render a stale waiting-on-others prop", async () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter,
      ownerUrgentExceptions,
      ownerWaitingOnOthers,
    };

    render(<Dashboard />);

    expect(await screen.findByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.queryByText("Waiting on Others")).not.toBeInTheDocument();
    expect(screen.queryByText(/Refund recovery/)).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /View all waiting items/i })).not.toBeInTheDocument();
  });

  it("does not surface stale logistics exceptions on the home page", async () => {
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

    expect(await screen.findByText("Existing dashboard metrics")).toBeInTheDocument();
    expect(screen.queryByText("Failed delivery needs escalation")).not.toBeInTheDocument();
    expect(screen.queryByText("Logistics Failure")).not.toBeInTheDocument();
    expect(screen.queryAllByRole("link", { name: /Open workflow/i })).toHaveLength(0);
    expect(screen.queryByRole("button", { name: /dismiss|hide|acknowledge|snooze|resolve/i })).not.toBeInTheDocument();
  });
});
