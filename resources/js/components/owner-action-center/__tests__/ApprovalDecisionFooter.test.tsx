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

  it("preserves the repair rejection minimum reason length", async () => {
    const onSubmit = vi.fn();
    sweetAlertFire
      .mockResolvedValueOnce({ isConfirmed: true, value: "Other" })
      .mockResolvedValueOnce({ isConfirmed: true, value: "Too short" })
      .mockResolvedValueOnce({ isConfirmed: true });

    render(
      <ApprovalDecisionFooter
        definition={approvalPanelRegistry.repair_rejection}
        recordLabel="Repair rejection #1"
        submitting={false}
        onSubmit={onSubmit}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: /^Reject$/i }));

    await waitFor(() => expect(sweetAlertFire).toHaveBeenCalledTimes(3));
    expect(sweetAlertFire.mock.calls[2][0]).toEqual(expect.objectContaining({
      icon: "warning",
      title: "Invalid rejection reason",
    }));
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
