import { describe, expect, it } from 'vitest';
import {
  canRenewComplianceDocument,
  initialRenewalDocumentType,
  validityLabel,
  type ComplianceSlot,
} from '../BusinessDocumentCompliance';

const slot = (overrides: Partial<ComplianceSlot> = {}): ComplianceSlot => ({
  logical_slot: 'mayors_permit',
  title: "Mayor's Permit / Business Permit",
  current: {
    id: 10,
    document_type: 'mayors_permit',
    logical_slot: 'mayors_permit',
    version_number: 1,
    status: 'approved',
    issued_on: '2026-01-01',
    expiration_mode: 'dated',
    expires_on: '2027-01-01',
    validity: 'valid',
    legacy_label: null,
    url: '/shop-owner/1/documents/10',
  },
  pending: null,
  history: [],
  ...overrides,
});

describe('BusinessDocumentCompliance', () => {
  it('allows renewal only for an approved slot without a pending version', () => {
    expect(canRenewComplianceDocument(slot())).toBe(true);
    expect(canRenewComplianceDocument(slot({ pending: slot().current }))).toBe(false);
    expect(canRenewComplianceDocument(slot({ current: { ...slot().current!, status: 'pending' } }))).toBe(false);
  });

  it('uses truthful validity labels, including unverified metadata', () => {
    expect(validityLabel('valid_no_expiration')).toContain('no expiration');
    expect(validityLabel('metadata_unverified')).toBe('Metadata unverified');
    expect(validityLabel('expired')).toBe('Expired');
  });

  it('keeps an ambiguous legacy document visibly renewable but never relabels it as DTI', () => {
    const legacy = slot({
      current: {
        ...slot().current!,
        document_type: 'legacy_dti_sec_registration',
        version_number: null,
        validity: 'metadata_unverified',
        legacy_label: 'Legacy DTI/SEC — classification pending',
      },
    });

    expect(canRenewComplianceDocument(legacy)).toBe(true);
    expect(legacy.current?.document_type).toBe('legacy_dti_sec_registration');
    expect(legacy.current?.legacy_label).toContain('classification pending');
  });

  it('requires an explicit authority choice before renewing an ambiguous legacy record', () => {
    expect(initialRenewalDocumentType('legacy_dti_sec_registration')).toBe('');
    expect(initialRenewalDocumentType('dti_registration')).toBe('dti_registration');
    expect(initialRenewalDocumentType('sec_registration')).toBe('sec_registration');
  });
});
