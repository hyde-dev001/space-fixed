import { describe, expect, it } from "vitest";
import {
  parseApprovalSelection,
  type ApprovalSelection,
} from "../../../../components/owner-action-center/approvalSelection";

describe("Action Center approval deep links", () => {
  it.each([
    ["order_refund", 12],
    ["repair_refund", 13],
    ["product_price_change", 14],
    ["repair_price_change", 15],
    ["repair_package_price_change", 16],
    ["payslip", 17],
    ["salary_change", 18],
    ["purchase_request", 19],
    ["expense", 20],
    ["repair_rejection", 21],
  ] as const)("parses the typed %s selection", (sourceType, sourceId) => {
    expect(parseApprovalSelection(sourceType + ":" + sourceId)).toEqual({
      sourceType,
      sourceId,
    } satisfies ApprovalSelection);
  });

  it("does not interpret legacy page query strings as a typed selection", () => {
    expect(parseApprovalSelection("refund_type=order&refund=12")).toBeNull();
    expect(parseApprovalSelection("expense=34")).toBeNull();
  });
});
