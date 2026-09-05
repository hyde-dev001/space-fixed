import { workflowFeedback } from "../../utils/workflowFeedback";
import type { ApprovalAction, ApprovalPanelDefinition } from "./approvalPanelRegistry";

interface ApprovalDecisionFooterProps {
  definition: ApprovalPanelDefinition;
  recordLabel: string;
  submitting: boolean;
  onSubmit: (action: ApprovalAction, reason?: string) => void;
}

const OTHER_REJECTION_REASON = "Other";

const rejectionReasonOptions = {
  "Insufficient evidence": "Insufficient evidence",
  "Incorrect or incomplete information": "Incorrect or incomplete information",
  "Does not meet approval requirements": "Does not meet approval requirements",
  "Duplicate request": "Duplicate request",
  [OTHER_REJECTION_REASON]: OTHER_REJECTION_REASON,
};

const rejectionReasonError = (reason: string, minimumLength: number, maximumLength: number): string | undefined => {
  if (!reason) return "Enter a rejection reason before submitting.";
  if (reason.length < minimumLength) return `Enter at least ${minimumLength} characters explaining the rejection.`;
  if (reason.length > maximumLength) return `Keep the rejection reason to ${maximumLength} characters or fewer.`;
  return undefined;
};

export default function ApprovalDecisionFooter({
  definition,
  recordLabel,
  submitting,
  onSubmit,
}: ApprovalDecisionFooterProps) {
  const rejectMaxLength = definition.reject?.maxLength ?? 1000;
  const rejectMinLength = definition.reject?.minLength ?? 0;

  const handleApprove = async () => {
    if (submitting || !definition.approve) return;

    const confirmation = await workflowFeedback.confirm({
      title: `Approve ${recordLabel}?`,
      text: `This will ${definition.consequence}. Confirm only after checking the summary and evidence above.`,
      confirmButtonText: "Approve",
      confirmButtonColor: "#059669",
    });

    if (confirmation.isConfirmed) onSubmit("approve");
  };

  const handleReject = async () => {
    if (submitting || !definition.reject) return;

    const selection = await workflowFeedback.alert({
      title: `Reject ${recordLabel}?`,
      text: "Choose a reason for rejecting this request.",
      icon: "warning",
      input: "select",
      inputOptions: rejectionReasonOptions,
      inputPlaceholder: "Select a rejection reason",
      inputValidator: (value) => value ? undefined : "Choose a rejection reason.",
      showCancelButton: true,
      confirmButtonText: "Continue",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#dc2626",
      cancelButtonColor: "#6b7280",
    });

    if (!selection.isConfirmed) return;

    let reason = String(selection.value ?? "").trim();

    if (reason === OTHER_REJECTION_REASON) {
      const customReason = await workflowFeedback.alert({
        title: `Reject ${recordLabel}?`,
        text: "Add the specific reason for rejecting this request.",
        icon: "warning",
        input: "textarea",
        inputLabel: "Rejection reason",
        inputPlaceholder: "Explain what needs to be corrected or clarified...",
        inputAttributes: { maxlength: String(rejectMaxLength) },
        inputValidator: (value) => rejectionReasonError(value.trim(), rejectMinLength, rejectMaxLength),
        showCancelButton: true,
        confirmButtonText: "Reject",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#dc2626",
        cancelButtonColor: "#6b7280",
      });

      if (!customReason.isConfirmed) return;
      reason = String(customReason.value ?? "").trim();
    }

    const validationError = rejectionReasonError(reason, rejectMinLength, rejectMaxLength);
    if (validationError) {
      await workflowFeedback.warning("Invalid rejection reason", validationError);
      return;
    }

    onSubmit("reject", reason);
  };

  if (!definition.approve && !definition.reject) {
    return <p className="text-sm text-gray-600 dark:text-gray-300">This record is view-only in the current workflow stage.</p>;
  }

  return (
    <div className="flex flex-wrap gap-3">
      {definition.approve && (
        <button
          type="button"
          disabled={submitting}
          onClick={handleApprove}
          className="inline-flex min-h-11 items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-gray-900"
        >
          Approve
        </button>
      )}
      {definition.reject && (
        <button
          type="button"
          disabled={submitting}
          onClick={handleReject}
          className="inline-flex min-h-11 items-center justify-center rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/30 dark:focus-visible:ring-offset-gray-900"
        >
          Reject
        </button>
      )}
    </div>
  );
}
