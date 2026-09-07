import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const readPage = (fileName: string) => readFileSync(
  join(process.cwd(), "resources/js/Pages/ERP/Finance", fileName),
  "utf8",
);

const expense = readPage("Expense.tsx");
const createInvoice = readPage("createInvoice.tsx");
const invoice = readPage("Invoice.tsx");

describe("Finance UI consistency presentation", () => {
  it("uses a neutral surface for procured stock details", () => {
    expect(expense).toContain("rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 space-y-2");
    expect(expense).not.toContain("border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20");
    expect(expense).toContain("Procured Stock Details");
  });

  it("right-aligns Save Invoice with the back link and keeps it black", () => {
    expect(createInvoice).toContain("flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between");
    expect(createInvoice).toContain("bg-gray-950");
    expect(createInvoice).not.toContain("bg-blue-600 text-white hover:bg-blue-700 shadow-sm inline-flex items-center gap-2");
  });

  it("uses neutral invoice actions while preserving semantic status colors", () => {
    expect(invoice).toContain("p-2.5 bg-gray-950 hover:bg-gray-800 text-white rounded-lg transition-colors");
    expect(invoice).not.toContain("p-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors");
    expect(invoice).not.toContain("border-2 border-purple-300 dark:border-purple-700 bg-white dark:bg-gray-800 hover:bg-purple-50 dark:hover:bg-purple-900/20");
    expect(invoice).not.toContain("p-2 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg transition-colors");
    expect(invoice).not.toContain("p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors");
    expect(invoice).toContain('paid: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400"');
  });
});
