import AuditLogs from "../Common/AuditLogs";

export default function HRAuditLogs() {
  return (
    <AuditLogs
      title="HR Audit Logs"
      description="Review HR activity for this shop, including employee and workforce changes."
      capabilityKey="GET:hr.audit.index"
    />
  );
}
