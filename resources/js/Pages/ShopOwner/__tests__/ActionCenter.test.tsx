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

describe("Shop Owner Approval Center", () => {
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
      approvalCoverageSources: [
        "refunds",
        "prices",
        "payslips",
        "salary_changes",
        "purchase_requests",
        "suspensions",
        "expenses",
        "repair_rejections",
      ],
      source: "all",
      page: 1,
      per_page: 20,
      bucket: "needs_my_decision",
    };
  });

  it("renders one approval queue without retired exception or waiting navigation", () => {
    render(<ActionCenter />);

    expect(screen.getByRole("heading", { name: "Approval Center" })).toBeInTheDocument();
    expect(screen.getByText("1 approval requires your decision")).toBeInTheDocument();
    expect(screen.getByText("Approvals requiring your decision")).toBeInTheDocument();
    expect(screen.queryByText("Owner Action Center")).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Urgent Exceptions/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Waiting on Others/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("navigation", { name: /Action Center buckets/i })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: /All Approvals/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Price Changes/i })).toBeInTheDocument();
  });

  it("renders owner decisions with all supported approval filters and review-only rows", async () => {
    render(<ActionCenter />);

    expect(screen.getByText("Supplier expense")).toBeInTheDocument();
    expect(screen.getAllByText(/Expense/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/450\.00/)).toBeInTheDocument();
    expect(screen.getByText(/Priority:\s*High/i)).toBeInTheDocument();
    for (const label of [
      "All Approvals",
      "Refunds",
      "Price Changes",
      "Payslips",
      "Salary Adjustments",
      "Purchase Requests",
      "Suspension Requests",
      "Expenses",
      "Repair Rejections",
    ]) {
      expect(screen.getByRole("link", { name: new RegExp(`^${label}$`, "i") })).toBeInTheDocument();
    }
    expect(screen.getByRole("link", { name: /^Refunds$/i })).toHaveAttribute(
      "href",
      expect.stringContaining("source=refunds"),
    );
    expect(screen.getByRole("button", { name: /View Supplier expense approval details/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Reject$/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /View Supplier expense approval details/i }));
    await waitFor(() => expect(screen.getByRole("dialog", { name: /Expense approval/i })).toBeInTheDocument());
  });

  it("keeps all approval families visible when one module filter is selected", () => {
    mocks.props = {
      ...mocks.props,
      source: "refunds",
      ownerActionCenter: result({
        coverage: "refunds",
        health: {
          enabled_adapter_keys: ["order_refunds", "repair_refunds"],
          healthy_adapter_keys: ["order_refunds", "repair_refunds"],
          failed_adapter_keys: [],
        },
      }),
    };

    render(<ActionCenter />);

    expect(screen.getByRole("navigation", { name: /Approval Center source filters/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /^Refunds$/i })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /^Expenses$/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /^Repair Rejections$/i })).toBeInTheDocument();
  });

  it("shows completed owner decisions in history and opens them read-only", async () => {
    mocks.props = {
      ...mocks.props,
      view: "history",
      source: "purchase_requests",
      approvalHistory: {
        items: [{
          attention_key: "history:purchase_request:42:approved",
          source_type: "purchase_request",
          source_id: 42,
          title: "Purchase request PR-42",
          concise_summary: "Purchase request approval was recorded.",
          coverage_source: "purchase_requests",
          status: "approved",
          decision_at: "2026-08-14T11:00:00+08:00",
          requested_at: "2026-08-14T09:00:00+08:00",
          comparable_monetary_exposure: 1250,
          comments: "Approved for restock.",
          reviewed_by: "Second TestShop",
          destination_url: "/shop-owner/action-center?view=history&source=purchase_requests&approval=purchase_request:42",
        }],
        coverage_counts: { purchase_requests: 1 },
        coverage: "purchase_requests",
        pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
      },
    };

    render(<ActionCenter />);

    expect(screen.getByRole("heading", { name: "Approval history" })).toBeInTheDocument();
    expect(screen.getByText("Purchase request PR-42")).toBeInTheDocument();
    expect(screen.getByText("Approved")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /View Purchase request PR-42 approval details/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Reject$/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /View Purchase request PR-42 approval details/i }));
    await waitFor(() => expect(screen.getByRole("dialog", { name: /Purchase request approval/i })).toBeInTheDocument());
    expect(screen.getByText(/recorded in approval history/i)).toBeInTheDocument();
  });

  it("shows partial coverage using approval language", () => {
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
    expect(screen.getByText(/5 approvals from currently available sources/i)).toBeInTheDocument();
    expect(screen.getByText(/expenses temporarily unavailable/i)).toBeInTheDocument();
  });

  it("distinguishes unavailable data from a healthy empty approval queue", () => {
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

    expect(screen.getByText(/Approval Center currently unavailable/i)).toBeInTheDocument();

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

    expect(screen.getByText(/No approvals require your decision/i)).toBeInTheDocument();
  });

  it("refreshes and preserves source filter and pagination state", () => {
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

    fireEvent.click(screen.getByRole("button", { name: /refresh approval center/i }));
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

  it("does not render retired bucket data if a stale payload reaches the page", () => {
    mocks.props = {
      ...mocks.props,
      ownerActionCenter: result({
        bucket: "urgent_exceptions",
        items: [item({
          primary_bucket: "urgent_exceptions",
          source_type: "compliance_document",
          title: "Expired document",
          owner_action_required: false,
          waiting_on: "none",
        })],
      }),
    };

    render(<ActionCenter />);

    expect(screen.queryByText("Expired document")).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Open workflow/i })).not.toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Approval Center" })).toBeInTheDocument();
  });
});
