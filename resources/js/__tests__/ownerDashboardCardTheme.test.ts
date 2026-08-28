import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (path: string) => readFileSync(resolve(path), 'utf8');

describe('owner-mode dashboard metric cards', () => {
  it('uses the shared neutral card contract for Inventory metrics', () => {
    const inventory = source('resources/js/Pages/ERP/inventory/InventoryDashboard.tsx');

    expect(inventory).toContain('metrics-card');
    expect(inventory).toContain('bg-gray-100 text-[#111111]');
    expect(inventory).not.toContain('hover:-translate-y-1');
  });

  it('uses the shared neutral card contract for Workforce metrics', () => {
    const workforce = source('resources/js/Pages/ERP/HR/Dashboard.tsx');

    expect(workforce).toContain('metrics-card');
    expect(workforce).toContain('bg-gray-100 text-[#111111]');
    expect(workforce).not.toContain('hover:-translate-y-1');
  });
});
