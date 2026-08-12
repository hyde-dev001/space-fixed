import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RegisteredShops from '../RegisteredShops';

const { fetchMock, swalFireMock } = vi.hoisted(() => ({
  fetchMock: vi.fn(),
  swalFireMock: vi.fn(),
}));

vi.stubGlobal('fetch', fetchMock);

vi.mock('@inertiajs/react', () => ({
  router: { delete: vi.fn() },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const shop = (overrides: Record<string, unknown> = {}) => ({
  id: 7,
  first_name: 'Shop',
  last_name: 'Owner',
  fullName: 'Shop Owner',
  email: 'owner@example.test',
  contact_number: '555-0100',
  phone: '555-0100',
  business_name: 'Sole Space',
  business_address: '1 Main Street',
  business_type: 'retail',
  registration_type: 'individual',
  status: 'approved',
  accountStatus: 'approved',
  archived: false,
  suspension_reason: null,
  created_at: '2026-08-12 12:00:00',
  approved_at: '2026-08-12 12:00:00',
  ...overrides,
});

const stats = { total: 1, active: 1, suspended: 0, archived: 0, thisMonth: 1 };

beforeEach(() => {
  fetchMock.mockReset();
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true, value: 'Verified lifecycle reason.' });
  fetchMock.mockResolvedValue({
    ok: true,
    json: async () => ({ message: 'Lifecycle operation completed.' }),
  });
});

afterEach(() => {
  vi.clearAllTimers();
});

describe('RegisteredShops lifecycle controls', () => {
  it('does not offer activation for pending or rejected shops', () => {
    render(
      <RegisteredShops
        shops={[
          shop({ id: 1, status: 'approved' }),
          shop({ id: 2, status: 'suspended', accountStatus: 'suspended' }),
          shop({ id: 3, status: 'pending', accountStatus: 'pending' }),
          shop({ id: 4, status: 'rejected', accountStatus: 'rejected' }),
          shop({ id: 5, status: 'archived', accountStatus: 'suspended', archived: true }),
        ]}
        stats={stats}
      />
    );

    expect(screen.getAllByTitle('Activate Shop')).toHaveLength(1);
    expect(screen.getByText('Archived')).toBeInTheDocument();
    expect(screen.queryByTitle('Delete Shop')).not.toBeInTheDocument();
  });

  it('restores an archived shop with a reason and the canonical route', async () => {
    render(
      <RegisteredShops
        shops={[shop({ id: 5, status: 'archived', accountStatus: 'suspended', archived: true })]}
        stats={{ ...stats, total: 1, active: 0, suspended: 0, archived: 1 }}
      />
    );

    fireEvent.click(screen.getByTitle('Restore Shop'));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/admin/shops/5/restore',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ restore_reason: 'Verified lifecycle reason.' }),
      }),
    ));

    expect(swalFireMock.mock.calls[0][0]).toMatchObject({
      input: 'textarea',
      title: 'Restore Shop',
    });
  });

  it('keeps the archived state when the server rejects a restore', async () => {
    fetchMock.mockResolvedValueOnce({
      ok: false,
      status: 409,
      json: async () => ({ message: 'The shop changed before restore.' }),
    });

    render(
      <RegisteredShops
        shops={[shop({ id: 5, status: 'archived', accountStatus: 'suspended', archived: true })]}
        stats={{ ...stats, total: 1, active: 0, suspended: 0, archived: 1 }}
      />
    );

    fireEvent.click(screen.getByTitle('Restore Shop'));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/admin/shops/5/restore',
      expect.objectContaining({ method: 'POST' }),
    ));
    expect(screen.getByTitle('Restore Shop')).toBeInTheDocument();
  });

  it('disables lifecycle controls while a restore is submitting', async () => {
    let resolveRequest: (value: unknown) => void = () => undefined;
    fetchMock.mockImplementationOnce(() => new Promise((resolve) => {
      resolveRequest = resolve;
    }));

    render(
      <RegisteredShops
        shops={[shop({ id: 5, status: 'archived', accountStatus: 'suspended', archived: true })]}
        stats={{ ...stats, total: 1, active: 0, suspended: 0, archived: 1 }}
      />
    );

    await act(async () => {
      fireEvent.click(screen.getByTitle('Restore Shop'));
      await Promise.resolve();
    });
    await waitFor(() => expect(screen.getByTitle('Restore Shop')).toBeDisabled());

    await act(async () => {
      resolveRequest({
        ok: true,
        json: async () => ({ message: 'Restored.' }),
      });
      await Promise.resolve();
    });
  });
});
