import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BusinessUpgradeRequests from '../BusinessUpgradeRequests';

const { patchMock, reloadMock, getMock, swalFireMock } = vi.hoisted(() => ({
  patchMock: vi.fn(),
  reloadMock: vi.fn(),
  getMock: vi.fn(),
  swalFireMock: vi.fn(() => Promise.resolve({ isConfirmed: true })),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  router: { patch: vi.fn(), reload: reloadMock, get: getMock },
  usePage: () => ({ props: {} }),
}));

vi.mock('axios', () => ({
  default: { patch: patchMock },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
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
      view_url: '/admin/business-upgrade-requests/42/documents/100/view',
    },
  ],
};

const props = {
  requests: [pendingRequest],
  filters: { status: 'pending', search: null, date_from: null, date_to: null },
  pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
};

const allStatusesProps = {
  ...props,
  filters: { ...props.filters, status: '' },
};

const openDetails = () => {
  fireEvent.click(screen.getByRole('button', { name: /view details for request 42/i }));
};

const viewDocument = () => {
  fireEvent.click(screen.getByRole('button', { name: /view document valid id/i }));
};

beforeEach(() => {
  patchMock.mockReset();
  reloadMock.mockReset();
  getMock.mockReset();
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true });
});

describe('BusinessUpgradeRequests', () => {
  it('shows filters, owner transition summary, and an evidence review button', () => {
    render(<BusinessUpgradeRequests {...props} />);

    expect(screen.getByRole('heading', { name: /business upgrade requests/i })).toBeInTheDocument();
    expect(screen.getByText('Sole Space Shoes')).toBeInTheDocument();
    expect(screen.getByText(/individual retail/i)).toBeInTheDocument();
    expect(screen.getByText(/company both/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /view details for request 42/i })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /valid id/i })).not.toBeInTheDocument();
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

  it('keeps the status selector aligned with the server-side URL filter', async () => {
    const { rerender } = render(<BusinessUpgradeRequests {...props} />);
    const statusFilter = screen.getByLabelText(/filter status/i);

    expect(statusFilter).toHaveValue('pending');

    rerender(<BusinessUpgradeRequests {...allStatusesProps} />);

    await waitFor(() => expect(statusFilter).toHaveValue(''));
  });

  it('keeps approval disabled until every submitted document is viewed', async () => {
    patchMock.mockResolvedValueOnce({
      data: {
        request: {
          ...pendingRequest,
          status: 'approved',
          reviewed_at: '2026-08-10T05:00:00Z',
        },
      },
    });
    render(<BusinessUpgradeRequests {...allStatusesProps} />);

    openDetails();
    const approveButton = screen.getByRole('button', { name: /approve request 42/i });
    expect(approveButton).toBeDisabled();
    expect(screen.getByRole('button', { name: /reject request 42/i })).toBeEnabled();

    viewDocument();
    expect(approveButton).toBeEnabled();
    fireEvent.click(approveButton);

    await waitFor(() => expect(patchMock).toHaveBeenCalledWith(
      '/admin/business-upgrade-requests/42',
      {
        decision: 'approved',
        decision_reason: null,
        documents: [{ id: 100, viewed: true }],
      },
    ));
    await waitFor(() => expect(screen.getByText('Approved', { selector: 'span' })).toBeInTheDocument());
    expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({ icon: 'success' }));
  });

  it('allows rejection with a reason before all documents are viewed', async () => {
    patchMock.mockResolvedValueOnce({
      data: { request: { ...pendingRequest, status: 'rejected', decision_reason: 'Please update the permit.' } },
    });
    render(<BusinessUpgradeRequests {...props} />);

    openDetails();
    fireEvent.click(screen.getByRole('button', { name: /reject request 42/i }));
    fireEvent.change(screen.getByRole('textbox', { name: /rejection reason/i }), { target: { value: 'Please update the permit.' } });
    fireEvent.click(screen.getByRole('button', { name: /confirm rejection/i }));

    await waitFor(() => expect(patchMock).toHaveBeenCalledWith(
      '/admin/business-upgrade-requests/42',
      { decision: 'rejected', decision_reason: 'Please update the permit.' },
    ));
  });

  it('removes a settled request from the pending queue but keeps it in the all-statuses view', async () => {
    patchMock.mockResolvedValueOnce({
      data: { request: { ...pendingRequest, status: 'approved' } },
    });
    const { rerender } = render(<BusinessUpgradeRequests {...props} />);

    openDetails();
    viewDocument();
    fireEvent.click(screen.getByRole('button', { name: /approve request 42/i }));

    await waitFor(() => expect(screen.queryByText('Sole Space Shoes')).not.toBeInTheDocument());

    rerender(<BusinessUpgradeRequests {...allStatusesProps} requests={[{ ...pendingRequest, status: 'approved' }]} />);
    expect(screen.getByText('Sole Space Shoes')).toBeInTheDocument();
    expect(screen.getByText('Approved', { selector: 'span' })).toBeInTheDocument();
  });

  it('refreshes the list and explains a stale 409 response', async () => {
    patchMock.mockRejectedValueOnce({
      response: { status: 409, data: { message: 'Another reviewer already decided this request.' } },
    });
    render(<BusinessUpgradeRequests {...allStatusesProps} />);

    openDetails();
    viewDocument();
    fireEvent.click(screen.getByRole('button', { name: /approve request 42/i }));

    await waitFor(() => expect(screen.getByText(/another reviewer already decided/i)).toBeInTheDocument());
    expect(reloadMock).toHaveBeenCalled();
  });
});
