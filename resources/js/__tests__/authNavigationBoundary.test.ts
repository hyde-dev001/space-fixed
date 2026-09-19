import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appSource = readFileSync(resolve('resources/js/app.jsx'), 'utf8');
const logoutSources = [
  'resources/js/components/header/ShopOwnerDropdown.tsx',
  'resources/js/components/header/UserDropdown.tsx',
  'resources/js/components/header/SuperAdminDropdown.tsx',
].map((file) => readFileSync(resolve(file), 'utf8'));
const badgeCountsSource = readFileSync(resolve('resources/js/hooks/useBadgeCounts.ts'), 'utf8');

describe('auth navigation boundaries', () => {
  it('keeps sidebar and cart contexts mounted while Inertia changes pages', () => {
    expect(appSource).toContain('const ApplicationProviders');
    expect(appSource).toContain('<SidebarProvider>');
    expect(appSource).toContain('<CartProvider syncEnabled={isUserSidePage && !isUserAuthPage}>');
    expect(appSource).not.toContain('{isUserSidePage ? (');
  });

  it('lets the server redirect finish logout without a second visit', () => {
    for (const source of logoutSources) {
      expect(source).not.toContain('setTimeout(() => { router.visit(');
      expect(source).not.toContain('onError: () => {\n          router.visit(');
    }
  });

  it('does not start a second Inertia visit from background auth polling', () => {
    expect(badgeCountsSource).not.toContain("router.visit('/user/login')");
  });
});
