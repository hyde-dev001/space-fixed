import { useEffect, useState } from "react";
import type { OwnerAttentionItem } from "../../types/ownerActionCenter";
import type { ApprovalAction, ApprovalPanelDefinition } from "./approvalPanelRegistry";

interface ApprovalDecisionFooterProps {
  definition: ApprovalPanelDefinition;
  item: OwnerAttentionItem;
  recordLabel: string;
  submitting: boolean;
  onSubmit: (action: ApprovalAction, reason?: string) => void;
}

export default function ApprovalDecisionFooter({
  definition,
  item,
  recordLabel,
  submitting,
  onSubmit,
}: ApprovalDecisionFooterProps) {
  const [pendingAction, setPendingAction] = useState<ApprovalAction | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState<string | null>(null);
  const rejectMaxLength = definition.reject?.maxLength ?? 1000;

  useEffect(() => {
    setPendingAction(null);
    setReason("");
    setReasonError(null);
  }, [item.attention_key, definition.sourceType]);

  const submit = () => {
    if (submitting || pendingAction === null) return;

    if (pendingAction === "reject") {
      const trimmedReason = reason.trim();
      if (trimmedReason === "") {
        setReasonError("Enter a rejection reason before confirming.");
        return;
      }
      onSubmit("reject", trimmedReason);
      return;
    }

    onSubmit("approve");
  };

  if (!definition.approve && !definition.reject) {
    return <p className="text-sm text-gray-600 dark:text-gray-300">This record is view-only in the current workflow stage.</p>;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-3">
        {definition.approve && (
          <button
            type="button"
            disabled={submitting}
            onClick={() => {
              setPendingAction("approve");
              setReasonError(null);
            }}
            className="inline-flex min-h-11 items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-gray-900"
          >
            Approve
          </button>
        )}
        {definition.reject && (
          <button
            type="button"
            disabled={submitting}
            onClick={() => {
              setPendingAction("reject");
              setReasonError(null);
            }}
            className="inline-flex min-h-11 items-center justify-center rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/30 dark:focus-visible:ring-offset-gray-900"
          >
            Reject
          </button>
        )}
      </div>

      {pendingAction === "approve" && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/20" aria-live="polite">
          <h4 className="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Approve {recordLabel}?</h4>
          <p className="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
            This will {definition.consequence}. Confirm only after checking the summary and evidence above.
          </p>
          <div className="mt-3 flex flex-wrap gap-3">
            <button
              type="button"
              disabled={submitting}
              onClick={submit}
              className="inline-flex min-h-11 items-center justify-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-gray-900"
            >
              {submitting ? "Approving…" : "Confirm approval"}
            </button>
            <button
              type="button"
              disabled={submitting}
              onClick={() => setPendingAction(null)}
              className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-200 dark:focus-visible:ring-offset-gray-900"
            >
              Cancel
            </button>
          </div>
        </div>
      )}

      {pendingAction === "reject" && definition.reject && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/20">
          <h4 className="text-sm font-semibold text-red-900 dark:text-red-100">Reject {recordLabel}?</h4>
          <p className="mt-1 text-sm text-red-800 dark:text-red-200">A rejection sends this record back to its authoritative workflow owner.</p>
          <div className="mt-3">
            <label htmlFor="approval-rejection-reason" className="text-sm font-semibold text-gray-800 dark:text-gray-200">
              Rejection reason <span className="font-normal">(required)</span>
            </label>
            <textarea
              id="approval-rejection-reason"
              value={reason}
              required
              maxLength={rejectMaxLength}
              onChange={(event) => {
                setReason(event.target.value);
                if (event.target.value.trim() !== "") setReasonError(null);
              }}
              className="mt-1 block min-h-24 w-full rounded-lg border border-gray-300 bg-white p-3 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
              aria-describedby={reasonError ? "approval-rejection-reason-error" : undefined}
            />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Maximum {rejectMaxLength} characters.</p>
            {reasonError && <p id="approval-rejection-reason-error" role="alert" className="mt-1 text-sm font-medium text-red-700 dark:text-red-300">{reasonError}</p>}
          </div>
          <div className="mt-3 flex flex-wrap gap-3">
            <button
              type="button"
              disabled={submitting}
              onClick={submit}
              className="inline-flex min-h-11 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus-visible:ring-offset-gray-900"
            >
              {submitting ? "Rejecting…" : "Confirm rejection"}
            </button>
            <button
              type="button"
              disabled={submitting}
              onClick={() => setPendingAction(null)}
              className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-200 dark:focus-visible:ring-offset-gray-900"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

