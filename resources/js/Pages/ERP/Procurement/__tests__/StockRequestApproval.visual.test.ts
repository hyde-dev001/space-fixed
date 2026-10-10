import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const source = readFileSync(resolve("resources/js/Pages/ERP/Procurement/StockRequestApproval.tsx"), "utf8");

describe("Procurement stock request details presentation", () => {
	it("uses white neutral detail panels while preserving approval content and actions", () => {
		const detailsModal = source.slice(source.indexOf("Stock Request Details"), source.indexOf("Inventory Notes"));

		expect(detailsModal).toContain("rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900");
		expect(detailsModal).toContain("Requested Size");
		expect(detailsModal).toContain("Requested Color");
		expect(detailsModal).toContain("Available Sizes");
		expect(detailsModal).not.toContain("bg-indigo-50");
		expect(detailsModal).not.toContain("bg-purple-50");
		expect(detailsModal).not.toContain("bg-blue-50");
		expect(source).toContain("Approve");
		expect(source).toContain("Reject");
	});
});
