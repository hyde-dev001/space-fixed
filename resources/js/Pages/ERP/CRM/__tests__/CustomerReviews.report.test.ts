import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const source = readFileSync(resolve("resources/js/Pages/ERP/CRM/CustomerReviews.tsx"), "utf8");

describe("customer review reporting", () => {
  it("keeps the report action available to Shop Owners and uses the owner endpoint", () => {
    expect(source).toContain('const reportEndpoint = ownerMode ? "/api/shop-owner/reviews/report" : "/api/crm/reviews/report";');
    expect(source).toContain("        reportEndpoint,");
    expect(source).not.toContain("if (!selectedReview || ownerMode) return;");
    expect(source).not.toContain("{!ownerMode && (reportedIds.has(getReportIdentifier(selectedReview))");
    expect(source).not.toContain("{showReportModal && selectedReview && !ownerMode && (");
  });
});
