import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { routerPostMock, usePageMock } = vi.hoisted(() => ({
  routerPostMock: vi.fn(),
  usePageMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  ),
  router: {
    post: routerPostMock,
  },
  usePage: () => usePageMock(),
}));

vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/icons/index', () => ({
  MailIcon: () => <span aria-hidden="true" />,
  LockIcon: () => <span aria-hidden="true" />,
}));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({
  default: { fire: vi.fn() },
}));

import UserLogin from '../UserLogin';

const pageState = {
  props: {
    csrf_token: 'csrf-token',
    flash: {},
  },
};

const submit = () => fireEvent.submit(screen.getByRole('button', { name: /sign in/i }).closest('form')!);

beforeEach(() => {
  routerPostMock.mockReset();
  usePageMock.mockReset();
  pageState.props = {
    csrf_token: 'csrf-token',
    flash: {},
  };
  usePageMock.mockReturnValue(pageState);
  (globalThis as { route?: (name: string) => string }).route = (name: string) => ({
    landing: '/',
    'password.request': '/forgot-password',
    register: '/register',
  })[name] ?? `/${name}`;
});

describe('unified sign-in', () => {
  it('renders one account-neutral sign-in form without an account selector', () => {
    render(<UserLogin />);

    expect(screen.queryByRole('tablist', { name: 'Sign-in account type' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Customer / Staff' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Shop Owner' })).not.toBeInTheDocument();
  });

  it('submits every account credential to the unified user login endpoint', () => {
    render(<UserLogin />);

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'owner@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-password' } });
    submit();

    expect(routerPostMock).toHaveBeenCalledTimes(1);
    expect(routerPostMock).toHaveBeenCalledWith(
      '/user/login',
      { email: 'owner@example.test', password: 'secret-password', remember: false },
      expect.any(Object),
    );
  });

  it('keeps authentication errors generic without naming an account type', () => {
    routerPostMock.mockImplementation((_url: string, _data: unknown, options: { onError?: (errors: { email: string }) => void }) => {
      options.onError?.({ email: 'Email or password is incorrect.' });
    });

    render(<UserLogin />);
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'unknown@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrong-password' } });
    submit();

    expect(screen.getByText('Email or password is incorrect.')).toBeInTheDocument();
  });
});
