import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const readPage = (name: string): string => readFileSync(
  `resources/js/Pages/ShopOwner/Operations/${name}.tsx`,
  "utf8",
);

describe("Shop Owner operations monitoring surfaces", () => {
  it("uses the Manager Job Orders visual contract without Manager mutation actions", () => {
    const source = readPage("JobOrders");

    expect(source).toContain('<h1 id="owner-job-orders-title" className="sr-only">Job Orders</h1>');
    expect(source).toContain("View details");
    expect(source).not.toContain("Reassign order");
    expect(source).not.toContain("Reassign Order");
  });

  it("keeps the order snapshot in the filter action row", () => {
    const source = readPage("JobOrders");
    const filterSection = source.indexOf('aria-labelledby="owner-order-filters-title"');
    const tableSection = source.indexOf('aria-labelledby="owner-order-table-title"');
    const applyFilters = source.indexOf(">Apply filters</button>");
    const orderSnapshot = source.indexOf("orders in view");

    expect(orderSnapshot).toBeGreaterThan(filterSection);
    expect(orderSnapshot).toBeLessThan(tableSection);
    expect(orderSnapshot).toBeGreaterThan(applyFilters);
    expect(source).toContain("justify-between gap-4 md:col-span-2 xl:col-span-6");
    expect(source).toContain("ml-auto rounded-xl");
  });

  it("uses the Manager Repair Jobs visual contract without Manager review actions", () => {
    const source = readPage("RepairJobs");

    expect(source).toContain('<h1 id="owner-repair-jobs-title" className="sr-only">Repair Jobs</h1>');
    expect(source).toContain("View details");
    expect(source).not.toContain("Repair Review");
    expect(source).not.toContain("Reassign Repairer");
    expect(source).not.toContain("Final Reject");
  });
});
