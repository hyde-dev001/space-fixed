import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const readProjectFile = (path: string) => readFileSync(join(process.cwd(), path), 'utf8');

describe('desktop user-side presentation', () => {
  it('keeps the new visual system desktop-scoped', () => {
    const css = readProjectFile('resources/css/app.css');

    expect(css).toContain('@media (min-width: 1024px)');
    expect(css).toContain('--userside-ink');
    expect(css).toContain('--userside-canvas');
    expect(css).toContain('--userside-surface');
    expect(css).toContain('@media (min-width: 1024px) and (prefers-reduced-motion: reduce)');
  });

  it('preserves navigation behavior while adding the desktop scope', () => {
    const navigation = readProjectFile('resources/js/Pages/UserSide/Shared/Navigation.tsx');

    for (const contract of [
      'useCart',
      'useBadgeCounts',
      'handleSearch',
      'handleLogout',
      'router.visit',
      'router.post',
      'effectiveCartCount',
    ]) {
      expect(navigation).toContain(contract);
    }

    expect(navigation).toContain('userside-desktop-scope');
    expect(navigation).toContain('productPageMode');
    expect(navigation).toContain("productPageMode || catalogMode ? 'top-0'");

    const productShow = readProjectFile('resources/js/Pages/UserSide/Products/ProductShow.tsx');
    expect(productShow).toContain('mobileMenuTriggerIcon="hamburger" productPageMode');

    const products = readProjectFile('resources/js/Pages/UserSide/Products/Products.tsx');
    expect(products).toContain('mobileMenuTriggerIcon="hamburger" landingSidebar catalogMode');
    expect(products).toContain('<div className="hidden" aria-label="Latest offers">');
    expect(navigation).toContain("productPageMode || catalogMode ? 'top-0'");
  });
});
