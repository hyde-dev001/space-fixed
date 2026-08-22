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

export default function ExpenseApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "approval_status") ?? "submitted";
  const hasEvidence = hasAny(detail, "receipt", "receipt_url", "attachment", "notes", "approval_notes", "description");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Amount" value={formatCurrency(pick(detail, "amount", "total", "expense_amount") ?? item.comparable_monetary_exposure)} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Category" value={stringValue(pick(detail, "category", "expense_category"))} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Reference" value={stringValue(pick(detail, "reference", "expense_number", "code"))} />
          <DetailField label="Expense date" value={formatDate(pick(detail, "expense_date", "date", "incurred_at"))} />
          <DetailField label="Description" value={stringValue(pick(detail, "description", "purpose", "title"))} />
          <DetailField label="Submitted by" value={stringValue(pick(detail, "submitted_by.name", "requester.name", "created_by.name", "submitted_by"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasEvidence && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "receipt", "receipt_url", "attachment") && <DetailNote label="Receipt or attachment" value={pick(detail, "receipt", "receipt_url", "attachment")} />}
            {hasAny(detail, "description") && <DetailNote label="Description" value={pick(detail, "description")} />}
            {hasAny(detail, "notes", "approval_notes") && <DetailNote label="Notes" value={pick(detail, "notes", "approval_notes")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Current approver" value={stringValue(pick(detail, "approval.current_approver_role", "current_approver_role"), "Shop owner")} />
          <DetailField label="Workflow status" value={formatStatus(status)} />
          <DetailField label="Approved by" value={stringValue(pick(detail, "approved_by.name", "approved_by"))} />
          <DetailField label="Settlement" value={formatStatus(pick(detail, "settlement_status", "payment_status", "paid_status"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "approved_at", "rejected_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}

