import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const currentDir = dirname(fileURLToPath(import.meta.url));
const sourcePath = resolve(currentDir, "../JobOrdersRepair.tsx");
const source = readFileSync(sourcePath, "utf-8");

describe("Repair job orders visual presentation", () => {
  it("right-aligns the workload summary", () => {
    const headerStart = source.indexOf("{/* Header */}");
    const metricsStart = source.indexOf("{/* Metrics */}", headerStart);
    const header = source.slice(headerStart, metricsStart);

    expect(header).toContain('<div className="flex justify-end">');
    expect(header).toContain("Active workload:");
    expect(header).toContain("Pending refund reviews:");
  });

  it("uses background-free monochrome table statuses", () => {
    const primaryStatus = source.indexOf("getRepairStatusLabel(order.status)");
    const statusCellStart = source.lastIndexOf("<td", primaryStatus);
    const statusCellEnd = source.indexOf("</td>", primaryStatus);
    const statusCell = source.slice(statusCellStart, statusCellEnd);

    expect(statusCell).toContain("text-gray-900 dark:text-white");
    expect(statusCell).not.toMatch(/bg-[a-z0-9/-]+/);
    expect(statusCell).not.toContain("rounded-full");
  });
});
