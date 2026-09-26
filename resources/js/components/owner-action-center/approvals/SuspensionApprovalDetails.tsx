import {
  DetailField,
  DetailGrid,
  DetailNote,
  DetailSection,
  formatDate,
  formatStatus,
  hasAny,
  pick,
  StatusBadge,
  stringValue,
  type ApprovalDetailRendererProps,
} from "../approvalDetails";

export default function SuspensionApprovalDetails({ detail, item }: ApprovalDetailRendererProps) {
  const status = pick(detail, "status", "owner_status") ?? "pending";
  const managerStatus = pick(detail, "manager_status") ?? "approved";
  const hasEvidence = hasAny(detail, "reason", "evidence", "manager_note", "owner_note");

  return (
    <div className="space-y-4">
      <DetailSection title="Decision summary">
        <DetailGrid>
          <DetailField label="Status" value={<StatusBadge value={status} />} />
          <DetailField label="Review stage" value={formatStatus(managerStatus === "approved" ? "owner_approval" : managerStatus)} />
          <DetailField label="Record" value={item.title} />
          <DetailField label="Request ID" value={stringValue(pick(detail, "id"), `#${item.source_id}`)} />
        </DetailGrid>
      </DetailSection>

      {hasEvidence && (
        <DetailSection title="HR request and review notes">
          <dl className="space-y-3">
            {hasAny(detail, "reason") && <DetailNote label="HR suspension reason" value={pick(detail, "reason")} />}
            {hasAny(detail, "evidence") && <DetailNote label="HR evidence / details" value={pick(detail, "evidence")} />}
            {hasAny(detail, "manager_note") && <DetailNote label="Manager note" value={pick(detail, "manager_note")} />}
            {hasAny(detail, "owner_note") && <DetailNote label="Owner note" value={pick(detail, "owner_note")} />}
          </dl>
        </DetailSection>
      )}

      <DetailSection title="Employee details">
        <DetailGrid>
          <DetailField label="Employee" value={stringValue(pick(detail, "name", "employee.name"), "Employee")} />
          <DetailField label="Email" value={stringValue(pick(detail, "email", "employee.email"))} />
          <DetailField label="Position" value={stringValue(pick(detail, "position", "employee.position"))} />
          <DetailField label="Requested by" value={stringValue(pick(detail, "requested_by", "requester.name"))} />
          <DetailField label="Submitted" value={formatDate(pick(detail, "requested_at", "created_at", "submitted_at"))} />
        </DetailGrid>
      </DetailSection>
    </div>
  );
}
