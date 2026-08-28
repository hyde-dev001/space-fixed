import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');

describe('shared monochrome Light Mode theme', () => {
  it('scopes shared metric card styling away from Dark Mode', () => {
    expect(appCss).toContain('html:not(.dark) #app .metrics-card');
  });

  it('uses black and neutral gray ApexCharts series only in Light Mode', () => {
    expect(appCss).toContain('html:not(.dark) #app .erp-theme .apexcharts-series[rel="1"]');
    expect(appCss).toContain('stroke: #111111 !important;');
    expect(appCss).toContain('fill: #111111 !important;');
    expect(appCss).toContain('.apexcharts-series[rel="2"]');
    expect(appCss).toContain('.apexcharts-series[rel="3"]');
    expect(appCss).toContain('.apexcharts-legend-marker[rel="2"]');
    expect(appCss).not.toContain('.dark .erp-theme .apexcharts-series');
  });

  it('does not match dark-prefixed text utilities as Light Mode link colors', () => {
    expect(appCss).not.toContain(":is(a, [role='tab'])[class*='text-blue-']");
    expect(appCss).toContain("[class~='text-blue-600']");
  });

  it('keeps Light Mode native and custom filter options readable', () => {
    expect(appCss).toContain("html:not(.dark) #app select option");
    expect(appCss).toContain("html:not(.dark) #app select option:checked");
    expect(appCss).toContain("html:not(.dark) #app select option:hover");
    expect(appCss).toContain("html:not(.dark) #app [role='option'][aria-selected='true']");
    expect(appCss).toContain("html:not(.dark) #app [role='option'][data-highlighted='true']");
  });
});
