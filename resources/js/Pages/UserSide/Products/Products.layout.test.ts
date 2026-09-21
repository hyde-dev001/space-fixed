import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const productsSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Products/Products.tsx'),
  'utf8',
);

describe('Products page layout', () => {
  it('keeps the default catalog header clean while preserving search result copy', () => {
    expect(productsSource).not.toContain(": 'ALL SHOES'");
    expect(productsSource).not.toContain('Discover our curated selection of shoes. Browse by style, price, and location. Click any product to view details and select your size.');
    expect(productsSource).toContain('Search Results for');
    expect(productsSource).toContain('Showing results matching');
  });

  it('opts the catalog and dynamic product cards into the shared scroll reveal', () => {
    expect(productsSource).toContain("import { useScrollReveal } from '../Shared/useScrollReveal';");
    expect(productsSource).toContain('const revealRootRef = useRef<HTMLDivElement | null>(null);');
    expect(productsSource).toContain('useScrollReveal(revealRootRef);');
    expect(productsSource).toContain('ref={revealRootRef}');
    expect(productsSource.match(/data-scroll-reveal/g)?.length ?? 0).toBeGreaterThanOrEqual(2);
    expect(productsSource).toContain('data-scroll-reveal className="scroll-reveal h-full"');
    expect(productsSource).toContain('className="group flex h-full');
  });

  it('adds a sibling Quick View trigger and keeps the existing catalog link path', () => {
    expect(productsSource).toContain("import ProductQuickView from '../../../components/products/ProductQuickView';");
    expect(productsSource).toContain('const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);');
    expect(productsSource).toContain('quickViewTriggerRef.current = event.currentTarget;');
    expect(productsSource).toContain('aria-label={`Quick view ${p.name}`}');
    expect(productsSource).toContain('<ProductQuickView');
    expect(productsSource).toContain('className="group flex h-full');
  });

  it('keeps the sort menu above animated catalog cards', () => {
    expect(productsSource).toContain(
      'data-scroll-reveal className="scroll-reveal relative z-30 mb-8 w-full md:max-w-none"',
    );
    expect(productsSource).toContain(
      'absolute right-0 left-auto z-40 mt-3 w-[min(92vw,14.5rem)]',
    );
  });

  it('places the multi-select color filter after the newest-date sort option', () => {
    const newestDateIndex = productsSource.indexOf('Date, new to old');
    const priceIndex = productsSource.indexOf('data-testid="price-filter-menu-item"');
    const colorIndex = productsSource.indexOf('data-testid="color-filter-menu-item"');

    expect(newestDateIndex).toBeGreaterThanOrEqual(0);
    expect(priceIndex).toBeGreaterThan(newestDateIndex);
    expect(colorIndex).toBeGreaterThan(newestDateIndex);
    expect(colorIndex).toBeGreaterThan(priceIndex);
    expect(productsSource).toContain('filter[color]');
    expect(productsSource).toContain('available_colors');
    expect(productsSource).toContain('selectedColors');
    expect(productsSource).toContain('pendingColors');
  });

  it('uses the named-color catalog through a search-only color picker', () => {
    expect(productsSource).toContain("import { NAMED_COLORS } from '@/data/namedColors';");
    expect(productsSource).toContain('Search named colors');
    expect(productsSource).toContain('role="listbox"');
    expect(productsSource).not.toContain('Search custom colors');
    expect(productsSource).not.toContain('>Quick Select</h3>');
    expect(productsSource).not.toContain('>Custom colors</h3>');
    expect(productsSource).not.toContain('>Selected colors</h3>');
  });

  it('adds an apply-driven price range filter with URL-backed API parameters', () => {
    expect(productsSource).toContain('data-testid="price-filter-menu-item"');
    expect(productsSource).toContain('Minimum price');
    expect(productsSource).toContain('Maximum price');
    expect(productsSource).toContain('filter[price_min]');
    expect(productsSource).toContain('filter[price_max]');
    expect(productsSource).toContain("params.set('min_price'");
    expect(productsSource).toContain("params.set('max_price'");
    expect(productsSource).toContain("event.key === 'Escape'");
    expect(productsSource).toContain('The minimum price must be less than or equal to the maximum price.');
  });
});
