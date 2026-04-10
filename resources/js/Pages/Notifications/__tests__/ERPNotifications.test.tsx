import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import ERPNotifications from '../ERPNotifications';

const mockPageState: any = {
  props: {
    auth: {
      user: {
        role: '',
        roles: [],
      },
    },
  },
};

vi.mock('@inertiajs/react', () => ({
  usePage: () => mockPageState,
}));

vi.mock('../NotificationList', () => ({
  default: ({ basePath }: { basePath: string }) => <div data-testid="base-path">{basePath}</div>,
}));

describe('ERPNotifications', () => {
  it('maps staff-scoped roles to staff notifications namespace', () => {
    mockPageState.props.auth.user.role = 'repairer';
    mockPageState.props.auth.user.roles = ['Staff'];

    render(<ERPNotifications />);

    expect(screen.getByTestId('base-path').textContent).toBe('/api/staff/notifications');
  });

  it('maps non-staff ERP roles to HR notifications namespace', () => {
    mockPageState.props.auth.user.role = 'manager';
    mockPageState.props.auth.user.roles = ['Manager'];

    render(<ERPNotifications />);

    expect(screen.getByTestId('base-path').textContent).toBe('/api/hr/notifications');
  });
});
