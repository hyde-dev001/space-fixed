import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const navigationSource = readFileSync(
  resolve('resources/js/Pages/UserSide/Shared/Navigation.tsx'),
  'utf8',
);

describe('user-side navigation shell', () => {
  it('uses the left drawer and right cart motion as the shared default', () => {
    expect(navigationSource).toContain('landingSidebar = true');
    expect(navigationSource).toContain('transition-transform duration-300 ease-out');
    expect(navigationSource).toContain("cartDrawerOpen ? 'translate-x-0' : 'translate-x-full pointer-events-none'");
    expect(navigationSource).toContain("cartDrawerOpen ? 'opacity-100' : 'pointer-events-none opacity-0'");
    expect(navigationSource).toContain('motion-reduce:transition-none');
  });

  it('fetches empty-query products when the search surface is focused', () => {
    expect(navigationSource).toContain('}, [searchQuery, isSearchFocused]);');
    expect(navigationSource).toContain('/api/search/suggestions?query=${encodeURIComponent(query)}');
    expect(navigationSource).toContain('product.price');
  });

  it('does not keep the Home or bottom Search links in the drawer', () => {
    const sidebarStart = navigationSource.indexOf('aria-label="Site menu"');
    const sidebarEnd = navigationSource.indexOf('</aside>', sidebarStart);
    const sidebarSource = navigationSource.slice(sidebarStart, sidebarEnd);

    expect(sidebarSource).not.toContain('>Search</Link>');
    expect(navigationSource).not.toContain(
      'className={mobileNavLinkClasses(isMobileHomeActive)}>Home</Link>',
    );
  });
});
