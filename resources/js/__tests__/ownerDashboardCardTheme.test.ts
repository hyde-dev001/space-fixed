import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (path: string) => readFileSync(resolve(path), 'utf8');

describe('owner-mode dashboard metric cards', () => {
  it('uses the shared neutral card contract for Inventory metrics', () => {
    const inventory = source('resources/js/Pages/ERP/inventory/InventoryDashboard.tsx');
    const metricCard = source('resources/js/components/dashboard/DashboardMetricCard.tsx');

    expect(inventory).toContain('DashboardMetricCard');
    expect(metricCard).toContain('metrics-card');
    expect(metricCard).toContain('bg-gray-100');
    expect(metricCard).toContain('bg-white');
    expect(inventory).not.toContain('hover:-translate-y-1');
  });

  it('uses the shared neutral card contract for Workforce metrics', () => {
    const workforce = source('resources/js/Pages/ERP/HR/Dashboard.tsx');
    const metricCard = source('resources/js/components/dashboard/DashboardMetricCard.tsx');

    expect(workforce).toContain('DashboardMetricCard');
    expect(metricCard).toContain('metrics-card');
    expect(metricCard).toContain('bg-gray-100');
    expect(metricCard).toContain('bg-white');
    expect(workforce).not.toContain('hover:-translate-y-1');
  });

  it('keeps Finance cards and HR status panels on the shared monochrome surface', () => {
    const finance = source('resources/js/Pages/ERP/Finance/Dashboard.tsx');
    const workforce = source('resources/js/Pages/ERP/HR/Dashboard.tsx');
    const metricCard = source('resources/js/components/dashboard/DashboardMetricCard.tsx');

    expect(finance).toContain('iconTestId="finance-metric-icon"');
    expect(metricCard).toContain('metrics-card');
    expect(workforce).toContain('DashboardPanel');

    for (const lightModeColor of [
      'bg-blue-100', 'text-blue-600', 'text-blue-700', 'bg-blue-500',
      'bg-purple-100', 'text-purple-600', 'text-purple-700', 'bg-purple-500',
      'bg-orange-100', 'text-orange-600', 'text-orange-700', 'bg-orange-500',
      'bg-green-100', 'text-green-600', 'text-green-700', 'bg-green-500',
      'bg-red-100', 'text-red-600', 'text-red-700', 'bg-red-500',
    ]) {
      expect(finance).not.toContain(lightModeColor);
      expect(workforce).not.toContain(lightModeColor);
    }
  });
});
