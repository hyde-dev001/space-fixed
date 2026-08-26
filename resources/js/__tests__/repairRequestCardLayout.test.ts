import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const dssInsights = readFileSync(
  resolve('resources/js/Pages/ShopOwner/DssInsights.tsx'),
  'utf8',
);

const utilizationStart = dssInsights.indexOf('function UtilizationOverviewCard');
const mainPageStart = dssInsights.indexOf('const DssInsights');
const utilizationSource = dssInsights.slice(utilizationStart, mainPageStart);

const workloadGridStart = dssInsights.indexOf(
  '<div className="grid grid-cols-1 lg:grid-cols-3',
);
const dailyChartMarker = dssInsights.indexOf(
  '{/* Daily active chart */}',
  workloadGridStart,
);
const workloadGridSource = dssInsights.slice(workloadGridStart, dailyChartMarker);
const pesoIconStart = dssInsights.indexOf('const PesoIcon');
const boxMetricIconStart = dssInsights.indexOf('const BoxMetricIcon', pesoIconStart);
const pesoIconSource = dssInsights.slice(pesoIconStart, boxMetricIconStart);

describe('Repair Request card layout', () => {
  it('stretches the outer card while keeping the meter panel natural-height', () => {
    expect(utilizationSource).toContain('overflow-hidden h-full flex flex-col');
    expect(utilizationSource).not.toContain('sm:pt-6 flex-1');
    expect(utilizationSource).toContain('<div className="flex flex-1 flex-col">');
  });

  it('lets the workload cards share the row height without adding call-site height overrides', () => {
    expect(workloadGridSource).toContain(
      'grid grid-cols-1 lg:grid-cols-3 items-stretch gap-5',
    );
    expect(workloadGridSource).not.toContain('items-start');
    expect(workloadGridSource).not.toContain('className="h-full"');
  });

  it('keeps the meter below the header with intentional spacing', () => {
    expect(utilizationSource).toContain('<div className="relative mt-4">');
  });

  it('renders the peso icon with Philippine peso strokes instead of a plain P', () => {
    expect(pesoIconSource).toContain('M4 10h10');
    expect(pesoIconSource).toContain('M4 13h8');
  });
});
