import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readProductFile = (fileName: string) => readFileSync(
  resolve(process.cwd(), `resources/js/Pages/UserSide/Products/${fileName}`),
  'utf8',
);

describe('ProductShow desktop layout contract', () => {
  it('contains the BOY London-inspired composition only at xl widths', () => {
    const source = readProductFile('ProductShow.tsx');

    expect(source).toContain('data-testid="desktop-product-hero"');
    expect(source).toContain('xl:max-w-[1440px]');
    expect(source).toContain('xl:grid-cols-[minmax(0,1.55fr)_minmax(390px,0.85fr)]');
    expect(source).toContain('xl:sticky xl:top-28 xl:self-start');
    expect(source).toContain('xl:order-2');
    expect(source).toContain('xl:order-1');
    expect(source).toContain('data-testid="desktop-product-disclosures"');
    expect(source).toContain('className="hidden xl:block"');
    expect(source).toContain('aria-expanded={openDesktopDisclosure === disclosure.id}');
    expect(source).toContain("label: 'Product Details'");
    expect(source).toContain("label: 'Returns Policy'");
    expect(source).toContain("label: 'Shipping'");
  });

  it('keeps the existing smaller-screen purchase controls and size flow', () => {
    const source = readProductFile('ProductShow.tsx');

    expect(source).toContain('xl:hidden');
    expect(source).toContain('Size Guide');
    expect(source).toContain('aria-pressed={isSameSize(selectedSize, option.value)}');
    expect(source).toContain('label="Add to Cart"');
    expect(source).toContain('label="Buy Now"');
    expect(source).toContain('buyNow={true}');
    expect(source).toContain('setShowSizeChart(true)');
  });

  it('places desktop-only related and recent rails after customer reviews', () => {
    const source = readProductFile('ProductShow.tsx');
    const railSource = readProductFile('ProductRail.tsx');
    const reviewsIndex = source.indexOf('{/* Reviews and Ratings Section */}');
    const relatedIndex = source.indexOf('<ProductRail title="You May Also Like"');
    const recentIndex = source.indexOf('<ProductRail title="Recently Viewed Items"');

    expect(reviewsIndex).toBeGreaterThan(-1);
    expect(relatedIndex).toBeGreaterThan(reviewsIndex);
    expect(recentIndex).toBeGreaterThan(relatedIndex);
    expect(source).toContain('data-testid="desktop-product-rails"');
    expect(source).toContain('className="mt-20 hidden space-y-20 px-4 sm:px-6 xl:block xl:px-0"');
    expect(railSource).toContain('className="hidden xl:block"');
    expect(railSource).toContain('xl:grid-cols-4');
    expect(railSource).toContain('bg-[#f5f5f5]');
    expect(railSource).not.toContain('shadow');
  });
});
