import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const batches = readFileSync(
	join(process.cwd(), "resources/js/Pages/ERP/Logistics/Batches.tsx"),
	"utf8",
);
const riders = readFileSync(
	join(process.cwd(), "resources/js/Pages/ERP/Logistics/Riders.tsx"),
	"utf8",
);

describe("Logistics page actions layout", () => {
	it("keeps the module filter and new batch action on the right", () => {
		expect(batches).toContain(
			'className="flex min-w-0 flex-wrap items-start justify-end gap-3"',
		);
	});

	it("keeps rider filters on the right", () => {
		expect(riders).toContain(
			'className="flex flex-col gap-3 sm:gap-4 sm:flex-row sm:items-center sm:justify-end"',
		);
	});
});
