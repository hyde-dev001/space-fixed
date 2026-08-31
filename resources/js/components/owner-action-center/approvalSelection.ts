import type { OwnerAttentionSourceType } from "../../types/ownerActionCenter";

export interface ApprovalSelection {
  sourceType: OwnerAttentionSourceType;
  sourceId: number;
}

const APPROVAL_SOURCE_TYPES: ReadonlySet<string> = new Set([
  "order_refund",
  "repair_refund",
  "product_price_change",
  "repair_price_change",
  "repair_package_price_change",
  "payslip",
  "salary_change",
  "purchase_request",
  "suspension_request",
  "termination_request",
  "rehire_request",
  "expense",
  "repair_rejection",
]);

export const parseApprovalSelection = (value: unknown): ApprovalSelection | null => {
  if (typeof value !== "string") {
    return null;
  }

  const match = /^([a-z][a-z0-9_]*):([1-9][0-9]*)$/.exec(value);
  if (!match || !APPROVAL_SOURCE_TYPES.has(match[1])) {
    return null;
  }

  const sourceId = Number(match[2]);
  if (!Number.isSafeInteger(sourceId) || sourceId < 1) {
    return null;
  }

  return {
    sourceType: match[1] as OwnerAttentionSourceType,
    sourceId,
  };
};
