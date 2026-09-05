import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DocumentRenewalQueue from '../DocumentRenewalQueue';
import {
  buildRenewalApprovalPayload,
  canReviewRenewal,
  type DocumentRenewal,
} from '../DocumentRenewalQueue';

const { getMock, postMock, reloadMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
  postMock: vi.fn(),
  reloadMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  router: { get: getMock, post: postMock, reload: reloadMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const renewal = (overrides: Partial<DocumentRenewal> = {}): DocumentRenewal => ({
  id: 7,
  document_type: 'mayors_permit',
  logical_slot: 'mayors_permit',
  version_number: 2,
  status: 'pending',
  issued_on: '2026-08-13',
  expiration_mode: 'dated',
  expires_on: '2027-08-13',
  validity: 'metadata_unverified',
  url: '/admin/shop-owners/1/documents/7',
  created_at: '2026-08-13T00:00:00.000Z',
  owner: {
    id: 1,
    business_name: 'Sole Space',
    name: 'Juan Dela Cruz',
    email: 'juan@example.test',
    status: 'approved',
  },
  predecessor: {
    id: 3,
    document_type: 'mayors_permit',
    logical_slot: 'mayors_permit',
    version_number: 1,
    status: 'approved',
    issued_on: '2026-01-01',
    expiration_mode: 'dated',
    expires_on: '2026-12-31',
    validity: 'valid',
    url: '/admin/shop-owners/1/documents/3',
  },
  ...overrides,
});

describe('document renewal review queue helpers', () => {
  it('only allows a pending renewal for an approved owner', () => {
    expect(canReviewRenewal(renewal())).toBe(true);
    expect(canReviewRenewal(renewal({ status: 'approved' }))).toBe(false);
    expect(canReviewRenewal(renewal({ owner: { ...renewal().owner, status: 'pending' } }))).toBe(false);
    expect(canReviewRenewal(renewal({ predecessor: null }))).toBe(false);
  });

  it('builds an approval payload from server metadata without private fields', () => {
    expect(buildRenewalApprovalPayload(renewal())).toEqual({
      document_type: 'mayors_permit',
      logical_slot: 'mayors_permit',
      version_number: 2,
      issued_on: '2026-08-13',
      expiration_mode: 'dated',
      expires_on: '2027-08-13',
      viewed: true,
    });
  });
});

describe('document renewal review queue UI', () => {
  beforeEach(() => {
    getMock.mockReset();
    postMock.mockReset();
    reloadMock.mockReset();
  });

  it('renders decided renewal history and applies server-side filters', () => {
    render(
      <DocumentRenewalQueue
        renewals={[renewal({ status: 'approved' })]}
        pagination={{ current_page: 1, per_page: 20, total: 1, last_page: 1 }}
        stats={{ total: 1, pending: 0, approved: 1, rejected: 0 }}
        filters={{ search: '', status: 'approved' }}
      />,
    );

    expect(screen.getByText('Approved renewals')).toBeInTheDocument();
    expect(screen.getByText('Sole Space')).toBeInTheDocument();
    expect(screen.getByText('approved')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Search renewals'), {
      target: { value: 'Sole' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(getMock).toHaveBeenCalledWith(
      '/admin/document-renewals',
      { search: 'Sole', status: 'approved', page: 1, per_page: 20 },
      expect.objectContaining({ replace: true }),
    );
  });
});
