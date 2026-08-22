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

export default function PurchaseRequestApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "approval_status") ?? "submitted";
  const hasNotes = hasAny(detail, "justification", "notes", "approval_notes", "rejection_reason");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Total" value={formatCurrency(pick(detail, "total", "total_amount", "estimated_total", "amount"))} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Priority" value={formatStatus(pick(detail, "priority", "priority_level"))} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Request number" value={stringValue(pick(detail, "request_number", "reference", "request_code"))} />
          <DetailField label="Product or item" value={stringValue(pick(detail, "product.name", "product_name", "item_name", "item"))} />
          <DetailField label="Quantity" value={stringValue(pick(detail, "quantity", "requested_quantity"))} />
          <DetailField label="Unit" value={stringValue(pick(detail, "unit", "unit_name"))} />
          <DetailField label="Supplier" value={stringValue(pick(detail, "supplier.name", "supplier_name"))} />
          <DetailField label="Requested by" value={stringValue(pick(detail, "requester.name", "requested_by.name", "requested_by"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "requested_date", "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasNotes && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "justification") && <DetailNote label="Justification" value={pick(detail, "justification")} />}
            {hasAny(detail, "notes", "approval_notes", "rejection_reason") && <DetailNote label="Notes" value={pick(detail, "notes", "approval_notes", "rejection_reason")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Current reviewer" value={stringValue(pick(detail, "approval.current_approver_role", "current_approver_role"), "Shop owner")} />
          <DetailField label="Approved by" value={stringValue(pick(detail, "approved_by.name", "approved_by"))} />
          <DetailField label="Approval date" value={formatDate(pick(detail, "approved_at", "updated_at"))} />
          <DetailField label="Workflow status" value={formatStatus(status)} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}

