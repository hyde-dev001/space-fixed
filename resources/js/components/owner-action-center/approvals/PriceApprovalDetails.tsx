import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  formatStatus,
  formatWorkflowVersion,
  hasAny,
  personName,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

export default function PriceApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const oldPrice = pick(detail, "current_price", "old_price", "old_package_price", "previous_price");
  const newPrice = pick(detail, "proposed_price", "new_price", "package_price", "price");
  const status = (item.source_type === "repair_package_price_change"
    ? pick(detail, "approval_status", "status", "raw_status")
    : pick(detail, "status", "approval_status", "raw_status")) ?? "pending_owner";
  const category = pick(detail, "category", "product.category", "service.category")
    ?? (item.source_type === "product_price_change"
      ? "product"
      : item.source_type === "repair_package_price_change" ? "package" : "repair service");
  const requestType = pick(detail, "request_type", "type") ?? item.source_type;
  const hasNotes = hasAny(detail, "reason", "change_reason", "request_notes", "finance_notes", "notes", "rejection_reason");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Current price" value={formatCurrency(oldPrice)} />
          <DetailField label="Proposed price" value={formatCurrency(newPrice)} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Product or service" value={stringValue(pick(detail, "product.name", "product_name", "service.name", "service_name", "package_name", "name", "reference"))} />
          <DetailField label="Category" value={formatStatus(category)} />
          <DetailField label="Requested by" value={personName(pick(detail, "requester", "creator", "updater", "requested_by", "created_by"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasNotes && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "reason", "change_reason", "request_notes") && <DetailNote label="Reason" value={pick(detail, "reason", "change_reason", "request_notes")} />}
            {hasAny(detail, "finance_notes", "notes", "rejection_reason") && <DetailNote label="Finance notes" value={pick(detail, "finance_notes", "notes", "rejection_reason")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Request type" value={formatStatus(requestType)} />
          <DetailField label="Current approver" value={formatStatus(pick(detail, "current_approver_role", "approval.current_approver_role") ?? "shop_owner")} />
          <DetailField label="Workflow version" value={formatWorkflowVersion(pick(detail, "approval_workflow_version", "workflow_version"))} />
          <DetailField label="Finance reviewed" value={formatDate(pick(detail, "finance_reviewed_at"))} />
          <DetailField label="Owner reviewed" value={formatDate(pick(detail, "owner_reviewed_at"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "approved_at", "rejected_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}
