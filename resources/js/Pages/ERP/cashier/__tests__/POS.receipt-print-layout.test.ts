import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const posSource = readFileSync(
	join(__dirname, '../POS.tsx'),
	'utf8',
);

describe('Cashier POS receipt print layout', () => {
	it('uses the full printable page instead of leaving the receipt in a narrow thermal column', () => {
		expect(posSource).toContain('size: A4;');
		expect(posSource).toContain('margin: 10mm;');
		expect(posSource).toContain('.receipt-print-modal');
		expect(posSource).toContain('.receipt-print-card');
		expect(posSource).toContain('.receipt-modal-content');
		expect(posSource).toContain('min-height: calc(297mm - 20mm);');
		expect(posSource).toContain('font-size: 14px !important;');
		expect(posSource).not.toContain('size: 80mm auto;');
		expect(posSource).not.toContain('max-width: 80mm');
	});
});
