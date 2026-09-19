import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const source = readFileSync(resolve("resources/js/Pages/ERP/manager/LeaveApprovals.tsx"), "utf8");

describe("Manager leave approvals feedback contract", () => {
  it("uses SweetAlert confirmation for both approval decisions", () => {
    expect(source).toContain('import { workflowFeedback } from "../../../utils/workflowFeedback";');
    expect(source).not.toContain("window.confirm");
    expect(source).not.toContain("window.alert");
    expect(source.match(/workflowFeedback\.confirm/g)).toHaveLength(2);
    expect(source).toContain('confirmButtonColor: "#059669"');
    expect(source).toContain('confirmButtonColor: "#dc2626"');
  });
});
