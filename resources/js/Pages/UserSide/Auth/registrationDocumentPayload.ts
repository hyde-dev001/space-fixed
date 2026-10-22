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
  ocrScreening?: string | null;
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

const appendDocumentFile = (formData: FormData, field: string, file: File): void => {
  // Hostinger rejects apostrophes in multipart filenames; preserve the selected File and its bytes.
  const filename = file.name.replace(/'/g, '_');
  if (filename === file.name) {
    formData.append(field, file);
  } else {
    formData.append(field, file, filename);
  }
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
      appendDocumentFile(formData, slot, document.file);
    }

    appendMetadata(formData, `document_metadata[${slot}]`, document.metadata);

    if (submissionKey) {
      formData.append(`submission_keys[${slot}]`, submissionKey);
    }
  }

  for (const document of documents.supportingDocuments) {
    if (document.file) {
      appendDocumentFile(formData, `other_documents[${document.slotId}]`, document.file);
    }

    appendMetadata(formData, `other_document_metadata[${document.slotId}]`, document.metadata);

    if (document.submissionKey) {
      formData.append(`submission_keys[supporting_document:${document.slotId}]`, document.submissionKey);
    }
  }

  if (documents.ocrScreening) {
    formData.append('ocr_screening', documents.ocrScreening);
  }
};
