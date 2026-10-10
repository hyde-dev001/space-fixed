import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const purchaseRequest = readFileSync(
	join(process.cwd(), "resources/js/Pages/ERP/Procurement/PurchaseRequest.tsx"),
	"utf8",
);
const purchaseOrders = readFileSync(
	join(process.cwd(), "resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx"),
	"utf8",
);
const suppliersManagement = readFileSync(
	join(process.cwd(), "resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx"),
	"utf8",
);

const rightAlignedActions =
	'className="flex flex-col items-end lg:flex-row lg:items-center lg:justify-end gap-4"';

describe("Procurement page actions layout", () => {
	it("keeps the new purchase request action on the right", () => {
		expect(purchaseRequest).toContain(rightAlignedActions);
		expect(purchaseRequest).not.toContain("lg:justify-between");
	});

	it("keeps the new purchase order action on the right", () => {
		expect(purchaseOrders).toContain(rightAlignedActions);
		expect(purchaseOrders).not.toContain("lg:justify-between");
	});

	it("keeps supplier archive and add actions on the right", () => {
		expect(suppliersManagement).toContain(rightAlignedActions);
		expect(suppliersManagement).not.toContain("lg:justify-between");
	});

	it("keeps the add supplier modal inside the viewport with a scrollable body", () => {
		expect(suppliersManagement).toContain('overflow-y-auto px-4 py-6 sm:py-8');
		expect(suppliersManagement).toContain('max-h-[calc(100dvh-2rem)] w-full max-w-5xl');
		expect(suppliersManagement).toContain('min-h-0 flex-1 overflow-y-auto p-5 space-y-5 sm:p-6');
	});
});
