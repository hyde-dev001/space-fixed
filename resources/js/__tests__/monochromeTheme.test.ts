import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');
const appSidebarErp = readFileSync(resolve('resources/js/layout/AppSidebar_ERP.tsx'), 'utf8');
const appSidebarShopOwner = readFileSync(resolve('resources/js/layout/AppSidebar_shopOwner.tsx'), 'utf8');
const canonicalOwnerSidebar = readFileSync(resolve('resources/js/layout/CanonicalOwnerSidebar.tsx'), 'utf8');
const customSelect = readFileSync(resolve('resources/js/components/form/Select.tsx'), 'utf8');
const multiSelect = readFileSync(resolve('resources/js/components/form/MultiSelect.tsx'), 'utf8');

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

  it('keeps Light Mode radial gauge progress as a stroke instead of a filled wedge', () => {
    expect(appCss).toContain('html:not(.dark) #app .erp-theme .apexcharts-series[rel="1"] .apexcharts-radialbar-area');
    expect(appCss).toContain('filter: grayscale(1) brightness(0);');
  });

  it('does not match dark-prefixed text utilities as Light Mode link colors', () => {
    expect(appCss).not.toContain(":is(a, [role='tab'])[class*='text-blue-']");
    expect(appCss).toContain("[class~='text-blue-600']");
  });

  it('keeps Light Mode native and custom filter options readable', () => {
    expect(appCss).toContain("html:not(.dark) #app .erp-theme select option");
    expect(appCss).toContain("html:not(.dark) #app .erp-theme select option:checked");
    expect(appCss).toContain("html:not(.dark) #app .erp-theme select option:hover");
    expect(appCss).toContain("html:not(.dark) #app .erp-theme [role='option'][aria-selected='true']");
    expect(appCss).toContain("html:not(.dark) #app .erp-theme [role='option'][data-highlighted='true']");
  });

  it('keeps native select popovers theme-aware with neutral selected and hover states', () => {
    expect(appCss).toContain('html:not(.dark) #app .erp-theme select {');
    expect(appCss).toContain('color-scheme: light;');
    expect(appCss).toContain('html.dark #app .erp-theme select {');
    expect(appCss).toContain('color-scheme: dark;');
    expect(appCss).toContain(`html:not(.dark) #app .erp-theme select option:checked,
html:not(.dark) #app .erp-theme select option:checked:hover {
  background-color: #f3f4f6 !important;
  color: #111111 !important;
}`);
    expect(appCss).toContain(`html.dark #app .erp-theme select option:checked,
html.dark #app .erp-theme select option:checked:hover {
  background-color: #334155 !important;
  color: #f8fafc !important;
}`);
  });

  it('keeps custom select choices in the same gray hover language', () => {
    expect(customSelect).toContain('selectedValue === "" ? "bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-white"');
    expect(customSelect).not.toContain('selectedValue === "" ? "bg-gray-900 text-white"');
    expect(multiSelect).toContain('isSelected ? "bg-gray-100 dark:bg-gray-700"');
    expect(multiSelect).not.toContain('bg-primary/10');
    expect(multiSelect).not.toContain('hover:bg-primary/5');
  });

  it('keeps every account sidebar wordmark monochrome', () => {
    for (const source of [appSidebarErp, appSidebarShopOwner]) {
      expect(source).toContain('text-gray-900 dark:text-gray-100');
      expect(source).not.toContain('bg-gradient-to-r from-blue-600 to-purple-600');
    }

    expect(canonicalOwnerSidebar).toContain('className="flex items-center gap-2 rounded-lg text-[#111111] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] dark:text-gray-100 dark:focus-visible:ring-gray-300"');
  });
});
