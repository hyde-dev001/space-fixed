import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatCurrency,
  formatDate,
  formatStatus,
  hasAny,
  isRecord,
  personName,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

export default function RepairRejectApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "approval_status", "repair_status") ?? "pending_owner";
  const hasEvidence = hasAny(detail, "rejection_reason", "repairer_rejection_reason", "reason", "notes", "owner_review_notes");
  const services = Array.isArray(detail.services)
    ? detail.services
      .map((service) => isRecord(service) ? stringValue(pick(service, "name", "title"), "") : "")
      .filter(Boolean)
      .join(", ")
    : "";

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
          <DetailField label="Customer" value={personName(pick(detail, "user", "customer", "customer_name"))} />
          <DetailField label="Shoe or item" value={stringValue(pick(detail, "shoe.name", "shoe_description", "item_description", "shoe"))} />
          <DetailField label="Repair description" value={stringValue(pick(detail, "repair_description", "description", "service_description", "repairer_rejection_reason"))} />
          <DetailField label="Services" value={stringValue(services)} />
          <DetailField label="Repairer" value={personName(pick(detail, "repairer", "repairer_name"))} />
        </DetailGrid>
      </DetailSection>

      {hasEvidence && (
        <DetailSection title="Evidence/notes">
          <dl className="space-y-3">
            {hasAny(detail, "rejection_reason", "repairer_rejection_reason", "reason") && <DetailNote label="Rejection reason" value={pick(detail, "rejection_reason", "repairer_rejection_reason", "reason")} />}
            {hasAny(detail, "notes", "owner_review_notes") && <DetailNote label="Notes" value={pick(detail, "notes", "owner_review_notes")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Workflow/history">
        <DetailGrid>
          <DetailField label="Rejected by" value={personName(pick(detail, "repairerRejectedBy", "rejected_by", "repairer"))} />
          <DetailField label="Manager review" value={personName(pick(detail, "managerReviewedBy", "manager", "manager_status"))} />
          <DetailField label="Owner review" value={formatStatus(status)} />
          <DetailField label="Owner reviewed by" value={personName(pick(detail, "ownerReviewedBy", "owner_reviewed_by"))} />
          <DetailField label="Rejected at" value={formatDate(pick(detail, "repairer_rejected_at", "rejected_at"))} />
          <DetailField label="Updated" value={formatDate(pick(detail, "updated_at", "owner_reviewed_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}
