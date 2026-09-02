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

  it('uses the shared customer scroll-reveal behavior', () => {
    expect(landingSource).toContain("import { useScrollReveal } from '../Shared/useScrollReveal';");
    expect(landingSource).toContain('useScrollReveal(revealRootRef);');
    expect(landingSource).not.toContain("root.querySelectorAll<HTMLElement>('[data-scroll-reveal]')");
    expect(landingSource).not.toContain('.scroll-reveal {');
  });

  it('uses a measured fixed-footer curtain reveal with responsive navigation', () => {
    [
      'id="landing-footer"',
      'landing-footer',
      'landing-curtain',
      'footer-curtain-spacer',
      'footer-wordmark',
      '--landing-footer-height',
      'ResizeObserver',
      'IntersectionObserver',
      'SOLESPACE',
      'Explore',
      'Support',
      'Community',
      'Shipping to',
      'Language',
      '<details',
      '<summary',
    ].forEach((marker) => {
      expect(landingSource).toContain(marker);
    });

    expect(landingSource).toContain('fixed inset-x-0 bottom-0 z-0');
    expect(landingSource).toContain('overflow-y-auto overscroll-auto');
    expect(landingSource).not.toContain('overscroll-contain');
    expect(landingSource).toContain('aria-hidden={!footerIsInteractive}');
    expect(landingSource).toContain("toggleAttribute('inert', !footerIsInteractive)");
    expect(landingSource).toContain('prefers-reduced-motion');
    expect(landingSource).not.toContain('footer-reveal-stage');
    expect(landingSource).not.toContain('-mt-32');
    expect(landingSource).not.toContain('sticky bottom-0');
    expect(landingSource).not.toContain("addEventListener('scroll'");
    expect(landingSource).not.toContain('--footer-reveal-progress');
  });

  it('uses a landscape snap carousel for categories below the desktop breakpoint', () => {
    expect(landingSource).toContain(
      'landing-category-carousel flex snap-x snap-mandatory gap-4 overflow-x-auto pb-3',
    );
    expect(landingSource).toContain('min-w-[84%] snap-start sm:min-w-[58%]');
    expect(landingSource).toContain('min-w-[84%] snap-start aspect-[4/3] overflow-hidden');
    expect(landingSource).toContain('sm:min-w-[58%] sm:aspect-[16/10]');
    expect(landingSource).toContain('lg:grid lg:grid-cols-3 lg:gap-7 lg:overflow-visible lg:pb-0');
    expect(landingSource).toContain('lg:min-w-0 lg:aspect-auto lg:min-h-[38rem]');
  });

  it('removes the superseded statistics and final CTA copy', () => {
    expect(landingSource).not.toContain('Satisfaction');
    expect(landingSource).not.toContain('READY TO STEP INTO STYLE?');
  });
});
