import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const readPage = (name: string): string => readFileSync(
  `resources/js/Pages/ShopOwner/Operations/${name}.tsx`,
  "utf8",
);

describe("Shop Owner operations monitoring surfaces", () => {
  it("uses the Manager Job Orders visual contract without Manager mutation actions", () => {
    const source = readPage("JobOrders");

    expect(source).toContain("Job Orders");
    expect(source).toContain("Monitor the shop-wide order workload");
    expect(source).toContain("View details");
    expect(source).not.toContain("Reassign order");
    expect(source).not.toContain("Reassign Order");
  });

  it("uses the Manager Repair Jobs visual contract without Manager review actions", () => {
    const source = readPage("RepairJobs");

    expect(source).toContain("Repair Jobs");
    expect(source).toContain("each repairer");
    expect(source).toContain("View details");
    expect(source).not.toContain("Repair Review");
    expect(source).not.toContain("Reassign Repairer");
    expect(source).not.toContain("Final Reject");
  });
});
