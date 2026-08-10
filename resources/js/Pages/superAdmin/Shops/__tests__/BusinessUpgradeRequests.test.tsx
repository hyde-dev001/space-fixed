import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BusinessUpgradeRequests from '../BusinessUpgradeRequests';

const { patchMock, reloadMock, getMock } = vi.hoisted(() => ({
  patchMock: vi.fn(),
  reloadMock: vi.fn(),
  getMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  router: { patch: vi.fn(), reload: reloadMock, get: getMock },
  usePage: () => ({ props: {} }),
}));

vi.mock('axios', () => ({
  default: { patch: patchMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const pendingRequest = {
  id: 42,
  status: 'pending' as const,
  current_registration_type: 'individual',
  current_business_type: 'retail',
  requested_registration_type: 'company',
  requested_business_type: 'both',
  decision_reason: null,
  reviewed_at: null,
  created_at: '2026-08-10T04:00:00Z',
  shop_owner: {
    id: 7,
    business_name: 'Sole Space Shoes',
    name: 'Ava Owner',
    email: 'ava@example.test',
  },
  reviewed_by: null,
  documents: [
    {
      id: 100,
      document_type: 'valid_id',
      mime_type: 'application/pdf',
      size: 1234,
      source_status: 'uploaded',
      download_url: '/admin/business-upgrade-requests/42/documents/100',
    },
  ],
};

const props = {
  requests: [pendingRequest],
  filters: { status: 'pending', search: null, date_from: null, date_to: null },
  pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
};

beforeEach(() => {
  patchMock.mockReset();
  reloadMock.mockReset();
  getMock.mockReset();
  vi.stubGlobal('confirm', vi.fn(() => true));
});

describe('BusinessUpgradeRequests', () => {
  it('shows filters, owner transition summary, and private evidence download links', () => {
    render(<BusinessUpgradeRequests {...props} />);

    expect(screen.getByRole('heading', { name: /business upgrade requests/i })).toBeInTheDocument();
    expect(screen.getByText('Sole Space Shoes')).toBeInTheDocument();
    expect(screen.getByText(/individual retail/i)).toBeInTheDocument();
    expect(screen.getByText(/company both/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /valid id/i })).toHaveAttribute('href', pendingRequest.documents[0].download_url);
    expect(screen.getByRole('option', { name: /superseded/i })).toBeInTheDocument();
  });

  it('submits status/search filters and requests another page', () => {
    render(
      <BusinessUpgradeRequests
        {...props}
        pagination={{ ...props.pagination, last_page: 2 }}
      />,
    );

    fireEvent.change(screen.getByLabelText(/filter status/i), { target: { value: 'rejected' } });
    fireEvent.change(screen.getByLabelText(/search requests/i), { target: { value: 'Sole Space' } });
    fireEvent.click(screen.getByRole('button', { name: /apply filters/i }));

    expect(getMock).toHaveBeenCalledWith(
      '/admin/business-upgrade-requests',
      { status: 'rejected', search: 'Sole Space' },
      expect.objectContaining({ preserveState: true }),
    );

    fireEvent.click(screen.getByRole('button', { name: /next page/i }));
    expect(getMock).toHaveBeenLastCalledWith(
      '/admin/business-upgrade-requests',
      { status: 'rejected', search: 'Sole Space', page: '2' },
      expect.objectContaining({ preserveState: true }),
    );
  });

  it('confirms and submits an approval decision', async () => {
    patchMock.mockResolvedValueOnce({
      data: { request: { ...pendingRequest, status: 'approved', reviewed_at: '2026-08-10T05:00:00Z' } },
    });
    render(<BusinessUpgradeRequests {...props} />);

    fireEvent.click(screen.getByRole('button', { name: /approve request 42/i }));

    await waitFor(() => expect(patchMock).toHaveBeenCalledWith(
      '/admin/business-upgrade-requests/42',
      { decision: 'approved', decision_reason: null },
    ));
    await waitFor(() => expect(screen.getByText('Approved', { selector: 'span' })).toBeInTheDocument());
  });

  it('requires a rejection reason before submitting and then sends it', async () => {
    patchMock.mockResolvedValueOnce({
      data: { request: { ...pendingRequest, status: 'rejected', decision_reason: 'Please update the permit.' } },
    });
    render(<BusinessUpgradeRequests {...props} />);

    fireEvent.click(screen.getByRole('button', { name: /reject request 42/i }));
    fireEvent.click(screen.getByRole('button', { name: /confirm rejection/i }));
    expect(screen.getByText(/rejection reason is required/i)).toBeInTheDocument();
    expect(patchMock).not.toHaveBeenCalled();

    fireEvent.change(screen.getByRole('textbox', { name: /rejection reason/i }), { target: { value: 'Please update the permit.' } });
    fireEvent.click(screen.getByRole('button', { name: /confirm rejection/i }));

    await waitFor(() => expect(patchMock).toHaveBeenCalledWith(
      '/admin/business-upgrade-requests/42',
      { decision: 'rejected', decision_reason: 'Please update the permit.' },
    ));
  });

  it('refreshes the list and explains a stale 409 response', async () => {
    patchMock.mockRejectedValueOnce({
      response: { status: 409, data: { message: 'Another reviewer already decided this request.' } },
    });
    render(<BusinessUpgradeRequests {...props} />);

    fireEvent.click(screen.getByRole('button', { name: /approve request 42/i }));

    await waitFor(() => expect(screen.getByText(/another reviewer already decided/i)).toBeInTheDocument());
    expect(reloadMock).toHaveBeenCalled();
  });
});
