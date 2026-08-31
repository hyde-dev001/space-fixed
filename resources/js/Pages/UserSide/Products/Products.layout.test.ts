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
    expect(productsSource).toContain('className="group block h-full');
  });
});
