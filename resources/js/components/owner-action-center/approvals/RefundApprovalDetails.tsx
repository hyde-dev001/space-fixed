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
  const amount = pick(detail, "amount", "refund_amount", "requested_amount", "total") ?? item.comparable_monetary_exposure;
  const status = pick(detail, "status", "approval_status", "approval.status") ?? "pending_owner";
  const hasEvidence = hasAny(detail, "reason", "rejection_reason", "notes", "approval_note", "evidence");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Amount" value={formatCurrency(amount)} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Approval stage" value={formatStatus(pick(detail, "approval.current_approver_role", "current_approver_role") ?? "Shop owner")} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Order or repair" value={stringValue(pick(detail, "order_number", "order.reference", "repair.reference", "reference"))} />
          <DetailField label="Customer" value={stringValue(pick(detail, "customer.name", "customer_name", "order.customer_name"))} />
          <DetailField label="Requested by" value={stringValue(pick(detail, "requested_by.name", "requester.name", "created_by.name", "requested_by"))} />
          <DetailField label="Refund method" value={stringValue(pick(detail, "refund_method", "method", "gateway"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasEvidence && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "reason", "rejection_reason") && <DetailNote label="Reason" value={pick(detail, "reason", "rejection_reason")} />}
            {hasAny(detail, "notes", "approval_note") && <DetailNote label="Notes" value={pick(detail, "notes", "approval_note")} />}
            {hasAny(detail, "evidence") && <DetailNote label="Evidence" value={pick(detail, "evidence")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Current owner" value={stringValue(pick(detail, "approval.current_approver_role", "current_approver_role"), "Shop owner")} />
          <DetailField label="Previous stage" value={formatStatus(pick(detail, "approval.previous_status", "previous_status", "workflow_stage"))} />
          <DetailField label="Approved by" value={stringValue(pick(detail, "approved_by.name", "approved_by"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "approved_at", "rejected_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}

