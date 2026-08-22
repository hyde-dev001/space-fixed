import { describe, expect, it } from "vitest";
import { parseApprovalSelection } from "../approvalSelection";

describe("parseApprovalSelection", () => {
  it("parses a supported source and positive numeric identifier", () => {
    expect(parseApprovalSelection("order_refund:123")).toEqual({
      sourceType: "order_refund",
      sourceId: 123,
    });
  });

  it("rejects zero and negative identifiers", () => {
    expect(parseApprovalSelection("order_refund:0")).toBeNull();
    expect(parseApprovalSelection("order_refund:-1")).toBeNull();
  });

  it("rejects unknown source types", () => {
    expect(parseApprovalSelection("unknown:123")).toBeNull();
  });

  it("rejects injection-shaped and malformed values", () => {
    expect(parseApprovalSelection("1 OR 1=1")).toBeNull();
    expect(parseApprovalSelection("order_refund:123:extra")).toBeNull();
    expect(parseApprovalSelection("order_refund:001")).toBeNull();
    expect(parseApprovalSelection("order_refund:9007199254740992")).toBeNull();
  });
});
