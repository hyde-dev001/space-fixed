import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const payslipApproval = readFileSync(
  join(process.cwd(), "resources/js/Pages/ERP/Finance/payslipApproval.tsx"),
  "utf8",
);

describe("Finance payslip approval presentation", () => {
  it("keeps search refreshes debounced and on-page", () => {
    expect(payslipApproval).toContain("AbortController");
    expect(payslipApproval).toContain("window.setTimeout");
    expect(payslipApproval).toContain("initialLoading");
    expect(payslipApproval).toContain("AbortError");
  });

  it("uses the My Payslips earnings and deductions breakdown in a wide modal", () => {
    const detailModalStart = payslipApproval.indexOf("{viewModalOpen && selectedRequest && (");
    const batchModalStart = payslipApproval.indexOf("{/* Batch Approval Preview Modal */}", detailModalStart);
    const detailModal = payslipApproval.slice(detailModalStart, batchModalStart);

    expect(detailModal).toContain("max-w-6xl");
    expect(detailModal).not.toContain("overflow-y-auto");
    expect(detailModal).toContain("Earnings");
    expect(detailModal).toContain("Deductions");
    expect(detailModal).toContain("Gross Pay");
    expect(detailModal).toContain("Total Deductions");
    expect(detailModal).toContain("NET PAY");
  });

  it("labels deduction percentages against gross pay", () => {
    expect(payslipApproval).toContain("% of gross pay");
  });
});
