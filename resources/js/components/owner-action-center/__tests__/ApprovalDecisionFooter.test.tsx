import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import ApprovalDecisionFooter from "../ApprovalDecisionFooter";
import { approvalPanelRegistry } from "../approvalPanelRegistry";
import type { OwnerAttentionItem } from "../../../types/ownerActionCenter";

const item: OwnerAttentionItem = {
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
};

describe("ApprovalDecisionFooter", () => {
  it("requires a visible rejection reason before submitting a rejection", () => {
    const onSubmit = vi.fn();

    render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        item={item}
        recordLabel="Expense #12"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Reject$/i }));
    const reason = screen.getByLabelText(/rejection reason/i);
    expect(reason).toBeRequired();
    expect(reason).toHaveAttribute("maxLength", "1000");

    fireEvent.click(screen.getByRole("button", { name: /Confirm rejection/i }));
    expect(screen.getByText(/enter a rejection reason/i)).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();

    fireEvent.change(reason, { target: { value: "Missing receipt" } });
    fireEvent.click(screen.getByRole("button", { name: /Confirm rejection/i }));
    expect(onSubmit).toHaveBeenCalledWith("reject", "Missing receipt");
  });

  it("confirms the approval consequence and prevents duplicate submission", () => {
    const onSubmit = vi.fn();
    const { rerender } = render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        item={item}
        recordLabel="Expense #12"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Approve$/i }));
    expect(screen.getByText(/Approve Expense #12/i)).toBeInTheDocument();
    expect(screen.getByText(/move this record to the next authoritative workflow stage/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Confirm approval/i }));
    expect(onSubmit).toHaveBeenCalledWith("approve");

    rerender(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        item={item}
        recordLabel="Expense #12"
        submitting
        onSubmit={onSubmit}
      />,
    );
    expect(screen.getByRole("button", { name: /^Approve$/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /^Reject$/i })).toBeDisabled();
  });

  it("preserves the repair rejection minimum reason length", () => {
    const onSubmit = vi.fn();

    render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.repair_rejection}
        item={{ ...item, source_type: "repair_rejection", category: "repair_rejection" }}
        recordLabel="Repair rejection #1"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Reject$/i }));
    fireEvent.change(screen.getByLabelText(/rejection reason/i), { target: { value: "Too short" } });
    fireEvent.click(screen.getByRole("button", { name: /Confirm rejection/i }));

    expect(screen.getByText(/at least 10 characters/i)).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
