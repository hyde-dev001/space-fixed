import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const crmSource = readFileSync(join(__dirname, "../Customers.tsx"), "utf8");
const shopOwnerSource = readFileSync(
  join(__dirname, "../../../ShopOwner/Customers/customer management/Customers.tsx"),
  "utf8",
);

const listTableSource = (source: string) => {
  const tableStart = source.indexOf("<table");
  const modalStart = source.indexOf("{showDetailsModal");

  return source.slice(tableStart, modalStart === -1 ? source.length : modalStart);
};

describe("customer list identity labels", () => {
  it("does not show the internal Customer # label in CRM or Shop Owner list rows", () => {
    expect(listTableSource(crmSource)).not.toContain("Customer #");
    expect(listTableSource(shopOwnerSource)).not.toContain("Customer #");
  });
});
