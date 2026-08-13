import type { ReactNode } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { axiosPostMock, routerPostMock, usePageMock } = vi.hoisted(() => ({
  axiosPostMock: vi.fn(),
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

vi.mock('axios', () => ({
  default: {
    post: axiosPostMock,
  },
}));

vi.mock('sweetalert2', () => ({
  default: {
    fire: vi.fn(),
  },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

import SuperAdminLogin from '../SuperAdminLogin';
import PrivilegedSetup from '../PrivilegedSetup';
import PrivilegedResetPassword from '../PrivilegedResetPassword';
import PrivilegedMfaChallenge from '../PrivilegedMfaChallenge';
import PrivilegedMfaEnrollment from '../PrivilegedMfaEnrollment';
import PrivilegedRecoveryCodes from '../PrivilegedRecoveryCodes';
import PrivilegedReauthenticate from '../PrivilegedReauthenticate';
import CreateAdmin from '../../AdminTeam/CreateAdmin';

beforeEach(() => {
  axiosPostMock.mockReset();
  routerPostMock.mockReset();
  usePageMock.mockReset();
  usePageMock.mockReturnValue({ props: { errors: {} } });
  window.localStorage.clear();
  window.sessionStorage.clear();
  window.history.replaceState(null, '', '/admin/login');
});

describe('privileged authentication flows', () => {
  it('submits only login credentials and exposes password recovery', () => {
    render(<SuperAdminLogin />);

    fireEvent.change(screen.getByLabelText(/email address/i), {
      target: { value: 'admin@example.test' },
    });
    fireEvent.change(screen.getByLabelText(/^password$/i), {
      target: { value: 'secret-password' },
    });
    fireEvent.click(screen.getByLabelText(/remember me/i));
    fireEvent.submit(screen.getByRole('button', { name: /sign in/i }));

    expect(screen.getByRole('link', { name: /forgot password/i })).toHaveAttribute(
      'href',
      '/admin/forgot-password',
    );
    expect(routerPostMock).toHaveBeenCalledWith(
      '/admin/login',
      {
        email: 'admin@example.test',
        password: 'secret-password',
        remember: true,
      },
      expect.any(Object),
    );
    expect(Object.keys(routerPostMock.mock.calls[0][1])).toEqual(['email', 'password', 'remember']);
  });

  it('exchanges setup bearer fragments after cleaning the URL', async () => {
    window.history.replaceState(null, '', '/admin/setup?source=email#token=setup-secret');
    axiosPostMock.mockResolvedValue({
      data: { authorized: true, completion_proof: 'opaque-completion-proof' },
    });

    render(<PrivilegedSetup />);

    await waitFor(() => expect(axiosPostMock).toHaveBeenCalledTimes(1));

    expect(axiosPostMock).toHaveBeenCalledWith(
      '/admin/setup/exchange',
      { token: 'setup-secret' },
      expect.any(Object),
    );
    expect(window.location.pathname).toBe('/admin/setup');
    expect(window.location.search).toBe('?source=email');
    expect(window.location.hash).toBe('');
    expect(window.localStorage?.length ?? 0).toBe(0);
    expect(window.sessionStorage?.length ?? 0).toBe(0);
    expect(screen.queryByText('setup-secret')).not.toBeInTheDocument();
  });

  it('keeps the setup proof in memory, submits it, and displays token errors', async () => {
    window.history.replaceState(null, '', '/admin/setup#token=setup-secret');
    axiosPostMock.mockResolvedValue({
      data: { authorized: true, completion_proof: 'opaque-completion-proof' },
    });

    render(<PrivilegedSetup />);

    await screen.findByRole('button', { name: /continue to mfa setup/i });
    expect(document.body).not.toHaveTextContent('opaque-completion-proof');
    expect(window.location.href).not.toContain('opaque-completion-proof');
    expect(window.localStorage.length).toBe(0);
    expect(window.sessionStorage.length).toBe(0);

    fireEvent.change(screen.getByLabelText(/new password/i), {
      target: { value: 'LongEnough-Setup1!' },
    });
    fireEvent.change(screen.getByLabelText(/confirm password/i), {
      target: { value: 'LongEnough-Setup1!' },
    });
    fireEvent.submit(screen.getByRole('button', { name: /continue to mfa setup/i }).closest('form')!);

    expect(routerPostMock).toHaveBeenCalledWith(
      '/admin/setup/complete',
      {
        completion_proof: 'opaque-completion-proof',
        password: 'LongEnough-Setup1!',
        password_confirmation: 'LongEnough-Setup1!',
      },
      expect.any(Object),
    );

    const options = routerPostMock.mock.calls[0][2] as {
      onError?: (errors: Record<string, string>) => void;
    };
    act(() => options.onError?.({ token: 'The setup link is invalid or expired.' }));

    expect(screen.getByRole('alert')).toHaveTextContent('The setup link is invalid or expired.');
  });

  it('uses the same clean-fragment exchange for password reset links', async () => {
    window.history.replaceState(null, '', '/admin/reset-password#token=reset-secret');
    axiosPostMock.mockResolvedValue({ data: { authorized: true } });

    render(<PrivilegedResetPassword />);

    await waitFor(() => expect(axiosPostMock).toHaveBeenCalledTimes(1));

    expect(axiosPostMock).toHaveBeenCalledWith(
      '/admin/reset-password/exchange',
      { token: 'reset-secret' },
      expect.any(Object),
    );
    expect(window.location.hash).toBe('');
    expect(axiosPostMock).toHaveBeenCalledTimes(1);
  });

  it('provides an accessible six-digit TOTP challenge and recovery alternative', () => {
    render(<PrivilegedMfaChallenge />);

    const code = screen.getByLabelText(/six-digit verification code/i);
    expect(code).toHaveAttribute('inputmode', 'numeric');
    expect(code).toHaveAttribute('autocomplete', 'one-time-code');
    expect(screen.getByRole('button', { name: /use a recovery code/i })).toBeInTheDocument();

    fireEvent.change(code, { target: { value: '12' } });
    fireEvent.submit(screen.getByRole('button', { name: /verify/i }).closest('form')!);

    expect(screen.getByRole('alert')).toHaveTextContent(/six-digit/i);
    expect(routerPostMock).not.toHaveBeenCalled();
  });

  it('keeps enrollment in a standalone flow with QR, manual secret, and verification', () => {
    render(
      <PrivilegedMfaEnrollment
        qrCode="data:image/png;base64,qr"
        manualSecret="JBSWY3DPEHPK3PXP"
        issuer="SoleSpace"
      />,
    );

    expect(screen.getByRole('img', { name: /authenticator app qr code/i })).toBeInTheDocument();
    expect(screen.getByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument();
    expect(screen.getByLabelText(/six-digit verification code/i)).toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByText(/system monitoring/i)).not.toBeInTheDocument();
  });

  it('renders readable recovery codes and requires acknowledgement', () => {
    routerPostMock.mockImplementation((_url: string, _data: unknown, options?: { onSuccess?: () => void }) => {
      options?.onSuccess?.();
    });

    render(
      <PrivilegedRecoveryCodes
        recoveryCodes={['ALPHA-1111', 'BRAVO-2222']}
        acknowledgementToken="ack-secret"
      />,
    );

    expect(screen.getByText('ALPHA-1111')).toBeInTheDocument();
    expect(screen.getByText('BRAVO-2222')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /copy/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /print/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /download/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /saved these recovery codes/i })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('checkbox', { name: /saved these recovery codes/i }));
    fireEvent.click(screen.getByRole('button', { name: /continue/i }));

    expect(routerPostMock).toHaveBeenCalledWith(
      '/admin/mfa/setup/recovery/acknowledge',
      { token: 'ack-secret' },
      expect.any(Object),
    );
  });

  it('explains the fifteen-minute reauthentication window and submits two factors', () => {
    render(<PrivilegedReauthenticate intended="/admin/security" />);

    expect(screen.getByText(/15 minutes/i)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/current password/i), {
      target: { value: 'current-password' },
    });
    fireEvent.change(screen.getByLabelText(/six-digit verification code/i), {
      target: { value: '123456' },
    });
    fireEvent.submit(screen.getByRole('button', { name: /reauthenticate/i }).closest('form')!);

    expect(routerPostMock).toHaveBeenCalledWith(
      '/admin/reauthenticate',
      {
        password: 'current-password',
        code: '123456',
        intended: '/admin/security',
      },
      expect.any(Object),
    );
  });

  it('invites administrators without collecting or sending a password', () => {
    render(<CreateAdmin />);

    expect(screen.getByRole('heading', { name: /invite administrator/i })).toBeInTheDocument();
    expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/first name/i), { target: { value: 'Ada' } });
    fireEvent.change(screen.getByLabelText(/last name/i), { target: { value: 'Lovelace' } });
    fireEvent.change(screen.getByLabelText(/email address/i), { target: { value: 'ada@example.test' } });
    fireEvent.change(screen.getByLabelText(/phone number/i), { target: { value: '09123456789' } });
    fireEvent.submit(screen.getByRole('button', { name: /send invitation/i }).closest('form')!);

    expect(routerPostMock).toHaveBeenCalledWith(
      '/admin/administrators',
      {
        first_name: 'Ada',
        last_name: 'Lovelace',
        email: 'ada@example.test',
        phone: '09123456789',
        role: 'admin',
      },
      expect.any(Object),
    );
    expect(Object.keys(routerPostMock.mock.calls.at(-1)?.[1] ?? {})).not.toContain('password');
  });
});
