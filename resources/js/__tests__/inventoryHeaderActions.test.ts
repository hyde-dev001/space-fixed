import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (path: string) => readFileSync(resolve(path), 'utf8');

describe('inventory header actions', () => {
	it('right-aligns upload stock actions', () => {
		const source = read('resources/js/Pages/ERP/inventory/UploadInventory.tsx');

		expect(source).toContain('className="ml-auto flex items-center gap-3"');
		expect(source).toContain('Show Archived');
		expect(source).toContain('+ Add Stock Entry');
	});

	it('removes the visible Stock Movement refresh action', () => {
		const source = read('resources/js/Pages/ERP/inventory/StockMovement.tsx');
		const metricsIndex = source.indexOf('<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">');
		const header = source.slice(0, metricsIndex);

		expect(header).not.toContain('Refresh');
		expect(source).toContain('void loadMovements();');
	});

	it('right-aligns the new stock request action', () => {
		const source = read('resources/js/Pages/ERP/inventory/StockRequest.tsx');

		expect(source).toContain('className="ml-auto flex flex-wrap items-center gap-2"');
		expect(source).toContain('+ New Request');
	});
});
