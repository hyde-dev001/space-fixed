import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const source = readFileSync(resolve("resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx"), "utf8");

describe("Manager suspension approvals page contract", () => {
    it("keeps approval decisions inside the request details dialog", () => {
        const actionButtons = source.slice(
            source.indexOf("const ActionButtons"),
            source.indexOf("const SuspensionRequestCard"),
        );
        const detailsDialog = source.slice(
            source.indexOf("{selectedRequest &&"),
            source.indexOf("{requestToReject &&"),
        );

        expect(actionButtons).not.toContain("Approve stage");
        expect(actionButtons).not.toContain("Reject request");
        expect(detailsDialog).toContain("Approve stage");
        expect(detailsDialog).toContain("Reject request");
    });

    it("shows a success alert after either Manager decision", () => {
        expect(source).toContain("workflowFeedback.success");
        expect(source).toContain("Suspension stage approved");
        expect(source).toContain("Suspension request rejected");
    });

    it("labels the HR-provided reason and evidence in the Manager details", () => {
        expect(source).toContain("HR suspension reason");
        expect(source).toContain("HR evidence / details");
        expect(source).toContain("request.reason");
        expect(source).toContain("request.evidence");
    });
    it("keeps the summary neutral and removes the redundant latest-request card", () => {
        const metricCard = source.slice(source.indexOf('const MetricCard'), source.indexOf('const LoadingState'));
        expect(metricCard).toContain('border-gray-200 bg-white');
        expect(metricCard).not.toContain('tones');
        expect(metricCard).not.toContain('tone:');
        expect(metricCard).not.toContain('border-amber');
        expect(metricCard).not.toContain('border-blue');
        expect(metricCard).not.toContain('border-emerald');
        expect(metricCard).not.toContain('border-red');
        expect(source).not.toContain('Latest request:');
        expect(source).not.toContain('{page?.total ?? 0} request(s)');
    });
});
