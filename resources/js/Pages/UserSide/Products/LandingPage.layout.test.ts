import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const landingSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Products/LandingPage.tsx'),
  'utf8',
);

describe('SoleSpace landing page redesign', () => {
  it('uses the SoleSpace storefront section sequence', () => {
    [
      'New releases',
      'Shop by category',
      'KEEP EVERY STEP GOING',
      'Curated footwear',
      'Expert repairs',
      'One space for every step',
      'STEP INTO SOLESPACE',
    ].forEach((marker) => {
      expect(landingSource).toContain(marker);
    });

    expect(landingSource).toContain('id="landing-new-releases"');
    expect(landingSource).toContain('id="landing-categories"');
    expect(landingSource).toContain('id="landing-story"');
    expect(landingSource).toContain('id="landing-benefits"');
    expect(landingSource).toContain('id="landing-community"');
  });

  it('uses local SoleSpace assets and preserves landing integration hooks', () => {
    ['/images/shop/p1.jpg', '/images/shop/p2.jpg', '/images/shop/p3.jpg', '/images/shop/p4.jpg'].forEach((asset) => {
      expect(landingSource).toContain(asset);
    });

    expect(landingSource).toContain('<Navigation mobileMenuTriggerIcon="hamburger" landingSidebar />');
    expect(landingSource).toContain("route('products.show', product.slug)");
    expect(landingSource).toContain('route("repair")');
    expect(landingSource).toContain('route("services")');
    expect(landingSource).toContain('data-scroll-reveal');
    expect(landingSource).toContain('prefers-reduced-motion');
    expect(landingSource).toContain('html.solespace-first-load:not(.solespace-app-ready) .landing-hero-motion');
  });

  it('removes the superseded statistics and final CTA copy', () => {
    expect(landingSource).not.toContain('Satisfaction');
    expect(landingSource).not.toContain('READY TO STEP INTO STYLE?');
  });
});
