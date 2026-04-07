export type RepairExecutionChannel = "gcash" | "card" | "bank_transfer" | "manual_cash";

export function buildRepairRefundExecutionPayload(input: {
  executionMode: "manual" | "gateway";
  executionChannel?: RepairExecutionChannel;
  executionReference?: string;
  executionAmount?: number;
  executionProofUrls?: string[];
}) {
  if (input.executionMode === "manual") {
    if (!input.executionReference?.trim()) {
      throw new Error("Execution reference is required for manual POS refund execution");
    }

    if (!input.executionProofUrls || input.executionProofUrls.length === 0) {
      throw new Error("At least one execution proof is required for manual POS refund execution");
    }
  }

  return {
    execution_mode: input.executionMode,
    execution_channel: input.executionChannel ?? null,
    execution_reference: input.executionReference ?? null,
    execution_amount: input.executionAmount ?? null,
    execution_proof_urls: input.executionProofUrls ?? [],
  };
}
