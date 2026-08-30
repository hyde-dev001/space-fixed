import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/app.jsx'), 'utf8');

describe('application provider boundaries', () => {
  it('keeps both providers mounted while page contexts change', () => {
    expect(source).toContain('const [component, setComponent] = useState(initialComponent)');
    expect(source).toContain("setComponent(event.detail?.page?.component ?? '')");
    expect(source).toContain("const isUserAuthPage = component.startsWith('UserSide/Auth/')");
    expect(source).toContain('<ApplicationProviders initialComponent={component}>');

    const providersStart = source.indexOf('const ApplicationProviders');
    const providersEnd = source.indexOf('// Update CSRF token', providersStart);
    const providers = source.slice(providersStart, providersEnd);

    expect(providersStart).toBeGreaterThan(-1);
    expect(providersEnd).toBeGreaterThan(providersStart);
    expect(providers).toContain('<SidebarProvider>');
    expect(providers).toContain('<CartProvider syncEnabled={isUserSidePage && !isUserAuthPage}>');
    expect(providers).not.toContain('{isUserSidePage ? (');
  });

  it('uses Inertia lifecycle events for reduced-motion-safe user-side page transitions', () => {
    expect(source).toContain("componentName.startsWith('Notifications/Customer')");
    expect(source).toContain("const USER_SIDE_PAGE_ENTER_CLASS = 'userside-page-enter'");
    expect(source).toContain("router.on('start'");
    expect(source).toContain('triggerUserSidePageEnter(page?.component ?? \'\')');
    expect(source).toContain("document.documentElement.classList.add(USER_SIDE_PAGE_ENTER_CLASS)");
  });
});
