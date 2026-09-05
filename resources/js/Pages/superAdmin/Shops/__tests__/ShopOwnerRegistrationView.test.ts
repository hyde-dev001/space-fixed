import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShopOwnerRegistrationView from '../ShopOwnerRegistrationView';
import {
  areAllDocumentsViewed,
  buildRegistrationApprovalPayload,
  canDecideRegistration,
  getRegistrationDecisionErrorMessage,
} from '../ShopOwnerRegistrationView';

const { getMock, postMock, swalFireMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
  postMock: vi.fn(),
  swalFireMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => React.createElement(React.Fragment, null, children),
  Link: ({ children }: { children?: React.ReactNode }) => React.createElement(React.Fragment, null, children),
  router: { get: getMock, post: postMock },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => React.createElement('div', null, children),
}));

beforeEach(() => {
  getMock.mockReset();
  postMock.mockReset();
  swalFireMock.mockReset();
});

describe('areAllDocumentsViewed', () => {
  it('requires every submitted document to be viewed', () => {
    expect(areAllDocumentsViewed(0, new Set())).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0]))).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0, 1]))).toBe(true);
  });
});

describe('registration decision guards', () => {
  it('allows decisions only while the server-visible registration is pending', () => {
    expect(canDecideRegistration('pending')).toBe(true);
    expect(canDecideRegistration('approved')).toBe(false);
    expect(canDecideRegistration('rejected')).toBe(false);
  });

  it('surfaces server conflict and validation messages without inventing success', () => {
    expect(getRegistrationDecisionErrorMessage({
      registration: ['This registration was already decided.'],
    })).toBe('This registration was already decided.');
    expect(getRegistrationDecisionErrorMessage({
      rejection_reason: ['A rejection reason is required.'],
    })).toBe('A rejection reason is required.');
    expect(getRegistrationDecisionErrorMessage({})).toContain('server did not apply');
  });

  it('builds reviewer metadata from the server candidate and corrections', () => {
    const documents = [
      {
        id: 12,
        url: '/admin/shop-owners/1/documents/12',
        type: 'dti_registration',
        documentType: 'dti_registration',
        logicalSlot: 'business_registration',
        versionNumber: 1,
        issuedOn: '2026-01-01',
        expirationMode: 'none' as const,
        expiresOn: null,
      },
      {
        id: 13,
        url: '/admin/shop-owners/1/documents/13',
        type: 'mayors_permit',
        documentType: 'mayors_permit',
        logicalSlot: 'mayors_permit',
        versionNumber: 1,
        issuedOn: '2026-01-02',
        expirationMode: 'dated' as const,
        expiresOn: '2027-01-02',
      },
    ];

    expect(buildRegistrationApprovalPayload(
      documents,
      new Set([0, 1]),
      {
        12: {
          documentType: 'sec_registration',
          logicalSlot: 'business_registration',
          versionNumber: 1,
          issuedOn: '2026-01-01',
          expirationMode: 'dated',
          expiresOn: '2028-01-01',
        },
      },
    )).toEqual({
      documents: [
        {
          id: 12,
          document_type: 'sec_registration',
          logical_slot: 'business_registration',
          version_number: 1,
          issued_on: '2026-01-01',
          expiration_mode: 'dated',
          expires_on: '2028-01-01',
          viewed: true,
        },
        {
          id: 13,
          document_type: 'mayors_permit',
          logical_slot: 'mayors_permit',
          version_number: 1,
          issued_on: '2026-01-02',
          expiration_mode: 'dated',
          expires_on: '2027-01-02',
          viewed: true,
        },
      ],
    });
  });

  it('does not copy private storage fields into reviewer decision payloads', () => {
    const payload = buildRegistrationApprovalPayload([
      {
        id: 21,
        url: '/admin/shop-owners/1/documents/21',
        type: 'mayors_permit',
        logicalSlot: 'mayors_permit',
        versionNumber: 1,
        expirationMode: 'dated',
        expiresOn: '2027-01-01',
        ...({ file_path: 'private/permit.png', checksum_sha256: 'secret' } as Record<string, unknown>),
      },
    ], new Set([0]));

    expect(payload.documents[0]).not.toHaveProperty('file_path');
    expect(payload.documents[0]).not.toHaveProperty('checksum_sha256');
  });
});

describe('registration queue pagination UI', () => {
  it('sends search through the server paginator', async () => {
    render(
      React.createElement(ShopOwnerRegistrationView, {
        registrations: {
          data: [{
            id: 1,
            firstName: 'Owner',
            lastName: 'One',
            email: 'owner@example.test',
            phone: '09170000000',
            businessName: 'Sole Space',
            businessAddress: 'Cavite',
            businessType: 'Retail',
            serviceType: 'Retail',
            operatingHours: [],
            documents: [],
            documentUrls: [],
            status: 'pending',
            createdAt: '2026-08-12 12:00:00',
          }],
          current_page: 1,
          last_page: 2,
          per_page: 1,
          total: 2,
          from: 1,
          to: 1,
          links: [],
        } as never,
        stats: { total: 2, pending: 2, approved: 0, rejected: 0 },
        filters: { search: '', status: 'pending' },
      } as never)
    );

    fireEvent.change(screen.getByLabelText('Search Applications'), {
      target: { value: 'Sole' },
    });

    await waitFor(() => expect(getMock).toHaveBeenCalledWith(
      '/admin/registrations',
      { search: 'Sole', status: 'pending', page: 1 },
      expect.any(Object),
    ));
  });
});
