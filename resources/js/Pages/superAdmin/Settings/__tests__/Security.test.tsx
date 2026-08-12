import type { ReactNode } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { axiosPostMock, routerPostMock } = vi.hoisted(() => ({
  axiosPostMock: vi.fn(),
  routerPostMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  ),
  router: {
    post: routerPostMock,
  },
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

import Security from '../Security';
import Profile from '../Profile';

beforeEach(() => {
  axiosPostMock.mockReset();
  routerPostMock.mockReset();
});

describe('privileged security settings', () => {
  it('uses canonical MFA state and shows exhausted recovery codes without weakening MFA', () => {
    render(
      <Security
        security={{
          role: 'super_admin',
          status: 'active',
          mfa_complete: true,
          recovery_code_count: 0,
        }}
      />,
    );

    expect(screen.getByText(/mfa enabled/i)).toBeInTheDocument();
    expect(screen.getByText(/0 remaining/i)).toBeInTheDocument();
    expect(screen.getByText(/mfa remains active/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /generate new recovery codes/i })).toBeInTheDocument();
    expect(screen.queryByText(/secret|hash/i)).not.toBeInTheDocument();
  });

  it('consumes recovery JSON directly, prevents duplicate generation, and clears codes after acknowledgement', async () => {
    let resolveGeneration!: (value: { data: { recovery_codes: string[]; acknowledgement_token: string } }) => void;
    axiosPostMock.mockReturnValueOnce(new Promise((resolve) => {
      resolveGeneration = resolve;
    }));

    render(
      <Security
        security={{
          role: 'super_admin',
          status: 'active',
          mfa_complete: true,
          recovery_code_count: 8,
        }}
      />,
    );

    const generate = screen.getByRole('button', { name: /generate new recovery codes/i });
    fireEvent.click(generate);
    fireEvent.click(generate);
    expect(axiosPostMock).toHaveBeenCalledTimes(1);

    resolveGeneration({
      data: {
        recovery_codes: ['ALPHA-1111', 'BRAVO-2222'],
        acknowledgement_token: 'ack-secret',
      },
    });

    await waitFor(() => expect(screen.getByText('ALPHA-1111')).toBeInTheDocument());
    expect(screen.getByRole('checkbox', { name: /saved these recovery codes/i })).toBeInTheDocument();

    axiosPostMock.mockResolvedValueOnce({ data: { acknowledged: true } });
    fireEvent.click(screen.getByRole('checkbox', { name: /saved these recovery codes/i }));
    fireEvent.click(screen.getByRole('button', { name: /acknowledge and finish/i }));

    await waitFor(() => expect(screen.queryByText('ALPHA-1111')).not.toBeInTheDocument());
    expect(axiosPostMock).toHaveBeenLastCalledWith(
      '/admin/security/recovery/acknowledge',
      { token: 'ack-secret' },
      expect.any(Object),
    );
  });

  it('does not infer MFA completion from the recovery-code count', () => {
    render(
      <Security
        security={{
          role: 'admin',
          status: 'pending_setup',
          mfa_complete: false,
          recovery_code_count: 4,
        }}
      />,
    );

    expect(screen.getByText(/mfa setup required/i)).toBeInTheDocument();
    expect(screen.queryByText(/mfa enabled/i)).not.toBeInTheDocument();
  });

  it('links profile users to the dedicated security page', () => {
    render(
      <Profile
        auth={{
          user: {
            name: 'Ada Lovelace',
            email: 'ada@example.test',
          },
        }}
      />,
    );

    expect(screen.getByRole('link', { name: /security settings/i })).toHaveAttribute('href', '/admin/security');
  });
});
