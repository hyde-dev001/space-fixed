import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');
const appSidebarErp = readFileSync(resolve('resources/js/layout/AppSidebar_ERP.tsx'), 'utf8');
const appSidebarShopOwner = readFileSync(resolve('resources/js/layout/AppSidebar_shopOwner.tsx'), 'utf8');
const canonicalOwnerSidebar = readFileSync(resolve('resources/js/layout/CanonicalOwnerSidebar.tsx'), 'utf8');
const customSelect = readFileSync(resolve('resources/js/components/form/Select.tsx'), 'utf8');
const multiSelect = readFileSync(resolve('resources/js/components/form/MultiSelect.tsx'), 'utf8');
const erpCommandSearch = readFileSync(resolve('resources/js/components/header/ErpCommandSearch.tsx'), 'utf8');
const globalSearch = readFileSync(resolve('resources/js/Pages/ERP/Common/GlobalSearch.tsx'), 'utf8');
const timePicker = readFileSync(resolve('resources/js/Pages/ERP/Logistics/components/TimePickerModal.tsx'), 'utf8');
const ownerModuleTabs = readFileSync(resolve('resources/js/components/owner-shell/OwnerModuleTabs.tsx'), 'utf8');
const ownerApprovalFilters = readFileSync(resolve('resources/js/components/owner-action-center/OwnerApprovalFilters.tsx'), 'utf8');
const customOptionStyles = appCss.slice(
  appCss.indexOf('/* Apply the same selection language to accessible custom dropdowns across'),
  appCss.indexOf('@utility no-scrollbar'),
);

describe('shared monochrome Light and Dark Mode theme', () => {
  it('defines scoped ERP tokens for both themes', () => {
    expect(appCss).toContain('#app .erp-theme {');
    expect(appCss).toContain('--erp-ink: #111111;');
    expect(appCss).toContain('--erp-surface-muted: #f3f4f6;');
    expect(appCss).toContain('html.dark #app .erp-theme {');
    expect(appCss).toContain('--erp-ink: #ffffff;');
    expect(appCss).toContain('--erp-surface: #111111;');
  });

  it('keeps ordinary ERP controls neutral and semantic exceptions explicit', () => {
    expect(appCss).toContain(':not([data-erp-icon-action], [data-erp-icon-action] *');
    expect(appCss).toContain('[data-erp-icon-action]');
    expect(appCss).toContain('[data-critical]');
    expect(appCss).toContain('#app .erp-theme');

    for (const family of ['rose', 'pink', 'fuchsia', 'error', 'success', 'warning', 'meta', 'slate']) {
      expect(appCss).toContain(`[class^='bg-${family}-']`);
      expect(appCss).toContain(`[class*='dark:bg-${family}-']`);
    }

    for (const token of ['bg-primary', 'bg-danger', 'bg-gray-2', 'border-stroke', 'dark:bg-boxdark', 'dark:border-strokedark']) {
      expect(appCss).toContain(`[class~='${token}']`);
    }

    expect(appCss).toContain("[class*='bg-[radial-gradient']");
  });

  it('normalizes ERP charts and SweetAlert portals in dark mode without global scope', () => {
    expect(appCss).toContain('html.dark #app .erp-theme .apexcharts-series[rel="1"]');
    expect(appCss).toContain('html.dark #app .erp-theme .apexcharts-series[rel="1"] .apexcharts-radialbar-area');
    expect(appCss).toContain('html:has(#app .erp-theme) .erp-swal2-popup');
    expect(appCss).not.toContain('html:has(#app) .erp-swal2-popup');
  });

  it('keeps ERP progress and non-critical SweetAlert warning chrome neutral', () => {
    expect(appCss).toContain('html:has(#app .erp-theme) #nprogress .bar');
    expect(appCss).toContain('html.dark:has(#app .erp-theme) #nprogress .bar');
    expect(appCss).toContain('html:has(#app .erp-theme) .swal2-popup:not(.swal2-toast) :is(.swal2-warning, .swal2-info, .swal2-question)');
    expect(appCss).not.toContain('html:has(#app) #nprogress .bar');
  });

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

  it('keeps custom filter options readable with black selected states and neutral hover', () => {
    expect(customOptionStyles).toContain("html:not(.dark) #app :is([role='combobox'], [aria-haspopup='listbox']):is(:focus, :focus-within)");
    expect(customOptionStyles).toContain("html:not(.dark) #app :is([role='combobox'], [aria-haspopup='listbox']):hover");
    expect(customOptionStyles).toContain("html:not(.dark) #app [role='listbox'] [role='option'][aria-selected='true'] {");
    expect(customOptionStyles).toContain("html:not(.dark) #app [role='listbox'] [role='option'][data-highlighted='true']");
    expect(customOptionStyles).toContain("background-color: #111111 !important;\n  color: #ffffff !important;");
    expect(customOptionStyles).toContain("html.dark #app [role='listbox'] [role='option'][aria-selected='true'] {");
    expect(customOptionStyles).toContain("background-color: #111111 !important;\n  color: #ffffff !important;");
  });

  it('normalizes legacy blue interaction utilities only on ERP controls', () => {
    expect(appCss).toContain("#app .erp-theme :is(button, a, [role='button'], [role='option']");
    expect(appCss).toContain("[role='tab'], [role='menuitem']");
    expect(appCss).toContain("[class*='hover:bg-blue-']");
    expect(appCss).toContain("[class*='hover:bg-indigo-']");
    expect(appCss).toContain("[class*='hover:bg-purple-']");
    expect(appCss).toContain("background-color: #e5e7eb !important;");
    expect(appCss).toContain("[data-state='active']");
    expect(appCss).toContain(":not(option):not(:disabled):hover");
  });

  it('leaves native select option popovers to the browser while keeping field focus neutral', () => {
    expect(appCss).toContain('html:not(.dark) #app .erp-theme select {');
    expect(appCss).toContain('color-scheme: light;');
    expect(appCss).toContain('html.dark #app .erp-theme select {');
    expect(appCss).toContain('color-scheme: dark;');
    expect(appCss).not.toContain("html:not(.dark) #app .erp-theme select option");
    expect(appCss).not.toContain("html.dark #app .erp-theme select option");
  });

  it('uses black selected treatments and neutral hover treatments in shared controls', () => {
    expect(customSelect).toContain('selectedValue === "" ? "bg-gray-950 text-white dark:bg-gray-950 dark:text-white"');
    expect(customSelect).toContain('selectedValue === option.value ? "bg-gray-950 text-white dark:bg-gray-950 dark:text-white"');
    expect(multiSelect).toContain('bg-gray-950 text-white hover:bg-black dark:bg-gray-950 dark:text-white');
    expect(erpCommandSearch).toContain('bg-gray-950 text-white dark:bg-gray-950');
    expect(globalSearch).toContain("bg-gray-950 text-white hover:bg-black dark:bg-gray-950");
    expect(timePicker).toContain("border-gray-950 bg-gray-950 font-bold text-white");
    expect(ownerModuleTabs).toContain('dark:border-[#111111] dark:bg-[#111111] dark:text-white');
    expect(ownerApprovalFilters).toContain('bg-gray-950');
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
