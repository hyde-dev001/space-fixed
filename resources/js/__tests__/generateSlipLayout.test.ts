import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const sourcePath = 'resources/js/Pages/ERP/HR/generateSlip.tsx';

describe('generate payslip layout', () => {
	it('keeps the 13th-month release copy while hiding the authorization heading copy', () => {
		const source = readFileSync(resolve(sourcePath), 'utf8');

		expect(source).toContain('13th-Month Release');
		expect(source).toContain('Run controlled December release for your selected year.');
		expect(source).not.toContain('Release Authorization Status');
		expect(source).not.toContain('readiness for release controls');
	});

	it('places the authorization cards above the release controls and employee table', () => {
		const source = readFileSync(resolve(sourcePath), 'utf8');
		const authorizationCardsIndex = source.indexOf('{/* Release Authorization Cards */}');
		const thirteenthMonthControlsIndex = source.indexOf('{/* 13th-Month Controls */}');
		const employeeTableIndex = source.indexOf('{/* Employee Table */}');
		const filtersIndex = source.indexOf('className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5"');

		expect(authorizationCardsIndex).toBeGreaterThanOrEqual(0);
		expect(thirteenthMonthControlsIndex).toBeGreaterThan(authorizationCardsIndex);
		expect(employeeTableIndex).toBeGreaterThan(authorizationCardsIndex);
		expect(employeeTableIndex).toBeGreaterThan(thirteenthMonthControlsIndex);
		expect(filtersIndex).toBeGreaterThan(employeeTableIndex);
	});
});
