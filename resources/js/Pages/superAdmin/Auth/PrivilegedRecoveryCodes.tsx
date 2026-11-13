import PrivilegedAuthShell from './PrivilegedAuthShell';
import RecoveryCodesPanel from './RecoveryCodesPanel';

interface PrivilegedRecoveryCodesProps {
  recoveryCodes: string[];
  acknowledgementToken: string;
  acknowledgementEndpoint?: string;
  continueLabel?: string;
  useJsonRequest?: boolean;
  onAcknowledged?: () => void;
}

export default function PrivilegedRecoveryCodes({
  recoveryCodes,
  acknowledgementToken,
  acknowledgementEndpoint,
  continueLabel,
  useJsonRequest,
  onAcknowledged,
}: PrivilegedRecoveryCodesProps) {
  return (
    <PrivilegedAuthShell
      title="Save your recovery codes"
      description="Recovery codes are an emergency sign-in method. Store them offline and never share them with another person."
    >
      <RecoveryCodesPanel
        recoveryCodes={recoveryCodes}
        acknowledgementToken={acknowledgementToken}
        acknowledgementEndpoint={acknowledgementEndpoint}
        continueLabel={continueLabel}
        useJsonRequest={useJsonRequest}
        onAcknowledged={onAcknowledged ?? (() => undefined)}
      />
    </PrivilegedAuthShell>
  );
}
