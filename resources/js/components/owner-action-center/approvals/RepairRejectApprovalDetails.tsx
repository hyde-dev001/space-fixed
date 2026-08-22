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

export default function RepairRejectApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "approval_status", "repair_status") ?? "pending_owner";
  const hasEvidence = hasAny(detail, "rejection_reason", "repairer_rejection_reason", "reason", "notes");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Repair value" value={formatCurrency(pick(detail, "total", "repair_total", "amount") ?? item.comparable_monetary_exposure)} />
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Approval stage" value="Shop owner" />
          <DetailField label="Record" value={item.title} />
        </DetailGrid>
      </DetailSection>

      <DetailSection title="Request details">
        <DetailGrid>
          <DetailField label="Repair request" value={stringValue(pick(detail, "reference", "repair_number", "request_number", "id"))} />
          <DetailField label="Customer" value={stringValue(pick(detail, "customer.name", "customer_name"))} />
          <DetailField label="Shoe or item" value={stringValue(pick(detail, "shoe.name", "shoe_description", "item_description", "shoe"))} />
          <DetailField label="Repair description" value={stringValue(pick(detail, "repair_description", "description", "service_description"))} />
          <DetailField label="Repairer" value={stringValue(pick(detail, "repairer.name", "repairer_name"))} />
        </DetailGrid>
      </DetailSection>

      {hasEvidence && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "rejection_reason", "repairer_rejection_reason", "reason") && <DetailNote label="Rejection reason" value={pick(detail, "rejection_reason", "repairer_rejection_reason", "reason")} />}
            {hasAny(detail, "notes") && <DetailNote label="Notes" value={pick(detail, "notes")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Rejected by" value={stringValue(pick(detail, "rejected_by.name", "repairer.name", "rejected_by"))} />
          <DetailField label="Manager review" value={stringValue(pick(detail, "manager.name", "manager_status"))} />
          <DetailField label="Owner review" value={formatStatus(status)} />
          <DetailField label="Rejected at" value={formatDate(pick(detail, "repairer_rejected_at", "rejected_at"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}

