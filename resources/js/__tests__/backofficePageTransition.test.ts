import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');
const layoutSources = [
  'resources/js/layout/AppLayout_ERP.tsx',
  'resources/js/layout/AppLayout_shopOwner.tsx',
  'resources/js/layout/CanonicalOwnerLayout.tsx',
].map((path) => readFileSync(resolve(path), 'utf8'));
const superAdminLayout = readFileSync(resolve('resources/js/layout/AppLayout.tsx'), 'utf8');

describe('back-office page transition', () => {
  it('defines the approved subtle fade-and-slide-up motion', () => {
    expect(appCss).toContain('.backoffice-page-enter {');
    expect(appCss).toContain('animation: backoffice-page-enter 350ms');
    expect(appCss).toContain('@keyframes backoffice-page-enter');
    expect(appCss).toContain('transform: translate3d(0, 12px, 0);');
    expect(appCss).toContain('transform: translate3d(0, 0, 0);');
    expect(appCss).toContain('opacity: 0;');
    expect(appCss).toContain('opacity: 1;');
    expect(appCss).toContain('@media (prefers-reduced-motion: reduce)');
    expect(appCss).toMatch(/\.backoffice-page-enter\s*\{\s*animation:\s*none;/);
  });

  it('keys page content in every back-office layout', () => {
    for (const source of layoutSources) {
      expect(source).toContain('key={page.component}');
      expect(source).toContain('className="backoffice-page-enter"');
    }
  });

  it('limits the legacy shared layout transition to Superadmin pages', () => {
    expect(superAdminLayout).toContain('page.component.startsWith("superAdmin/")');
    expect(superAdminLayout).toContain('key={page.component}');
    expect(superAdminLayout).toContain('backoffice-page-enter');
  });

  it('does not alter the customer transition contract', () => {
    expect(appCss).toContain('.customer-page-transition {');
    expect(appCss).toContain('.scroll-reveal {');
  });
});
