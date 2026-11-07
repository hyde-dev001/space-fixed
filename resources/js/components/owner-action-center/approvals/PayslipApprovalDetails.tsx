import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  hasAny,
  isRecord,
  numberValue,
  personName,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";
import { formatDeductionPercentage } from "../../../utils/payrollDeductions";

export default function PayslipApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "approval_status") ?? "pending";
  const grossPay = numberValue(pick(detail, "gross_pay", "gross_salary")) ?? 0;
  const totalDeductions = numberValue(pick(detail, "deductions", "total_deductions")) ?? 0;
  const lineItemsValue = pick(detail, "line_items", "components");
  const deductionLineItems = Array.isArray(lineItemsValue)
    ? lineItemsValue.filter((lineItem): lineItem is Record<string, unknown> => (
      isRecord(lineItem) && stringValue(pick(lineItem, "type", "component_type"), "") === "deduction"
    ))
    : [];
  const hasNotes = hasAny(detail, "notes", "approval_notes", "final_approval_notes", "allowances", "deductions", "payout_proof_notes");

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

      {deductionLineItems.length > 0 && (
        <DetailSection title="Deductions (% of gross pay)">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-left dark:border-gray-800">
                  <th className="py-2 pr-3 font-medium text-gray-500 dark:text-gray-400">Description</th>
                  <th className="py-2 text-right font-medium text-gray-500 dark:text-gray-400">Amount</th>
                  <th className="py-2 pl-3 text-right font-medium text-gray-500 dark:text-gray-400">% of gross pay</th>
                </tr>
              </thead>
              <tbody>
                {deductionLineItems.map((lineItem, index) => {
                  const amount = numberValue(pick(lineItem, "amount", "calculated_amount")) ?? 0;

                  return (
                    <tr key={`${stringValue(pick(lineItem, "label", "component_name"), "deduction")}-${index}`} className="border-b border-gray-100 dark:border-gray-800">
                      <td className="py-2 pr-3 text-gray-700 dark:text-gray-300">{stringValue(pick(lineItem, "label", "component_name"), "Deduction")}</td>
                      <td className="py-2 text-right text-red-600 dark:text-red-400">−{formatCurrency(amount)}</td>
                      <td className="py-2 pl-3 text-right text-gray-500 dark:text-gray-400">{formatDeductionPercentage(amount, grossPay)}</td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot>
                <tr>
                  <td className="pt-3 font-semibold text-gray-900 dark:text-white">Total deductions</td>
                  <td className="pt-3 text-right font-semibold text-red-600 dark:text-red-400">−{formatCurrency(totalDeductions)}</td>
                  <td className="pt-3 pl-3 text-right font-semibold text-gray-700 dark:text-gray-300">{formatDeductionPercentage(totalDeductions, grossPay)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </DetailSection>
      )}

      {hasNotes && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
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
