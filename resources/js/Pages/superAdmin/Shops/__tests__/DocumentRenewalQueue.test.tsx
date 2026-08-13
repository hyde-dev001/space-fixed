import { describe, expect, it } from 'vitest';
import {
  buildRenewalApprovalPayload,
  canReviewRenewal,
  type DocumentRenewal,
} from '../DocumentRenewalQueue';

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
