import fs from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const posSource = fs.readFileSync(
	path.resolve(process.cwd(), "resources/js/Pages/ERP/cashier/POS.tsx"),
	"utf8",
);
const appStyles = fs.readFileSync(
	path.resolve(process.cwd(), "resources/css/app.css"),
	"utf8",
);

describe("Cashier POS dark mode contract", () => {
	it("scopes the cashier surface to the ERP dark palette", () => {
		expect(posSource).toContain('className="cashier-pos-page space-y-6 p-4 md:p-6"');
		expect(appStyles).toContain(".dark #app .erp-theme .cashier-pos-page");
		expect(appStyles).toContain('[class~="bg-white"]');
		expect(appStyles).toContain('[class~="text-slate-900"]');
		expect(appStyles).toContain(":is(input, select, textarea)");
	});
});
