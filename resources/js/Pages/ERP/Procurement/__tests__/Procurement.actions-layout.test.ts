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
});
