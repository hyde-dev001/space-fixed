import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppSidebar from '../AppSidebar';

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    url: '/superAdmin/shop-owner-registration-view',
    props: { auth: { superAdmin: { role: 'super_admin' } } },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => ({
    isExpanded: true,
    isMobileOpen: false,
    isHovered: false,
    setIsHovered: vi.fn(),
    openSubmenu: 'main-1',
    toggleSubmenu: vi.fn(),
  }),
}));

beforeEach(() => {
  (globalThis as { route?: (name: string) => string }).route = (name: string) => {
    if (name === 'landing') return '/';
    throw new Error(`Missing route: ${name}`);
  };
});

it('keeps the business upgrade queue link usable when the generated route list is stale', () => {
  render(<AppSidebar />);

  fireEvent.click(screen.getByRole('button', { name: /account management/i }));

  expect(screen.getByRole('link', { name: /business upgrade requests/i }))
    .toHaveAttribute('href', '/admin/business-upgrade-requests');
});
