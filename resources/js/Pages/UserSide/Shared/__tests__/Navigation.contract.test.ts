import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const navigationSource = readFileSync(
  resolve('resources/js/Pages/UserSide/Shared/Navigation.tsx'),
  'utf8',
);
const appCssSource = readFileSync(resolve('resources/css/app.css'), 'utf8');
const landingPageSource = readFileSync(
  resolve('resources/js/Pages/UserSide/Products/LandingPage.tsx'),
  'utf8',
);
const standaloneAccountMenuSources = [
  'resources/js/Pages/UserSide/Products/Products.tsx',
  'resources/js/Pages/UserSide/Products/ProductShow.tsx',
  'resources/js/Pages/UserSide/Repairs/Repair.tsx',
].map((path) => ({ path, source: readFileSync(resolve(path), 'utf8') }));
const supportingLabelSources = {
  shopProfile: readFileSync(resolve('resources/js/Pages/UserSide/Profile/ShopProfile.tsx'), 'utf8'),
  customerProfile: readFileSync(resolve('resources/js/Pages/UserSide/Profile/customerProfile.tsx'), 'utf8'),
  repairs: readFileSync(resolve('resources/js/Pages/UserSide/Repairs/myRepairs.tsx'), 'utf8'),
  paymentSuccess: readFileSync(resolve('resources/js/Pages/UserSide/Orders/PaymentSuccess.tsx'), 'utf8'),
  productDetails: readFileSync(resolve('resources/js/Pages/UserSide/Products/ProductShow.tsx'), 'utf8'),
};

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

  it('renders shop profiles and showroom actions in the landing search dialog', () => {
    expect(navigationSource).toContain('aria-label="Search products and shops"');
    expect(navigationSource).toContain('data-testid="landing-search-shops"');
    expect(navigationSource).toContain('data-testid="landing-search-shop-card"');
    expect(navigationSource).toContain('shopSuggestionLabel');
    expect(navigationSource).toContain('shop.virtual_showroom_url');
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

  it('keeps Download as the last utility link after Cart', () => {
    const sidebarStart = navigationSource.indexOf('aria-label="Site menu"');
    const sidebarSource = navigationSource.slice(sidebarStart, navigationSource.indexOf('</aside>', sidebarStart));

    expect(sidebarSource).toContain("href={route('download')}");
    expect(sidebarSource.indexOf('>Cart')).toBeLessThan(sidebarSource.indexOf('>Download'));
  });

  it('renders the shared moving offers ticker with reduced-motion support', () => {
    expect(navigationSource).toContain('aria-label="Latest offers"');
    expect(navigationSource).toContain('SoleSpace summer edit');
    expect(navigationSource).toContain('Premium footwear');
    expect(navigationSource).toContain('Expert repairs');
    expect(navigationSource).toContain('Shop the latest drops');
    expect(navigationSource).toContain('landing-marquee');
    expect(navigationSource).toContain('motion-reduce:animate-none');
  });

  it('keeps the solid offers marquee at page top and moves the fixed header up after scrolling', () => {
    expect(navigationSource).toContain('const [isPromoTickerAtTop, setIsPromoTickerAtTop] = useState(true);');
    expect(navigationSource).toContain('window.scrollY < 40');
    expect(navigationSource).toContain('relative z-[40] h-10');
    expect(navigationSource).toContain('bg-[#111111]');
    expect(navigationSource).not.toContain('bg-[#111111]/70');
    expect(navigationSource).not.toContain('fixed inset-x-0 top-0 z-[40]');
    expect(navigationSource).toContain("isPromoTickerAtTop ? 'top-10' : 'top-0'");
    expect(appCssSource).toContain('.landing-marquee:hover');
    expect(appCssSource).toContain('.landing-marquee:focus-within');
    expect(appCssSource).toContain('animation-play-state: paused');
  });

  it('does not reserve the former fixed-ticker space above the landing page', () => {
    expect(landingPageSource).not.toContain('bg-white pt-10 font-outfit antialiased');
  });

  it('removes the SoleSpace logo shadow and hides the search results scrollbar UI', () => {
    expect(navigationSource).toContain("isTransparentNav ? 'text-white' : 'text-gray-900'");
    expect(navigationSource).not.toContain("isTransparentNav ? 'text-white drop-shadow-[0_1px_3px_rgba(0,0,0,0.7)]' : 'text-gray-900'");
    expect(navigationSource).toContain('max-h-[55vh] overflow-y-auto no-scrollbar px-5 py-5 sm:px-7');
  });

  it('keeps glass drawers above the ticker stacking context', () => {
    expect(navigationSource).toContain('fixed inset-0 z-[100]');
    expect(navigationSource).toContain('fixed right-0 top-0 z-[110]');
    expect(navigationSource).toContain('fixed left-0 top-0 z-[110]');
    expect(navigationSource).toContain('bg-white/60');
    expect(navigationSource).toContain('bg-black/35');
    expect(navigationSource).toContain('backdrop-blur-2xl');
    expect(navigationSource).not.toContain('fixed right-0 top-full z-50 mt-1 w-52');
  });

  it('opens authenticated Account as an inline animated accordion with a plus control', () => {
    const accountPanelStart = navigationSource.indexOf('id="customer-account-submenu"');
    const utilityStart = navigationSource.indexOf('<div className="border-t border-[#cacacb] px-6 py-6 sm:px-8 dark:border-slate-700">');
    const cartStart = navigationSource.indexOf('>Cart {effectiveCartCount > 0', utilityStart);
    const accountTriggerStart = navigationSource.indexOf('aria-controls="customer-account-submenu"');
    const accountTriggerEnd = navigationSource.indexOf('</button>', accountTriggerStart);
    const accountTriggerSource = navigationSource.slice(navigationSource.lastIndexOf('<button', accountTriggerStart), accountTriggerEnd);
    const accountPanelSource = navigationSource.slice(accountPanelStart, cartStart);

    expect(accountPanelStart).toBeGreaterThan(-1);
    expect(utilityStart).toBeGreaterThan(-1);
    expect(cartStart).toBeGreaterThan(-1);
    expect(accountPanelStart).toBeGreaterThan(utilityStart);
    expect(accountPanelStart).toBeLessThan(cartStart);
    expect(accountTriggerSource).toContain('text-left text-base font-medium');
    expect(accountTriggerSource).not.toContain('text-lg font-semibold');
    expect(navigationSource).toContain('const [accountDrawerOpen, setAccountDrawerOpen] = useState(false);');
    expect(navigationSource).toContain('aria-controls="customer-account-submenu"');
    expect(navigationSource).toContain('aria-expanded={accountDrawerOpen}');
    expect(navigationSource).toContain('aria-label={`${accountDrawerOpen ? \'Collapse\' : \'Expand\'} Account`}');
    expect(navigationSource).toContain('transition-[grid-template-rows,opacity,transform] duration-300 ease-out');
    expect(accountPanelSource).toContain('aria-hidden={!accountDrawerOpen}');
    expect(accountPanelSource).toContain("accountDrawerOpen ? 'grid-rows-[1fr] translate-y-0 opacity-100' : 'grid-rows-[0fr] -translate-y-1 opacity-0 pointer-events-none'");
    expect(accountPanelSource).toContain('<span>Edit Profile</span>');
    expect(accountPanelSource).toContain('<span>Join Our Team</span>');
    expect(accountPanelSource).toContain('<span>Log out</span>');
    expect(accountPanelSource).toContain("href={route('shop-owner-register')}");
    expect(navigationSource).not.toContain('id="customer-account-menu"');
    expect(navigationSource).not.toContain('absolute bottom-full left-0 right-0');

    const siteMenuStart = navigationSource.indexOf('aria-label="Site menu"');
    const siteMenuEnd = navigationSource.indexOf('</aside>', siteMenuStart);
    const siteMenuSource = navigationSource.slice(siteMenuStart, siteMenuEnd);
    expect(siteMenuSource).toContain('text-xl font-semibold');
    expect(siteMenuSource).not.toContain('font-black');
    const siteNavStart = navigationSource.indexOf('<nav className="flex-1 px-6 py-7 sm:px-8">', siteMenuStart);
    const siteNavEnd = navigationSource.indexOf('</nav>', siteNavStart);
    const siteNavSource = navigationSource.slice(siteNavStart, siteNavEnd);
    expect(siteNavSource).not.toContain('id="customer-account-submenu"');
  });

  it('keeps Account identifiable and makes Logout visibly interactive', () => {
    const accountTriggerStart = navigationSource.indexOf('aria-controls="customer-account-submenu"');
    const accountTriggerEnd = navigationSource.indexOf('</button>', accountTriggerStart);
    const accountTriggerSource = navigationSource.slice(
      navigationSource.lastIndexOf('<button', accountTriggerStart),
      accountTriggerEnd,
    );
    const accountPanelStart = navigationSource.indexOf('id="customer-account-submenu"');
    const logoutStart = navigationSource.indexOf('>Log out</span>', accountPanelStart);
    const logoutSource = navigationSource.slice(
      navigationSource.lastIndexOf('<button', logoutStart),
      navigationSource.indexOf('</button>', logoutStart),
    );

    expect(accountTriggerSource).toContain('h-5 w-5');
    expect(accountTriggerSource).toContain('aria-hidden="true"');
    expect(logoutSource).toContain('hover:bg-red-50');
    expect(logoutSource).toContain('focus-visible:ring-2');
    expect(logoutSource).toContain('focus-visible:ring-red-500');
  });

  it('animates sidebar plus submenus and exposes their expanded state', () => {
    expect(navigationSource).toContain('aria-controls={`sidebar-submenu-${item.dropdownKey}`}');
    expect(navigationSource).toContain('aria-expanded={isExpanded}');
    expect(navigationSource).toContain('transition-[grid-template-rows,opacity,transform] duration-300 ease-out');
    expect(navigationSource).toContain('grid-rows-[1fr] translate-y-0 opacity-100');
    expect(navigationSource).toContain('grid-rows-[0fr] -translate-y-1 opacity-0 pointer-events-none');
    expect(navigationSource).toContain('transition-transform duration-300 ease-out motion-reduce:transition-none');
  });

  it('removes the desktop People control and keeps header icon spacing consistent', () => {
    expect(navigationSource).toContain("landingSidebar ? 'top-3 gap-3 sm:top-5'");
    expect(navigationSource).toContain('absolute right-0 flex items-center gap-3');
    expect(navigationSource).not.toContain('aria-label="User account"');
    expect(navigationSource).not.toContain('{/* User Icon */}');
    expect(navigationSource).not.toContain('className={`${headerIconButtonClasses} -mr-2`}');
  });

  it('uses the shortened cart label in the shared cart surface', () => {
    const cartStart = navigationSource.indexOf('aria-label="Shopping cart"');
    const cartEnd = navigationSource.indexOf('aria-label="Site menu"', cartStart);
    const cartSource = navigationSource.slice(cartStart, cartEnd);

    expect(cartSource).toContain('<p className="text-lg font-semibold">Cart</p>');
    expect(cartSource).toContain('Your cart is empty.');
    expect(cartSource).not.toContain('>Bag<');
  });

  it('opens and refreshes the cart drawer after a successful product add without inline confirmation', () => {
    expect(navigationSource).toContain('addCartAddedListener');
    expect(navigationSource).toContain('removeCartAddedListener');
    expect(navigationSource).toContain('const [cartRefreshKey, setCartRefreshKey] = useState(0);');
    expect(navigationSource).toContain('if (!event.detail?.openDrawer) return;');
    expect(navigationSource).toContain('setCartDrawerOpen(true);');
    expect(navigationSource).toContain('setCartRefreshKey((key) => key + 1);');
    expect(navigationSource).not.toContain('cartAddedItem');
    expect(navigationSource).not.toContain('Added to cart');
    expect(navigationSource).not.toContain('bg-emerald-50');
    expect(navigationSource).toContain('}, [cartDrawerOpen, isAuthenticated, cartRefreshKey]);');
  });

  it('keeps customer drawers readable in dark mode', () => {
    const cartStart = navigationSource.indexOf('aria-label="Shopping cart"');
    const cartEnd = navigationSource.indexOf('aria-label="Site menu"', cartStart);
    const cartSource = navigationSource.slice(cartStart, cartEnd);

    expect(cartSource).toContain('userside-customer-drawer');
    expect(cartSource).toContain('dark:text-white');
    expect(cartSource).toContain('dark:text-slate-400');
    expect(appCssSource).toContain('.userside-customer-drawer');
    expect(appCssSource).toContain('[class~="text-[#777777]"]');
  });

  it('keeps standalone mobile account menus aligned with the shared account actions', () => {
    for (const { path, source } of standaloneAccountMenuSources) {
      expect(source, path).toContain('Edit Profile');
      expect(source, path).toContain('Join Our Team');
      expect(source, path).toContain('Log out');
      expect(source, path).toContain('shop-owner-register');
      expect(source, path).not.toContain('href="/my-orders"');
      expect(source, path).not.toContain('href="/my-repairs"');
    }
  });

  it('uses the shared customer footer and keeps the Repairs page title', () => {
    expect(supportingLabelSources.shopProfile).toContain('CustomerFooterReveal');
    expect(supportingLabelSources.shopProfile).not.toContain('<footer');
    expect(supportingLabelSources.customerProfile).toContain('>Repairs</h2>');
    expect(supportingLabelSources.repairs).toContain('<Head title="Repairs" />');
    expect(supportingLabelSources.paymentSuccess).toContain('View Orders');
    expect(supportingLabelSources.productDetails).toContain('label="Add to Cart"');
  });
});
