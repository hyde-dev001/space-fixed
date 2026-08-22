import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  formatStatus,
  hasAny,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

export default function RefundApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const amount = pick(detail, "amount", "refund_amount", "refundAmountValue", "payoutAmountValue", "requested_amount", "total") ?? item.comparable_monetary_exposure;
  const status = pick(detail, "rawStatus", "status", "approval_status", "approval.status") ?? "pending_owner";
  const hasEvidence = hasAny(detail, "reason", "refundReason", "refundNote", "otherReasonNote", "rejection_reason", "notes", "approval_note", "evidence", "media");

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
            {hasAny(detail, "reason", "refundReason", "rejection_reason") && <DetailNote label="Reason" value={pick(detail, "reason", "refundReason", "rejection_reason")} />}
            {hasAny(detail, "refundNote", "otherReasonNote", "notes", "approval_note") && <DetailNote label="Notes" value={pick(detail, "refundNote", "otherReasonNote", "notes", "approval_note")} />}
            {hasAny(detail, "evidence", "media") && <DetailNote label="Evidence" value={pick(detail, "evidence", "media")} />}
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
