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

  it('keeps Finance cards and HR status panels monochrome in Light Mode', () => {
    const finance = source('resources/js/Pages/ERP/Finance/Dashboard.tsx');
    const workforce = source('resources/js/Pages/ERP/HR/Dashboard.tsx');
    const statusPanels = workforce.slice(workforce.indexOf('/* Bottom Section'));

    expect(finance).toContain('data-testid="finance-metric-icon"');
    expect(finance).toContain('bg-gray-100 text-gray-900');
    expect(statusPanels).toContain('metrics-card');
    expect(statusPanels).toContain('bg-gray-900');

    for (const lightModeColor of [
      'bg-blue-100', 'text-blue-600', 'text-blue-700', 'bg-blue-500',
      'bg-purple-100', 'text-purple-600', 'text-purple-700', 'bg-purple-500',
      'bg-orange-100', 'text-orange-600', 'text-orange-700', 'bg-orange-500',
      'bg-green-100', 'text-green-600', 'text-green-700', 'bg-green-500',
      'bg-red-100', 'text-red-600', 'text-red-700', 'bg-red-500',
    ]) {
      expect(statusPanels).not.toContain(lightModeColor);
    }

    expect(statusPanels).toContain('dark:bg-blue-900/30 dark:text-blue-400');
    expect(statusPanels).toContain('dark:bg-purple-900/30 dark:text-purple-400');
    expect(statusPanels).toContain('dark:bg-orange-900/30 dark:text-orange-400');
    expect(statusPanels).toContain('dark:bg-green-900/30 dark:text-green-400');
    expect(statusPanels).toContain('dark:bg-red-900/30 dark:text-red-400');
  });
});
