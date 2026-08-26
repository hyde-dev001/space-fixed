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

describe('Repair Request card layout', () => {
  it('keeps the utilization card at its natural height', () => {
    expect(utilizationSource).not.toContain('overflow-hidden h-full flex flex-col');
    expect(utilizationSource).not.toContain('sm:pt-6 flex-1');
  });

  it('top-aligns the workload cards without forcing the utilization card height', () => {
    expect(workloadGridSource).toContain(
      'grid grid-cols-1 lg:grid-cols-3 items-start gap-5',
    );
    expect(workloadGridSource).not.toContain('className="h-full"');
  });
});
