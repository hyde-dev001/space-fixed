import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { post: vi.fn() },
}));

vi.mock('ziggy-js', () => ({
  route: (name: string) => `/${name}`,
}));

vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({
  default: { fire: vi.fn() },
}));

import VerificationNotice from '../VerificationNotice';

describe('customer email verification notice', () => {
  it('shows the actual email delivery warning and server screening result', () => {
    render(
      <VerificationNotice
        email="customer@example.test"
        warning="Your account was created, but we could not send the verification email."
        registrationEmailFailed
        identityVerification={{
          documentType: 'national_id',
          screeningStatus: 'automated_check_passed',
          reviewStatus: 'not_required',
        }}
      />,
    );

    expect(screen.getByText('Account created for:')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('could not send the verification email');
    expect(screen.getByText('Your uploaded document was accepted for registration.')).toBeInTheDocument();
    expect(screen.getByText(/does not prove government authenticity/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Return to Login' })).toBeInTheDocument();
  });
});
