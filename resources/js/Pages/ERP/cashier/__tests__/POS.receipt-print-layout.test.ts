import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const posSource = readFileSync(
	join(__dirname, '../POS.tsx'),
	'utf8',
);

describe('Cashier POS receipt print layout', () => {
	it('uses a compact thermal page instead of expanding the receipt to A4', () => {
		expect(posSource).toContain('size: 80mm auto;');
		expect(posSource).toContain('.receipt-print-modal');
		expect(posSource).toContain('.receipt-print-card');
		expect(posSource).toContain('.receipt-modal-content');
		expect(posSource).toContain('max-width: 80mm');
		expect(posSource).not.toContain('size: A4;');
		expect(posSource).not.toContain('min-height: calc(297mm - 24mm)');
	});
});
