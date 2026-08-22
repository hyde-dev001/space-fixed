import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  formatStatus,
  hasAny,
  numberValue,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

export default function SalaryAdjustmentApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const previous = numberValue(pick(detail, "previous_salary", "old_salary", "current_salary"));
  const next = numberValue(pick(detail, "new_salary", "proposed_salary"));
  const changePercent = previous && next !== null ? ((next - previous) / previous) * 100 : pick(detail, "change_percent", "salary_change_percent");
  const status = pick(detail, "status", "approval_status") ?? "pending";
  const hasNotes = hasAny(detail, "reason", "notes", "rejection_reason", "approval_notes");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Previous salary" value={formatCurrency(previous)} />
          <DetailField label="Proposed salary" value={formatCurrency(next)} />
          <DetailField label="Change" value={changePercent === null || changePercent === undefined ? "—" : `${Number(changePercent).toFixed(2)}%`} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Employee" value={stringValue(pick(detail, "employee.name", "employee_name"))} />
          <DetailField label="Proposed by" value={stringValue(pick(detail, "proposer.name", "requested_by.name", "created_by.name", "proposer"))} />
          <DetailField label="Effective date" value={formatDate(pick(detail, "effective_date"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>

      {hasNotes && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "reason", "rejection_reason") && <DetailNote label="Reason" value={pick(detail, "reason", "rejection_reason")} />}
            {hasAny(detail, "notes", "approval_notes") && <DetailNote label="Notes" value={pick(detail, "notes", "approval_notes")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Current status" value={formatStatus(status)} />
          <DetailField label="Approved by" value={stringValue(pick(detail, "approved_by.name", "approved_by"))} />
          <DetailField label="Rejected by" value={stringValue(pick(detail, "rejected_by.name", "rejected_by"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "approved_at", "rejected_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}

