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

type AuthContext = 'user' | 'shop_owner';

const pageState = {
  props: {
    initialAuthContext: 'user' as AuthContext,
    csrf_token: 'csrf-token',
    flash: {},
  },
};

const submit = () => fireEvent.submit(screen.getByRole('button', { name: /sign in/i }).closest('form')!);

beforeEach(() => {
  routerPostMock.mockReset();
  usePageMock.mockReset();
  pageState.props = {
    initialAuthContext: 'user',
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

describe('shared sign-in account selector', () => {
  it('uses the server-provided initial context and exposes selected state accessibly', () => {
    pageState.props.initialAuthContext = 'shop_owner';

    render(<UserLogin />);

    expect(screen.getByRole('tab', { name: 'Customer / Staff' })).toHaveAttribute('aria-selected', 'false');
    expect(screen.getByRole('tab', { name: 'Shop Owner' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tablist', { name: 'Sign-in account type' })).toBeInTheDocument();
  });

  it('preserves email, password, and remember state while changing context', () => {
    render(<UserLogin />);

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'owner@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-password' } });
    fireEvent.click(screen.getByLabelText('Remember me'));
    fireEvent.click(screen.getByRole('tab', { name: 'Shop Owner' }));

    expect(screen.getByLabelText('Email')).toHaveValue('owner@example.test');
    expect(screen.getByLabelText('Password')).toHaveValue('secret-password');
    expect(screen.getByLabelText('Remember me')).toBeChecked();
  });

  it('submits Customer / Staff credentials only to the user endpoint', () => {
    render(<UserLogin />);

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'staff@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-password' } });
    submit();

    expect(routerPostMock).toHaveBeenCalledTimes(1);
    expect(routerPostMock).toHaveBeenCalledWith(
      '/user/login',
      { email: 'staff@example.test', password: 'secret-password', remember: false },
      expect.any(Object),
    );
  });

  it('submits Shop Owner credentials only to the owner endpoint', () => {
    render(<UserLogin />);

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'owner@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-password' } });
    fireEvent.click(screen.getByLabelText('Remember me'));
    fireEvent.click(screen.getByRole('tab', { name: 'Shop Owner' }));
    submit();

    expect(routerPostMock).toHaveBeenCalledTimes(1);
    expect(routerPostMock).toHaveBeenCalledWith(
      '/shop-owner/login',
      { email: 'owner@example.test', password: 'secret-password', remember: true },
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
