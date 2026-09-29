import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  formatStatus,
  isRecord,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

const evidenceUrls = (value: unknown): string[] => {
  const entries = Array.isArray(value) ? value : value == null ? [] : [value];

  return entries
    .map((entry) => {
      if (typeof entry === "string") return entry.trim();
      if (!isRecord(entry)) return "";

      return String(pick(entry, "url", "path", "src") ?? "").trim();
    })
    .filter(Boolean)
    .map((url) => (/^(https?:|data:|blob:|\/)/i.test(url) ? url : "/storage/" + url.replace(/^\/+/, "")));
};

const isVideoEvidence = (url: string): boolean => /\.(mp4|mov|avi|mkv|webm)(\?.*)?$/i.test(url);

export default function RefundApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const amount = pick(detail, "amount", "refund_amount", "refundAmountValue", "payoutAmountValue", "requested_amount", "total") ?? item.comparable_monetary_exposure;
  const status = pick(detail, "rawStatus", "status", "approval_status", "approval.status") ?? "pending_owner";
  const reasonValue = pick(detail, "refundReason", "reason", "rejection_reason");
  const notesValue = pick(detail, "reasonDetails", "refundNote", "otherReasonNote", "notes", "approval_note");
  const evidenceValue = pick(detail, "media", "evidence");
  const mediaUrls = evidenceUrls(evidenceValue);
  const hasEvidence = reasonValue !== null || notesValue !== null || evidenceValue !== null;

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Amount" value={formatCurrency(amount)} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Approval stage" value={formatStatus(pick(detail, "approvalStage", "approval.current_approver_role", "current_approver_role") ?? "Shop owner")} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Order or repair" value={stringValue(pick(detail, "orderNumber", "request_number", "order_number", "order.reference", "repair.reference", "reference"))} />
          <DetailField label="Customer" value={stringValue(pick(detail, "customerName", "customer.name", "customer_name", "order.customer_name"))} />
          <DetailField label="Requested by" value={stringValue(pick(detail, "requestedBy", "requested_by.name", "requester.name", "created_by.name", "requested_by"))} />
          <DetailField label="Refund method" value={stringValue(pick(detail, "refundMethod", "refund_method", "method", "gateway"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "requestDate", "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasEvidence && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {reasonValue !== null && <DetailNote label="Reason" value={reasonValue} />}
            {notesValue !== null && <DetailNote label="Notes" value={notesValue} />}
            {evidenceValue !== null && (
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Evidence</dt>
                {mediaUrls.length > 0 ? (
                  <div className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {mediaUrls.map((url, index) => (
                      <a
                        key={url + "-" + index}
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={"Open refund evidence " + (index + 1)}
                        className="group overflow-hidden rounded-lg border border-gray-200 bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900"
                      >
                        {isVideoEvidence(url) ? (
                          <video src={url} className="aspect-video w-full object-cover" muted playsInline preload="metadata" />
                        ) : (
                          <img src={url} alt={"Refund evidence " + (index + 1)} className="aspect-video w-full object-cover" />
                        )}
                      </a>
                    ))}
                  </div>
                ) : (
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Evidence preview unavailable.</p>
                )}
              </div>
            )}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Current owner" value={formatStatus(pick(detail, "approvalStage", "approval.current_approver_role", "current_approver_role") ?? "Shop owner")} />
          <DetailField label="Finance status" value={formatStatus(pick(detail, "financeStatus", "approval.previous_status", "previous_status", "workflow_stage"))} />
          <DetailField label="Owner status" value={formatStatus(pick(detail, "shopOwnerStatus"))} />
          <DetailField label="Return status" value={formatStatus(pick(detail, "returnStatus"))} />
          <DetailField label="Approved by" value={stringValue(pick(detail, "approved_by.name", "approved_by"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "approved_at", "rejected_at", "refundExecutedAt", "refundedAt"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}
