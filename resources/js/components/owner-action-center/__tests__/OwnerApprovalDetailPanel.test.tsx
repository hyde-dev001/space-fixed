import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const sweetAlertFire = vi.hoisted(() => vi.fn().mockResolvedValue({ isConfirmed: true }));

vi.mock("sweetalert2", () => ({
  default: { fire: sweetAlertFire },
}));

import OwnerApprovalDetailPanel from "../OwnerApprovalDetailPanel";
import type { ApprovalSelection } from "../approvalSelection";
import type { OwnerAttentionItem } from "../../../types/ownerActionCenter";

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

const selection: ApprovalSelection = { sourceType: "expense", sourceId: 12 };

const suspensionSelection: ApprovalSelection = { sourceType: "suspension_request", sourceId: 3 };

const suspensionItem = (overrides: Partial<OwnerAttentionItem> = {}): OwnerAttentionItem => item({
  attention_key: "suspension_request:3:owner_approval",
  source_type: "suspension_request",
  source_id: 3,
  category: "suspension_request",
  module: "hr",
  title: "Employee suspension approval",
  concise_summary: "Review the employee suspension request.",
  coverage_source: "suspensions",
  destination_url: "/shop-owner/action-center?approval=suspension_request%3A3",
  ...overrides,
});

describe("OwnerApprovalDetailPanel", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("loads the selected record into the responsive decision-summary order", async () => {
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
    const onClose = vi.fn();

    render(
      <OwnerApprovalDetailPanel
        item={item()}
        selection={selection}
        onClose={onClose}
        onDecisionComplete={vi.fn()}
      />,
    );

    const dialog = await screen.findByRole("dialog", { name: /Expense approval/i });
    expect(dialog.className).toContain("overflow-hidden");
    expect(dialog.className).not.toContain("lg:static");

    const headings = screen.getAllByRole("heading").map((heading) => heading.textContent);
    expect(headings.indexOf("Decision summary")).toBeLessThan(headings.indexOf("Request details"));
    expect(headings.indexOf("Request details")).toBeLessThan(headings.indexOf("Workflow/history"));
    expect(headings.indexOf("Workflow/history")).toBeLessThan(headings.indexOf("Decision footer"));

    fireEvent.keyDown(dialog, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("preserves stale context without exposing controls after responsibility changes", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ id: 12, status: "approved", amount: 450 }),
    }));
    const { rerender } = render(
      <OwnerApprovalDetailPanel
        item={item({ owner_action_required: false })}
        selection={selection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );

    await waitFor(() => expect(screen.queryByRole("button", { name: /^Approve$/i })).not.toBeInTheDocument());
    expect(screen.queryByRole("button", { name: /^Reject$/i })).not.toBeInTheDocument();

    rerender(
      <OwnerApprovalDetailPanel
        item={item({ owner_action_required: true })}
        selection={selection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );
    expect(screen.queryByRole("button", { name: /^Approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Reject$/i })).not.toBeInTheDocument();
  });

  it("keeps the selected context and offers refresh after a stale decision response", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ id: 12, amount: 450, status: "submitted" }),
      })
      .mockResolvedValueOnce({ ok: false, status: 409 });
    vi.stubGlobal("fetch", fetchMock);

    render(
      <OwnerApprovalDetailPanel
        item={item()}
        selection={selection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );

    await screen.findByRole("heading", { name: "Decision summary" });
    fireEvent.click(screen.getByRole("button", { name: /^Approve$/i }));
    fireEvent.click(screen.getByRole("button", { name: /Confirm approval/i }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(fetchMock.mock.calls[1][0]).toBe("/api/shop-owner/expenses/12/approve");
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ approval_notes: "" });
    expect(await screen.findByRole("alert")).toHaveTextContent(/changed before the decision was saved/i);
    expect(screen.getByText("Supplier expense")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Refresh$/i })).toBeInTheDocument();
  });

  it("sends the required action with Shop Owner suspension decisions", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ data: { id: 3, status: "pending", reason: "Policy violation" } }),
      })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) });
    vi.stubGlobal("fetch", fetchMock);

    render(
      <OwnerApprovalDetailPanel
        item={suspensionItem()}
        selection={suspensionSelection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );

    await screen.findByRole("heading", { name: "Decision summary" });
    fireEvent.click(screen.getByRole("button", { name: /^Approve$/i }));
    fireEvent.click(screen.getByRole("button", { name: /Confirm approval/i }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(fetchMock.mock.calls[1][0]).toBe("/api/shop-owner/suspension-requests/3/review");
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ action: "approve", note: "" });
  });

  it("sends the rejection action and note for Shop Owner suspension decisions", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ data: { id: 3, status: "pending", reason: "Policy violation" } }),
      })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) });
    vi.stubGlobal("fetch", fetchMock);

    render(
      <OwnerApprovalDetailPanel
        item={suspensionItem()}
        selection={suspensionSelection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );

    await screen.findByRole("heading", { name: "Decision summary" });
    fireEvent.click(screen.getByRole("button", { name: /^Reject$/i }));
    fireEvent.change(screen.getByRole("textbox", { name: /Rejection reason/i }), { target: { value: "Insufficient evidence" } });
    fireEvent.click(screen.getByRole("button", { name: /Confirm rejection/i }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(fetchMock.mock.calls[1][0]).toBe("/api/shop-owner/suspension-requests/3/review");
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ action: "reject", note: "Insufficient evidence" });
  });

  it("shows the HR reason and evidence in the Shop Owner approval details", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          id: 3,
          status: "pending",
          reason: "Repeated policy violations",
          evidence: "Incident report #42",
          manager_note: "Approved by manager",
        },
      }),
    }));

    render(
      <OwnerApprovalDetailPanel
        item={suspensionItem()}
        selection={suspensionSelection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );

    await screen.findByRole("heading", { name: "HR request and review notes" });
    expect(screen.getByText("Repeated policy violations")).toBeInTheDocument();
    expect(screen.getByText("Incident report #42")).toBeInTheDocument();
    expect(screen.getByText("Approved by manager")).toBeInTheDocument();
  });

  it("shows server validation errors instead of treating them as stale decisions", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ data: { id: 3, status: "pending", reason: "Policy violation" } }),
      })
      .mockResolvedValueOnce({
        ok: false,
        status: 422,
        json: async () => ({ message: "The action field is required.", errors: { action: ["The action field is required."] } }),
      });
    vi.stubGlobal("fetch", fetchMock);

    render(
      <OwnerApprovalDetailPanel
        item={suspensionItem()}
        selection={suspensionSelection}
        onClose={vi.fn()}
        onDecisionComplete={vi.fn()}
      />,
    );

    await screen.findByRole("heading", { name: "Decision summary" });
    fireEvent.click(screen.getByRole("button", { name: /^Approve$/i }));
    fireEvent.click(screen.getByRole("button", { name: /Confirm approval/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent("The action field is required.");
    expect(screen.getByRole("alert")).not.toHaveTextContent(/changed before the decision was saved/i);
  });
});
