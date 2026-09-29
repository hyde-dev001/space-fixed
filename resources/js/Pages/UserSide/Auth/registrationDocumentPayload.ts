export type RegistrationDocumentMetadata = {
  issuedOn?: string;
  expirationMode: 'dated' | 'none';
  expiresOn?: string | null;
};

export type RegistrationDocumentUpload = {
  file: File | null;
  metadata: RegistrationDocumentMetadata;
};

export type SupportingDocumentUpload = RegistrationDocumentUpload & {
  slotId: string;
  submissionKey?: string;
};

export type RegistrationDocumentPayload = {
  businessRegistration: RegistrationDocumentUpload;
  businessRegistrationType: 'dti_registration' | 'sec_registration';
  mayorsPermit: RegistrationDocumentUpload;
  birCertificate: RegistrationDocumentUpload;
  validId: RegistrationDocumentUpload;
  submissionKeys: Partial<Record<'businessRegistration' | 'mayorsPermit' | 'birCertificate' | 'validId', string>>;
  supportingDocuments: SupportingDocumentUpload[];
};

const appendMetadata = (
  formData: FormData,
  prefix: string,
  metadata: RegistrationDocumentMetadata,
): void => {
  formData.append(`${prefix}[issued_on]`, metadata.issuedOn ?? '');
  formData.append(`${prefix}[expiration_mode]`, metadata.expirationMode);
  formData.append(`${prefix}[expires_on]`, metadata.expiresOn ?? '');
};

export const appendRegistrationDocuments = (
  formData: FormData,
  documents: RegistrationDocumentPayload,
): void => {
  formData.append('business_registration_type', documents.businessRegistrationType);

  const fixedDocuments: Array<[
    'business_registration' | 'mayors_permit' | 'bir_certificate' | 'valid_id',
    RegistrationDocumentUpload,
    string | undefined,
  ]> = [
    ['business_registration', documents.businessRegistration, documents.submissionKeys.businessRegistration],
    ['mayors_permit', documents.mayorsPermit, documents.submissionKeys.mayorsPermit],
    ['bir_certificate', documents.birCertificate, documents.submissionKeys.birCertificate],
    ['valid_id', documents.validId, documents.submissionKeys.validId],
  ];

  for (const [slot, document, submissionKey] of fixedDocuments) {
    if (document.file) {
      formData.append(slot, document.file);
    }

    appendMetadata(formData, `document_metadata[${slot}]`, document.metadata);

    if (submissionKey) {
      formData.append(`submission_keys[${slot}]`, submissionKey);
    }
  }

  for (const document of documents.supportingDocuments) {
    if (document.file) {
      formData.append(`other_documents[${document.slotId}]`, document.file);
    }

    appendMetadata(formData, `other_document_metadata[${document.slotId}]`, document.metadata);

    if (document.submissionKey) {
      formData.append(`submission_keys[supporting_document:${document.slotId}]`, document.submissionKey);
    }
  }
};
