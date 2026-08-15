import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
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
  coverage_counts: { refunds: 0, expenses: 1, purchase_requests: 0 },
  health: {
    enabled_adapter_keys: ["order_refunds", "repair_refunds", "expenses", "purchase_requests"],
    healthy_adapter_keys: ["order_refunds", "repair_refunds", "expenses", "purchase_requests"],
    failed_adapter_keys: [],
  },
  degradation_status: "none",
  coverage: "all",
  pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
  ...overrides,
});

describe("Shop Owner Action Center", () => {
  beforeEach(() => {
    mocks.reload.mockReset();
    mocks.props = {
      ownerActionCenter: result(),
      source: "all",
      page: 1,
      per_page: 20,
    };
  });

  it("renders grounded decisions with enabled filters and workflow links", () => {
    render(<ActionCenter />);

    expect(screen.getByRole("heading", { name: /owner action center/i })).toBeInTheDocument();
    expect(screen.getAllByText("Needs My Decision").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("Supplier expense")).toBeInTheDocument();
    expect(screen.getAllByText(/Expense/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/450\.00/)).toBeInTheDocument();
    expect(screen.getByText(/Priority:\s*High/i)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Open workflow/i })).toHaveAttribute(
      "href",
      "/shop-owner/expense-approvals?expense=12",
    );
    expect(screen.getByRole("link", { name: /^Refunds$/i })).toHaveAttribute(
      "href",
      expect.stringContaining("source=refunds"),
    );
    expect(screen.getByRole("link", { name: /^Expenses$/i })).toHaveAttribute(
      "href",
      expect.stringContaining("source=expenses"),
    );
    expect(screen.getByRole("link", { name: /^Purchase Requests$/i })).toHaveAttribute(
      "href",
      expect.stringContaining("source=purchase_requests"),
    );
    expect(screen.queryByRole("button", { name: /approve|reject/i })).not.toBeInTheDocument();
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
