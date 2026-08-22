import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";
import ActionCenter from "../ActionCenter";
import type { OwnerActionCenterResult, OwnerAttentionItem } from "../../../types/ownerActionCenter";

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  reload: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  usePage: () => ({ props: mocks.props }),
  router: { reload: mocks.reload },
}));

vi.mock("../../../layout/AppLayout_shopOwner", () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

const item = (overrides: Partial<OwnerAttentionItem> = {}): OwnerAttentionItem => ({
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
  ...overrides,
});

const result = (overrides: Partial<OwnerActionCenterResult> = {}): OwnerActionCenterResult => ({
  items: [item()],
  coverage_counts: {
    refunds: 0,
    prices: 0,
    payslips: 0,
    salary_changes: 0,
    expenses: 1,
    purchase_requests: 0,
    repair_rejections: 0,
  },
  health: {
    enabled_adapter_keys: [
      "order_refunds",
      "repair_refunds",
      "price_approvals",
      "payslips",
      "salary_changes",
      "expenses",
      "purchase_requests",
      "repair_rejections",
    ],
    healthy_adapter_keys: [
      "order_refunds",
      "repair_refunds",
      "price_approvals",
      "payslips",
      "salary_changes",
      "expenses",
      "purchase_requests",
      "repair_rejections",
    ],
    failed_adapter_keys: [],
  },
  degradation_status: "none",
  bucket: "needs_my_decision",
  coverage: "all",
  pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
  ...overrides,
});

const exceptionResult = (overrides: Partial<OwnerActionCenterResult> = {}): OwnerActionCenterResult => result({
  items: [item({
    attention_key: "compliance_document:9:document_expiry",
    source_type: "compliance_document",
    source_id: 9,
    category: "document_expiry",
    primary_bucket: "urgent_exceptions",
    module: "compliance",
    title: "Mayor's Permit expiry",
    concise_summary: "This document is within its renewal window and has no pending renewal.",
    priority_tier: "high",
    materiality_tier: "high",
    comparable_monetary_exposure: null,
    urgency_at: "2026-08-21T00:00:00+08:00",
    actionable_since: "2026-07-22T00:00:00+08:00",
    waiting_on: "none",
    owner_action_required: false,
    coverage_source: "compliance",
    destination_url: "/shop-owner/settings/policies-compliance",
  })],
  coverage_counts: { compliance: 1, refunds: 0, logistics: 0 },
  health: {
    enabled_adapter_keys: ["compliance_documents"],
    healthy_adapter_keys: ["compliance_documents"],
    failed_adapter_keys: [],
  },
  bucket: "urgent_exceptions",
  coverage: "all",
  pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
  ...overrides,
});

const waitingResult = (overrides: Partial<OwnerActionCenterResult> = {}): OwnerActionCenterResult => result({
  items: [item({
    attention_key: "order_refund:21:refund_recovery_waiting",
    source_type: "order_refund",
    source_id: 21,
    category: "refund_recovery_waiting",
    primary_bucket: "waiting_on_others",
    module: "retail",
    title: "Refund recovery in progress",
    concise_summary: "Payment recovery owns the next step for this failed refund.",
    priority_tier: "high",
    materiality_tier: "high",
    comparable_monetary_exposure: 725,
    urgency_at: null,
    actionable_since: "2026-08-14T09:00:00+08:00",
    waiting_on: "payment_recovery",
    owner_action_required: false,
    coverage_source: "refunds",
    destination_url: "/shop-owner/refund-approvals?refund=21",
  })],
  coverage_counts: { compliance: 0, refunds: 1, logistics: 0 },
  health: {
    enabled_adapter_keys: [
      "pending_compliance_renewals",
      "waiting_order_refund_recovery",
      "waiting_repair_refund_recovery",
      "active_logistics_recovery",
    ],
    healthy_adapter_keys: [
      "pending_compliance_renewals",
      "waiting_order_refund_recovery",
      "waiting_repair_refund_recovery",
      "active_logistics_recovery",
    ],
    failed_adapter_keys: [],
  },
  bucket: "waiting_on_others",
  coverage: "all",
  pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
  ...overrides,
});

describe("Shop Owner Action Center", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  beforeEach(() => {
    mocks.reload.mockReset();
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        id: 12,
        reference: "EXP-12",
        category: "Operations",
        description: "Submitted supplier expense",
        amount: 450,
        status: "submitted",
        created_at: "2026-08-14T09:00:00+08:00",
        approval: { current_approver_role: "shop_owner" },
      }),
    }));
    mocks.props = {
      ownerActionCenter: result(),
      source: "all",
      page: 1,
      per_page: 20,
      bucket: "needs_my_decision",
      bucketSummaries: {
        needs_my_decision: result(),
        urgent_exceptions: exceptionResult(),
        waiting_on_others: waitingResult(),
      },
    };
  });

  it("renders Waiting on Others with bounded filters, responsibility labels, and no mutation controls", () => {
    mocks.props = {
      ...mocks.props,
      bucket: "waiting_on_others",
      source: "all",
      page: 4,
      ownerActionCenter: waitingResult(),
    };

    render(<ActionCenter />);

    expect(screen.getByRole("link", { name: /Waiting on Others\s*1/i })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /Waiting on Others\s*1/i })).toHaveAttribute(
      "href",
      expect.stringMatching(/bucket=waiting_on_others.*page=1/),
    );
    expect(screen.getByRole("link", { name: /^All$/i })).toHaveAttribute(
      "href",
      expect.stringMatching(/bucket=waiting_on_others.*source=all.*page=1/),
    );
    expect(screen.getByRole("link", { name: /^Compliance$/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /^Refunds$/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /^Logistics$/i })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /^Expenses$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /^Purchase Requests$/i })).not.toBeInTheDocument();
    expect(screen.getByText("Waiting on: Payment Recovery")).toBeInTheDocument();
    expect(screen.getByText("Refund recovery in progress")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /approve|reject|dismiss|resolve|snooze/i })).not.toBeInTheDocument();
  });

  it("uses distinct Waiting on Others empty, partial, unavailable, and disabled copy", () => {
    const { rerender } = render(<ActionCenter />);

    mocks.props = {
      ...mocks.props,
      bucket: "waiting_on_others",
      ownerActionCenter: waitingResult({
        items: [],
        health: {
          enabled_adapter_keys: ["waiting_order_refund_recovery"],
          healthy_adapter_keys: ["waiting_order_refund_recovery"],
          failed_adapter_keys: [],
        },
        coverage_counts: { compliance: 0, refunds: 0, logistics: 0 },
        pagination: { page: 1, per_page: 20, total: 0, last_page: 1 },
      }),
    };
    rerender(<ActionCenter />);
    expect(screen.getByText(/No waiting items from currently supported sources/i)).toBeInTheDocument();

    mocks.props = {
      ...mocks.props,
      ownerActionCenter: waitingResult({
        health: {
          enabled_adapter_keys: ["waiting_order_refund_recovery", "active_logistics_recovery"],
          healthy_adapter_keys: ["waiting_order_refund_recovery"],
          failed_adapter_keys: ["active_logistics_recovery"],
        },
        degradation_status: "partial",
        coverage_counts: { compliance: 0, refunds: 1, logistics: 0 },
        pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
      }),
    };
    rerender(<ActionCenter />);
    expect(screen.getByText(/waiting items from currently available sources/i)).toBeInTheDocument();
    expect(screen.getByText(/Logistics recovery temporarily unavailable/i)).toBeInTheDocument();

    mocks.props = {
      ...mocks.props,
      ownerActionCenter: waitingResult({
        items: [],
        health: {
          enabled_adapter_keys: ["active_logistics_recovery"],
          healthy_adapter_keys: [],
          failed_adapter_keys: ["active_logistics_recovery"],
        },
        degradation_status: "unavailable",
        coverage_counts: { compliance: 0, refunds: 0, logistics: 0 },
        pagination: { page: 1, per_page: 20, total: 0, last_page: 1 },
      }),
    };
    rerender(<ActionCenter />);
    expect(screen.getByText(/Waiting on Others currently unavailable/i)).toBeInTheDocument();

    mocks.props = {
      ...mocks.props,
      ownerActionCenter: waitingResult({
        items: [],
        health: { enabled_adapter_keys: [], healthy_adapter_keys: [], failed_adapter_keys: [] },
        degradation_status: "no_enabled_adapters",
        coverage_counts: { compliance: 0, refunds: 0, logistics: 0 },
        pagination: { page: 1, per_page: 20, total: 0, last_page: 1 },
      }),
    };
    rerender(<ActionCenter />);
    expect(screen.getByText(/Waiting on Others sources are not enabled/i)).toBeInTheDocument();
  });

  it("uses dominant bucket tabs and a compact exception queue without redundant filters", () => {
    mocks.props = {
      ...mocks.props,
      bucket: "urgent_exceptions",
      ownerActionCenter: exceptionResult(),
    };

    render(<ActionCenter />);

    expect(screen.getByRole("link", { name: /Needs My Decision\s*1/i })).toHaveAttribute(
      "href",
      expect.stringMatching(/bucket=needs_my_decision.*page=1/),
    );
    expect(screen.getByRole("link", { name: /Urgent Exceptions\s*1/i })).toHaveAttribute("aria-current", "page");
    expect(screen.getByText("Mayor's Permit expiry")).toBeInTheDocument();
    expect(screen.getByText(/Priority:\s*High/i)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Open workflow/i })).toHaveAttribute(
      "href",
      "/shop-owner/settings/policies-compliance",
    );
    expect(screen.queryByRole("navigation", { name: /Action Center source filters/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /dismiss|hide|acknowledge|snooze|resolve/i })).not.toBeInTheDocument();
  });

  it("renders Logistics exceptions with a source label and workflow link", () => {
    mocks.props = {
      ...mocks.props,
      bucket: "urgent_exceptions",
      ownerActionCenter: exceptionResult({
        items: [item({
          attention_key: "logistics_failure:17:unowned_delivery_failure",
          source_type: "logistics_failure",
          source_id: 17,
          category: "unowned_delivery_failure",
          module: "logistics",
          title: "Failed delivery needs escalation",
          concise_summary: "Delivery recovery is exhausted and has no active responsible party.",
          priority_tier: "high",
          materiality_tier: "high",
          comparable_monetary_exposure: null,
          urgency_at: "2026-08-16T09:00:00+08:00",
          actionable_since: "2026-08-15T09:00:00+08:00",
          waiting_on: "none",
          owner_action_required: false,
          coverage_source: "logistics",
          destination_url: "/shop-owner/logistics/shipments?shipment=8&leg=17",
        })],
        coverage_counts: { compliance: 0, refunds: 0, logistics: 1 },
        health: {
          enabled_adapter_keys: ["unowned_logistics_failures"],
          healthy_adapter_keys: ["unowned_logistics_failures"],
          failed_adapter_keys: [],
        },
      }),
    };

    render(<ActionCenter />);

    expect(screen.getByText("Logistics Failure")).toBeInTheDocument();
    expect(screen.getByText("Failed delivery needs escalation")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Open workflow/i })).toHaveAttribute(
      "href",
      "/shop-owner/logistics/shipments?shipment=8&leg=17",
    );
  });

  it("renders grounded decisions with all seven approval filters and review-only rows", async () => {
    render(<ActionCenter />);

    expect(screen.getByRole("heading", { name: /owner action center/i })).toBeInTheDocument();
    expect(screen.getAllByText("Needs My Decision").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("Supplier expense")).toBeInTheDocument();
    expect(screen.getAllByText(/Expense/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/450\.00/)).toBeInTheDocument();
    expect(screen.getByText(/Priority:\s*High/i)).toBeInTheDocument();
    for (const label of [
      "Refunds",
      "Prices",
      "Payslips",
      "Salary Adjustments",
      "Purchase Requests",
      "Expenses",
      "Repair Rejections",
    ]) {
      expect(screen.getByRole("link", { name: new RegExp(`^${label}$`, "i") })).toBeInTheDocument();
    }
    expect(screen.getByRole("link", { name: /^Refunds$/i })).toHaveAttribute(
      "href",
      expect.stringContaining("source=refunds"),
    );
    expect(screen.getByRole("button", { name: /Review Supplier expense/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Reject$/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Review Supplier expense/i }));
    await waitFor(() => expect(screen.getByRole("dialog", { name: /Expense approval/i })).toBeInTheDocument());
  });

  it("labels all seven approval families and keeps decisions at review level in the queue", () => {
    const approvals: Array<{
      source_type: OwnerAttentionItem["source_type"];
      coverage_source: OwnerAttentionItem["coverage_source"];
      title: string;
    }> = [
      { source_type: "order_refund", coverage_source: "refunds", title: "Order refund approval" },
      { source_type: "product_price_change", coverage_source: "prices", title: "Product price approval" },
      { source_type: "payslip", coverage_source: "payslips", title: "Payslip approval" },
      { source_type: "salary_change", coverage_source: "salary_changes", title: "Salary adjustment approval" },
      { source_type: "purchase_request", coverage_source: "purchase_requests", title: "Purchase request approval" },
      { source_type: "expense", coverage_source: "expenses", title: "Expense approval" },
      { source_type: "repair_rejection", coverage_source: "repair_rejections", title: "Repair rejection approval" },
    ];

    mocks.props = {
      ...mocks.props,
      ownerActionCenter: result({
        items: approvals.map((approval, index) => item({
          attention_key: `${approval.source_type}:${index + 1}:owner_approval`,
          source_type: approval.source_type,
          source_id: index + 1,
          title: approval.title,
          coverage_source: approval.coverage_source,
          comparable_monetary_exposure: 100 + index,
          destination_url: `/shop-owner/action-center?approval=${approval.source_type}:${index + 1}`,
        })),
        coverage_counts: {
          refunds: 1,
          prices: 1,
          payslips: 1,
          salary_changes: 1,
          expenses: 1,
          purchase_requests: 1,
          repair_rejections: 1,
        },
        pagination: { page: 1, per_page: 20, total: 7, last_page: 1 },
      }),
    };

    render(<ActionCenter />);

    for (const approval of approvals) {
      expect(screen.getByText(approval.title)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: `Review ${approval.title}` })).toBeInTheDocument();
    }
    expect(screen.getAllByRole("button", { name: /^Review /i })).toHaveLength(7);
    expect(screen.queryByRole("button", { name: /^Approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Reject$/i })).not.toBeInTheDocument();
  });

  it("labels partial coverage and names the failed source", () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter: result({
        health: {
          enabled_adapter_keys: ["order_refunds", "repair_refunds", "expenses"],
          healthy_adapter_keys: ["order_refunds", "repair_refunds"],
          failed_adapter_keys: ["expenses"],
        },
        degradation_status: "partial",
        coverage_counts: { refunds: 2, expenses: 3, purchase_requests: 0 },
        pagination: { page: 1, per_page: 20, total: 5, last_page: 1 },
      }),
    };

    render(<ActionCenter />);

    expect(screen.getByText(/partial coverage/i)).toBeInTheDocument();
    expect(screen.getByText(/5 actions from currently available sources/i)).toBeInTheDocument();
    expect(screen.getByText(/expenses temporarily unavailable/i)).toBeInTheDocument();
  });

  it("distinguishes unavailable data from a healthy empty queue", () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter: result({
        items: [],
        coverage_counts: { refunds: 0, expenses: 0, purchase_requests: 0 },
        health: {
          enabled_adapter_keys: ["expenses"],
          healthy_adapter_keys: [],
          failed_adapter_keys: ["expenses"],
        },
        degradation_status: "unavailable",
        pagination: { page: 1, per_page: 20, total: 0, last_page: 1 },
      }),
    };

    render(<ActionCenter />);

    expect(screen.getByText(/currently unavailable/i)).toBeInTheDocument();
    expect(screen.queryByText(/Needs My Decision\s*0/i)).not.toBeInTheDocument();

    mocks.props = {
      ...mocks.props,
      ownerActionCenter: result({
        items: [],
        health: {
          enabled_adapter_keys: ["expenses"],
          healthy_adapter_keys: ["expenses"],
          failed_adapter_keys: [],
        },
        pagination: { page: 1, per_page: 20, total: 0, last_page: 1 },
      }),
    };

    render(<ActionCenter />);

    expect(screen.getByText(/No decisions from currently supported sources require action/i)).toBeInTheDocument();
  });

  it("uses exception-specific empty-state language", () => {
    mocks.props = {
      ...mocks.props,
      bucket: "urgent_exceptions",
      ownerActionCenter: exceptionResult({
        items: [],
        coverage_counts: { compliance: 0, refunds: 0, logistics: 0 },
        pagination: { page: 1, per_page: 20, total: 0, last_page: 1 },
      }),
    };

    render(<ActionCenter />);

    expect(screen.getByText(/No urgent exceptions from currently supported sources/i)).toBeInTheDocument();
    expect(screen.getByText(/No urgent exceptions are listed on this page/i)).toBeInTheDocument();
    expect(screen.queryByText(/No decisions are listed on this page/i)).not.toBeInTheDocument();
  });

  it("refreshes and preserves bounded filter and pagination state", () => {
    mocks.props = {
      ...mocks.props,
      source: "expenses",
      page: 2,
      per_page: 2,
      ownerActionCenter: result({
        coverage: "expenses",
        pagination: { page: 2, per_page: 2, total: 5, last_page: 3 },
      }),
    };

    render(<ActionCenter />);

    fireEvent.click(screen.getByRole("button", { name: /refresh action center/i }));
    expect(mocks.reload).toHaveBeenCalledWith({ preserveScroll: true, preserveState: true });
    expect(screen.getByRole("link", { name: /previous page/i })).toHaveAttribute(
      "href",
      expect.stringContaining("source=expenses"),
    );
    expect(screen.getByRole("link", { name: /page 1/i })).toHaveAttribute(
      "href",
      expect.stringContaining("page=1"),
    );
    expect(screen.getByText("Page 2")).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /next page/i })).toHaveAttribute(
      "href",
      expect.stringContaining("page=3"),
    );
  });
});
