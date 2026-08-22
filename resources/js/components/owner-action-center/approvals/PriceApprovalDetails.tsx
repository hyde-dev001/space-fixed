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

export default function PriceApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const oldPrice = pick(detail, "current_price", "old_price", "old_package_price", "previous_price");
  const newPrice = pick(detail, "proposed_price", "new_price", "package_price", "price");
  const status = pick(detail, "status", "approval_status", "raw_status") ?? "pending_owner";
  const hasNotes = hasAny(detail, "reason", "request_notes", "finance_notes", "notes");

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
          <DetailField label="Category" value={stringValue(pick(detail, "category", "type", "request_type"))} />
          <DetailField label="Requested by" value={stringValue(pick(detail, "requested_by.name", "creator.name", "created_by.name", "requested_by", "creator"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasNotes && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "reason", "request_notes") && <DetailNote label="Reason" value={pick(detail, "reason", "request_notes")} />}
            {hasAny(detail, "finance_notes", "notes") && <DetailNote label="Finance notes" value={pick(detail, "finance_notes", "notes")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Request type" value={formatStatus(pick(detail, "request_type", "type"))} />
          <DetailField label="Current approver" value={stringValue(pick(detail, "current_approver_role", "approval.current_approver_role"), "Shop owner")} />
          <DetailField label="Workflow version" value={stringValue(pick(detail, "approval_workflow_version", "workflow_version"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "approved_at", "rejected_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}

