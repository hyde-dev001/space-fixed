import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const createInvoice = readFileSync(
  join(process.cwd(), "resources/js/Pages/ERP/Finance/createInvoice.tsx"),
  "utf8",
);

describe("Create Invoice layout", () => {
  it("allows the tax-rate dropdown menu to render beyond the line-item card", () => {
    expect(createInvoice).toContain(
      'className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-visible"',
    );
  });

  it("uses a white light-theme page background", () => {
    expect(createInvoice).toContain(
      'className="min-h-screen bg-white dark:bg-gray-900 py-6 px-4 sm:px-6 lg:px-8"',
    );
    expect(createInvoice).not.toContain("min-h-screen bg-gray-50");
  });
});
