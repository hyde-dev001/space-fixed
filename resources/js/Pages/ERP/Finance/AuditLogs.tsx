import AuditLogs from "../Common/AuditLogs";

export default function FinanceAuditLogs() {
  return (
    <AuditLogs
      title="Finance Audit Logs"
      description="Review finance-related activity and approvals for this shop."
      capabilityKey="GET:finance.audit.index"
    />
  );
}
