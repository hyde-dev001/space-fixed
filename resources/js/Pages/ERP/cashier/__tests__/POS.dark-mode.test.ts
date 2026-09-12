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

	it("keeps icon-only cart removal actions explicitly destructive", () => {
		for (const label of ["Remove product", "Unselect package", "Remove add-on", "Remove item"]) {
			expect(posSource).toContain(`aria-label="${label}"`);
		}
		expect(posSource.match(/data-erp-icon-action="true"/g)).toHaveLength(4);
		expect(posSource.match(/data-semantic-color="danger"/g)).toHaveLength(4);
	});

	it("keeps package and individual service cards white with black outlines and text", () => {
		expect(posSource).toContain('data-catalog-card="true"');
		expect(posSource).toContain("border-black bg-white p-4 text-left text-black");
		expect(posSource).toContain("enabled:hover:border-black enabled:hover:bg-white");
		expect(posSource).toContain("border border-black bg-white px-2 py-1 text-[10px] font-semibold uppercase text-black");
		expect(posSource).toContain("border-t border-black pt-3");
	});

	it("does not apply hover styling to disabled ERP controls", () => {
		expect(appStyles).toContain(":not([data-catalog-card]):disabled");
		expect(appStyles).toContain(":not([data-catalog-card]):disabled:hover");
		expect(appStyles).toContain("background-color: var(--erp-surface-muted) !important;");
		expect(appStyles).toContain("cursor: not-allowed !important;");
	});
});
