import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const shopProfileSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Profile/ShopProfile.tsx'),
  'utf8',
);

describe('ShopProfile desktop product rails', () => {
  it('renders the approved category-led desktop browsing structure', () => {
    expect(shopProfileSource).toContain('data-testid="shop-profile-desktop-product-rails"');
    expect(shopProfileSource).toContain('title="Recommended For You"');
    expect(shopProfileSource).toContain('retailCategoriesForSections.map((category)');
    expect(shopProfileSource).toContain('onSeeMore={() => setSelectedCategory(category)}');
    expect(shopProfileSource).toContain('title={category}');
  });

  it('keeps each desktop rail horizontally scrollable and matches catalog card sizing', () => {
    expect(shopProfileSource).toContain('snap-x snap-mandatory gap-4 overflow-x-auto');
    expect(shopProfileSource).toContain('xl:aspect-square');
    expect(shopProfileSource).toContain('xl:min-h-48.5 xl:p-3.5');
    expect(shopProfileSource).toContain('data-testid="shop-profile-product-card"');
    expect(shopProfileSource).not.toContain('grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8');
  });

  it('preserves existing product navigation and non-retail content paths', () => {
    expect(shopProfileSource).toContain("href={'/products/' + product.slug}");
    expect(shopProfileSource).toContain('filteredRepairPackages');
    expect(shopProfileSource).toContain('filteredRepairServices');
    expect(shopProfileSource).toContain('virtual-showroom');
  });
});
