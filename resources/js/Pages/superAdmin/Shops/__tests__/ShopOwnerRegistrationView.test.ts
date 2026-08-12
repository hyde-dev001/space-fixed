import { describe, expect, it } from 'vitest';
import {
  areAllDocumentsViewed,
  buildRegistrationApprovalPayload,
  canDecideRegistration,
  getRegistrationDecisionErrorMessage,
} from '../ShopOwnerRegistrationView';

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
