import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/app.jsx'), 'utf8');

describe('application provider boundaries', () => {
  it('keeps both providers mounted while page contexts change', () => {
    expect(source).toContain('const [component, setComponent] = useState(initialComponent)');
    expect(source).toContain("setComponent(event.detail?.page?.component ?? '')");
    expect(source).toContain("const isUserAuthPage = component.startsWith('UserSide/Auth/')");
    expect(source).toContain('<ApplicationProviders initialComponent={component} initialStatus={props.initialPage?.props?.status}>');
    expect(source).toContain("import { CustomerPageTransition } from './components/common/CustomerPageTransition';");
    expect(source).toContain("import { MaintenanceProvider } from './providers/MaintenanceProvider';");
    expect(source).toContain("router.on('invalid', dispatchMaintenanceActive);");
    expect(source).toContain("router.on('error', dispatchMaintenanceActive);");

    const providersStart = source.indexOf('const ApplicationProviders');
    const providersEnd = source.indexOf('// Update CSRF token', providersStart);
    const providers = source.slice(providersStart, providersEnd);

    expect(providersStart).toBeGreaterThan(-1);
    expect(providersEnd).toBeGreaterThan(providersStart);
    expect(providers).toContain('<SidebarProvider>');
    expect(providers).toContain('<MaintenanceProvider isMaintenancePage={component === \'Maintenance\'} initialStatus={initialStatus}>');
    expect(providers).toContain('<CartProvider syncEnabled={isUserSidePage && !isUserAuthPage}>');
    expect(providers).not.toContain('{isUserSidePage ? (');
    expect(providers.match(/<CustomerPageTransition \/>/g)).toHaveLength(1);
  });
});
