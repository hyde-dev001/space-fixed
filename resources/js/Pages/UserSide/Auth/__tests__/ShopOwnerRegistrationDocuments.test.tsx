import { describe, expect, it } from 'vitest';
import {
  appendRegistrationDocuments,
  type RegistrationDocumentMetadata,
} from '../registrationDocumentPayload';

const metadata = (expirationMode: RegistrationDocumentMetadata['expirationMode'], expiresOn: string | null = null): RegistrationDocumentMetadata => ({
  issuedOn: '2026-01-01',
  expirationMode,
  expiresOn,
});

describe('shop owner registration document payload', () => {
  it('uses the canonical business registration contract and explicit metadata', () => {
    const businessRegistration = new File(['dti'], 'dti.png', { type: 'image/png' });
    const formData = new FormData();

    appendRegistrationDocuments(formData, {
      businessRegistration: { file: businessRegistration, metadata: metadata('none') },
      businessRegistrationType: 'dti_registration',
      mayorsPermit: { file: null, metadata: metadata('dated', '2027-01-01') },
      birCertificate: { file: null, metadata: metadata('none') },
      validId: { file: null, metadata: metadata('none') },
      submissionKeys: {
        businessRegistration: '11111111-1111-4111-8111-111111111111',
        mayorsPermit: '22222222-2222-4222-8222-222222222222',
        birCertificate: '33333333-3333-4333-8333-333333333333',
        validId: '44444444-4444-4444-8444-444444444444',
      },
      supportingDocuments: [],
    });

    expect(formData.get('business_registration')).toBe(businessRegistration);
    expect(formData.get('dti_registration')).toBeNull();
    expect(formData.get('business_registration_type')).toBe('dti_registration');
    expect(formData.get('document_metadata[business_registration][issued_on]')).toBe('2026-01-01');
    expect(formData.get('document_metadata[business_registration][expiration_mode]')).toBe('none');
    expect(formData.get('document_metadata[mayors_permit][expiration_mode]')).toBe('dated');
    expect(formData.get('document_metadata[mayors_permit][issued_on]')).toBe('2026-01-01');
    expect(formData.get('document_metadata[mayors_permit][expires_on]')).toBe('2027-01-01');
    expect(formData.get('submission_keys[business_registration]')).toBe('11111111-1111-4111-8111-111111111111');
  });

  it('sends supporting files under stable UUID slots with their metadata', () => {
    const slotId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const supportingFile = new File(['lease'], 'lease.png', { type: 'image/png' });
    const formData = new FormData();

    appendRegistrationDocuments(formData, {
      businessRegistration: { file: null, metadata: metadata('none') },
      businessRegistrationType: 'sec_registration',
      mayorsPermit: { file: null, metadata: metadata('dated', '2027-01-01') },
      birCertificate: { file: null, metadata: metadata('none') },
      validId: { file: null, metadata: metadata('none') },
      submissionKeys: {},
      supportingDocuments: [{
        slotId,
        file: supportingFile,
        metadata: metadata('dated', '2028-01-01'),
      }],
    });

    expect(formData.get(`other_documents[${slotId}]`)).toBe(supportingFile);
    expect(formData.get(`other_document_metadata[${slotId}][issued_on]`)).toBe('2026-01-01');
    expect(formData.get(`other_document_metadata[${slotId}][expiration_mode]`)).toBe('dated');
    expect(formData.get(`other_document_metadata[${slotId}][expires_on]`)).toBe('2028-01-01');
    expect(formData.get('business_registration_type')).toBe('sec_registration');
  });
});
