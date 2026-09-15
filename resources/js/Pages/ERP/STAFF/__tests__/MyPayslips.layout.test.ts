import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const myPayslips = readFileSync(
	join(process.cwd(), "resources/js/Pages/ERP/STAFF/MyPayslips.tsx"),
	"utf8",
);

describe("My Payslips layout", () => {
	it("uses a white light-theme page background", () => {
		expect(myPayslips).toContain(
			'className="min-h-screen bg-white dark:bg-gray-950 px-4 py-8"',
		);
		expect(myPayslips).not.toContain("min-h-screen bg-gray-50");
	});
});
