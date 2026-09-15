import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  hasAny,
  personName,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

export default function PayslipApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "approval_status") ?? "pending";
  const hasNotes = hasAny(detail, "line_items", "components", "notes", "approval_notes", "final_approval_notes", "allowances", "deductions", "payout_proof_notes");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Gross pay" value={formatCurrency(pick(detail, "gross_pay", "gross_salary"))} />
          <DetailField label="Net pay" value={formatCurrency(pick(detail, "net_pay", "net_salary"))} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Employee" value={personName(pick(detail, "employee_name", "employee"))} />
          <DetailField label="Employee ID" value={stringValue(pick(detail, "employee_id", "employee.id"))} />
          <DetailField label="Pay period" value={stringValue(pick(detail, "pay_period", "payroll_period", "period"))} />
          <DetailField label="Department" value={stringValue(pick(detail, "department", "employee.department"))} />
          <DetailField label="Role" value={stringValue(pick(detail, "role", "position", "employee.position"))} />
        </DetailGrid>
      </DetailSection>

      {hasNotes && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "line_items", "components") && <DetailNote label="Line items" value={pick(detail, "line_items", "components")} />}
            {hasAny(detail, "allowances") && <DetailNote label="Allowances" value={pick(detail, "allowances")} />}
            {hasAny(detail, "deductions") && <DetailNote label="Deductions" value={pick(detail, "deductions")} />}
            {hasAny(detail, "notes", "approval_notes", "final_approval_notes") && <DetailNote label="Notes" value={pick(detail, "notes", "final_approval_notes", "approval_notes")} />}
            {hasAny(detail, "payout_proof_notes") && <DetailNote label="Payout notes" value={pick(detail, "payout_proof_notes")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Current approver" value={stringValue(pick(detail, "approval.current_approver_role", "current_approver_role"), "Shop owner")} />
          <DetailField label="Generated" value={formatDate(pick(detail, "generated_date", "generated_at", "created_at"))} />
          <DetailField label="Final approver" value={stringValue(pick(detail, "final_approver_name", "final_approver.name", "approved_by.name", "approved_by"))} />
          <DetailField label="Disbursement" value={stringValue(pick(detail, "disbursement_status", "payment_status"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}
