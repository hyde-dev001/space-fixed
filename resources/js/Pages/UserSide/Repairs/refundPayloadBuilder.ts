export type RefundEvidenceItem = { type: "photo" | "video"; url: string };

export type PreferredReturnChannel = "gcash" | "card" | "bank_transfer" | "manual_cash";

export function buildRepairRefundPayload(input: {
  sourceTransactionId: number;
  requestedAmount: number;
  reasonCode: string;
  reasonNotes: string;
  preferredReturnChannel: PreferredReturnChannel;
  preferredReturnAccountName: string;
  preferredReturnAccountRef: string;
  customerPayoutConsent: boolean;
  evidence: RefundEvidenceItem[];
}) {
  return {
    source_transaction_id: input.sourceTransactionId,
    request_type: "full" as const,
    requested_amount: input.requestedAmount,
    reason_code: input.reasonCode,
    reason_notes: input.reasonNotes,
    preferred_return_channel: input.preferredReturnChannel,
    preferred_return_account_name: input.preferredReturnAccountName,
    preferred_return_account_ref: input.preferredReturnAccountRef,
    customer_payout_consent: input.customerPayoutConsent,
    evidence: input.evidence,
  };
}
