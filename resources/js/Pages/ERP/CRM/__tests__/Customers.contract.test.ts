import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const source = readFileSync(resolve("resources/js/Pages/ERP/CRM/Customers.tsx"), "utf8");

describe("CRM customer detail modal contract", () => {
  it("removes the edit customer action while retaining the close action", () => {
    const detailsModal = source.slice(source.indexOf("{showDetailsModal &&"));

    expect(detailsModal).not.toContain("Edit Customer");
    expect(detailsModal).toMatch(/>\s*Close\s*<\/button>/);
  });
});
