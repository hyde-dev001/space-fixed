import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/app.jsx'), 'utf8');

describe('application provider boundaries', () => {
  it('mounts the cart provider for every UserSide page but not internal role pages', () => {
    expect(source).toContain('const [component, setComponent] = useState(initialComponent)');
    expect(source).toContain("setComponent(event.detail?.page?.component ?? '')");
    expect(source).toContain("const isUserAuthPage = component.startsWith('UserSide/Auth/')");
    expect(source).toContain('<ApplicationProviders initialComponent={component}>');

    const providersStart = source.indexOf('const ApplicationProviders');
    const providersEnd = source.indexOf('// Update CSRF token', providersStart);
    const providers = source.slice(providersStart, providersEnd);
    const customerStart = providers.indexOf('{isUserSidePage ? (');
    const internalStart = providers.indexOf(') : (', customerStart);

    expect(providersStart).toBeGreaterThan(-1);
    expect(providersEnd).toBeGreaterThan(providersStart);
    expect(customerStart).toBeGreaterThan(-1);
    expect(internalStart).toBeGreaterThan(customerStart);

    const customerBranch = providers.slice(customerStart, internalStart);
    const internalBranch = providers.slice(internalStart);

    expect(customerBranch).toContain('<CartProvider syncEnabled={!isUserAuthPage}>');
    expect(internalBranch).not.toContain('<CartProvider>');
  });
});
