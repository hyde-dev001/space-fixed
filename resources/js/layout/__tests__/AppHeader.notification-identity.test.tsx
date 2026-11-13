import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AppHeader from '../AppHeader';

const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', () => ({
  usePage: () => page,
  Link: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props}>{children}</a>,
}));
vi.mock('../../context/SidebarContext', () => ({ useSidebar: () => ({ toggleSidebar: vi.fn(), toggleMobileSidebar: vi.fn() }) }));
vi.mock('../../components/common/ThemeToggleButton', () => ({ ThemeToggleButton: () => null }));
vi.mock('../../components/header/UserDropdown', () => ({ default: () => null }));
vi.mock('../../components/header/ShopOwnerDropdown', () => ({ default: () => null }));
vi.mock('../../components/header/SuperAdminDropdown', () => ({ default: () => null }));
let client: QueryClient;

beforeEach(() => {
  vi.stubGlobal('route', () => '/');
  client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 300000 } } });
  vi.stubGlobal('fetch', vi.fn(async (url: string) => ({ ok: true, status: 200, json: async () =>
    url.endsWith('/unread-count') ? { count: 1 } : { data: [{ id: 1, title: 'Scoped notification', message: 'Private', is_read: false, created_at: '2026-10-04' }] },
  })));
});
afterEach(() => { cleanup(); client.clear(); vi.unstubAllGlobals(); });

it.each([
  [{ user: { id: 5, shop_owner_id: 8 } }, '/api/staff/notifications', '/erp/notifications'],
  [{ shop_owner: { id: 8 } }, '/api/shop-owner/notifications', '/shop-owner/notifications'],
  [{ super_admin: { id: 8 } }, '/api/admin/notifications', '/admin/notifications'],
])('uses the authenticated scoped endpoint and renders paginated data: %j', async (auth, endpoint, href) => {
  page.props = { auth };
  render(<QueryClientProvider client={client}><AppHeader /></QueryClientProvider>);
  await waitFor(() => expect(fetch).toHaveBeenCalledWith(expect.stringContaining(endpoint + '?'), expect.anything()));
  fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));
  expect(await screen.findByText('Scoped notification')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /view all/i })).toHaveAttribute('href', href);
});

it('closes the notification panel on principal change', async () => {
  page.props = { auth: { user: { id: 5, shop_owner_id: 8 } } };
  const tree = () => <QueryClientProvider client={client}><AppHeader /></QueryClientProvider>;
  const view = render(tree());
  fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));
  await screen.findByRole('heading', { name: 'Notifications' });
  page.props = { auth: { user: { id: 6, shop_owner_id: 8 } } };
  view.rerender(tree());
  expect(screen.queryByRole('heading', { name: 'Notifications' })).not.toBeInTheDocument();
});
