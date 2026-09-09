import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const sweetAlertFire = vi.hoisted(() => vi.fn());

vi.mock("sweetalert2", () => ({
  default: { fire: sweetAlertFire },
}));

import ApprovalDecisionFooter from "../ApprovalDecisionFooter";
import { approvalPanelRegistry } from "../approvalPanelRegistry";

describe("ApprovalDecisionFooter", () => {
  beforeEach(() => {
    sweetAlertFire.mockReset();
    sweetAlertFire.mockResolvedValue({ isConfirmed: true });
  });

  afterEach(() => {
    sweetAlertFire.mockReset();
  });

  it("opens suggested rejection reasons and submits the selected reason", async () => {
    const onSubmit = vi.fn();
    sweetAlertFire.mockResolvedValueOnce({ isConfirmed: true, value: "Insufficient evidence" });

    render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        recordLabel="Expense #12"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Reject$/i }));
    await waitFor(() => expect(sweetAlertFire).toHaveBeenCalledWith(expect.objectContaining({
      title: "Reject Expense #12?",
      input: "select",
      inputOptions: expect.objectContaining({ Other: "Other" }),
      confirmButtonText: "Continue",
      confirmButtonColor: "#dc2626",
    })));

    expect(screen.queryByRole("button", { name: /Confirm rejection/i })).not.toBeInTheDocument();
    expect(onSubmit).toHaveBeenCalledWith("reject", "Insufficient evidence");
  });

  it("lets the approver enter a custom reason through Other", async () => {
    const onSubmit = vi.fn();
    sweetAlertFire
      .mockResolvedValueOnce({ isConfirmed: true, value: "Other" })
      .mockResolvedValueOnce({ isConfirmed: true, value: "The attached quote does not match the requested amount." });

    render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        recordLabel="Expense #12"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Reject$/i }));

    await waitFor(() => expect(sweetAlertFire).toHaveBeenCalledTimes(2));
    expect(sweetAlertFire.mock.calls[1][0]).toEqual(expect.objectContaining({
      input: "textarea",
      inputLabel: "Rejection reason",
      inputAttributes: { maxlength: "1000" },
      confirmButtonText: "Reject",
      confirmButtonColor: "#dc2626",
    }));
    expect(onSubmit).toHaveBeenCalledWith("reject", "The attached quote does not match the requested amount.");
  });

  it("confirms the approval consequence and prevents duplicate submission", async () => {
    const onSubmit = vi.fn();
    const { rerender } = render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        recordLabel="Expense #12"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Approve$/i }));
    await waitFor(() => expect(sweetAlertFire).toHaveBeenCalledWith(expect.objectContaining({
      title: "Approve Expense #12?",
      text: expect.stringContaining("move this record to the next authoritative workflow stage"),
      showCancelButton: true,
      confirmButtonText: "Approve",
      confirmButtonColor: "#059669",
    })));
    expect(onSubmit).toHaveBeenCalledWith("approve");
    expect(screen.queryByRole("button", { name: /Confirm approval/i })).not.toBeInTheDocument();

    rerender(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        recordLabel="Expense #12"
        submitting
        onSubmit={onSubmit}
      />,
    );
    expect(screen.getByRole("button", { name: /^Approve$/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /^Reject$/i })).toBeDisabled();
  });

  it("uses semantic critical treatments for text decisions", () => {
    render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.expense}
        recordLabel="Expense #12"
        submitting={false}
        onSubmit={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: /^Approve$/i })).toHaveClass("bg-emerald-600", "text-white");
    expect(screen.getByRole("button", { name: /^Approve$/i })).toHaveAttribute("data-critical");
    expect(screen.getByRole("button", { name: /^Reject$/i })).toHaveClass("border-red-300", "bg-white", "text-red-700");
    expect(screen.getByRole("button", { name: /^Reject$/i })).toHaveAttribute("data-critical");
  });

});
