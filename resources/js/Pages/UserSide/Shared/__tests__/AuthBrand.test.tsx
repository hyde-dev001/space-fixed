import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { routerVisitMock } = vi.hoisted(() => ({
  routerVisitMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  ),
  router: {
    visit: routerVisitMock,
  },
}));

import AuthBrand from '../AuthBrand';

beforeEach(() => {
  vi.useFakeTimers();
  routerVisitMock.mockReset();
  (globalThis as { route?: (name: string) => string }).route = (name: string) => ({
    landing: '/',
    'admin.login': '/admin/login',
  })[name] ?? `/${name}`;
});

afterEach(() => {
  vi.useRealTimers();
});

describe('customer auth brand', () => {
  it('opens the landing page after a normal click', () => {
    render(<AuthBrand />);
    fireEvent.click(screen.getByRole('link', { name: 'SoleSpace' }));

    vi.advanceTimersByTime(700);

    expect(routerVisitMock).toHaveBeenCalledWith('/');
  });

  it('opens privileged login after seven consecutive clicks', () => {
    render(<AuthBrand />);
    const brand = screen.getByRole('link', { name: 'SoleSpace' });

    for (let click = 0; click < 7; click += 1) {
      fireEvent.click(brand);
    }

    expect(routerVisitMock).toHaveBeenCalledTimes(1);
    expect(routerVisitMock).toHaveBeenCalledWith('/admin/login');
  });
});
