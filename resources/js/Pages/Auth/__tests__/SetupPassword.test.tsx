import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SetupPassword from '../SetupPassword';
import { syncPageTheme } from '../../../utils/pageTheme';

const useFormMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  useForm: useFormMock,
}));

vi.mock('../../UserSide/Shared/Navigation', () => ({
  default: () => <nav data-testid="navigation" />,
}));

const props = {
  email: 'owner@example.com',
  token: 'setup-token',
  shopOwner: {
    business_name: 'SoleSpace Shop',
    first_name: 'Maya',
  },
};

describe('SetupPassword theme', () => {
  beforeEach(() => {
    document.documentElement.className = '';
    localStorage.clear();
    useFormMock.mockReturnValue({
      data: {
        email: props.email,
        token: props.token,
        password: '',
        password_confirmation: '',
      },
      setData: vi.fn(),
      post: vi.fn(),
      processing: false,
      errors: {},
    });
  });

  it('gives the complete setup page a dark palette when the app theme is dark', () => {
    localStorage.setItem('theme', 'dark');
    syncPageTheme('Auth/SetupPassword');

    const { container } = render(<SetupPassword {...props} />);
    const page = container.querySelector('.min-h-screen') as HTMLElement;
    const card = container.querySelector('.max-w-md') as HTMLElement;

    expect(document.documentElement).toHaveClass('dark', 'userside-theme', 'userside-dark');
    expect(page).toHaveClass('userside-auth-page');
    expect(card).toHaveClass('userside-auth-card');
    expect(screen.getByRole('heading', { name: 'Welcome, Maya!' })).toHaveClass('userside-auth-title');
    expect(screen.getByText('Set up your password for')).toHaveClass('userside-auth-subtitle');
    expect(screen.getByLabelText('Password')).toHaveClass('userside-auth-input');
  });

  it('keeps the existing light palette available when dark mode is off', () => {
    syncPageTheme('Auth/SetupPassword');

    const { container } = render(<SetupPassword {...props} />);
    const page = container.querySelector('.min-h-screen') as HTMLElement;
    const card = container.querySelector('.max-w-md') as HTMLElement;

    expect(page).toHaveClass('bg-white');
    expect(card).toHaveClass('border-gray-200', 'bg-white');
    expect(screen.getByLabelText('Password')).toHaveClass('border-gray-300');
    expect(document.documentElement).not.toHaveClass('dark', 'userside-dark');
  });
});
