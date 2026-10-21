import { describe, expect, it } from 'vitest';
import { scoreOwnerOcrEntry } from '../shopOwnerScreening';
import { assessShopOwnerDocument, type ShopOwnerOcrContext } from '../shopOwnerDocumentRecognition';
import type { ShopOwnerOcrPass } from '../registrationOcr';
import recognitionEvidence from '../../../../../../tests/Fixtures/ShopOwnerOcr/recognition-evidence.json';

const BASE_CONTEXT: ShopOwnerOcrContext = {
  firstName: 'Mira',
  lastName: 'Sample',
  businessName: 'Sample Shoe House',
  businessAddress: '123 Example Street, Barangay Sample, Imus, Cavite',
  registrationType: 'company',
  referenceDay: '2026-10-21',
  metadata: {
    business_registration: { expirationMode: 'dated', issuedOn: '2025-01-15', expiresOn: '2030-01-15' },
    mayors_permit: { expirationMode: 'dated', issuedOn: '2026-01-10', expiresOn: '2027-12-31' },
    bir_certificate: { expirationMode: 'none' },
    valid_id: { expirationMode: 'none' },
  },
};

function pass(lines: string[], confidence = 0.96): ShopOwnerOcrPass {
  const recognizedLines = lines.map((text, index) => {
    const y0 = 40 + index * 34;
    const words = text.match(/[\p{L}\p{N}]+(?:[./'-][\p{L}\p{N}]+)*/gu) ?? [];
    return {
      text,
      confidence,
      bbox: { x0: 40, y0, x1: 40 + text.length * 9, y1: y0 + 24 },
      words: words.map((word, wordIndex) => ({
        text: word,
        confidence,
        bbox: { x0: 40 + wordIndex * 32, y0, x1: 64 + wordIndex * 32, y1: y0 + 24 },
        pass_id: 'full_image' as const,
      })),
    };
  });
  return {
    id: 'full_image',
    text: lines.join('\n'),
    confidence,
    image_width: 1200,
    image_height: 1800,
    lines: recognizedLines,
  };
}

const dtiLines = (overrides: Partial<Record<'business' | 'proprietor' | 'issued' | 'expires', string>> = {}) => [
  'Department of Trade and Industry',
  'Certificate of Business Name Registration',
  `Registered Business Name: ${overrides.business ?? 'Sample Shoe House'}`,
  `Proprietor: ${overrides.proprietor ?? 'Mira Sample'}`,
  'Registration No: DTI-SYN-1200',
  `Date Issued: ${overrides.issued ?? 'January 15, 2025'}`,
  `Valid Until: ${overrides.expires ?? 'January 15, 2030'}`,
];

const mayorLines = (address = '123 Example Street, Barangay Sample, Imus, Cavite') => [
  'Municipality of Imus',
  "Mayor's Permit",
  'Permit No: LGU-SYN-2026-10',
  'Business Name: Sample Shoe House',
  `Business Address: ${address}`,
  'Date Issued: January 10, 2026',
  'Valid Until: December 31, 2027',
];

function assess(
  slot: 'business_registration' | 'mayors_permit' | 'bir_certificate' | 'valid_id',
  type: 'dti_registration' | 'sec_registration' | 'mayors_permit' | 'bir_certificate' | 'valid_id',
  lines: string[],
  context: ShopOwnerOcrContext = BASE_CONTEXT,
  confidence = 0.96,
  passes?: ShopOwnerOcrPass[],
) {
  const evidence = passes ?? [pass(lines, confidence)];
  const entry = assessShopOwnerDocument(slot, type, evidence, context);
  return { entry, score: scoreOwnerOcrEntry(slot, type, entry).score };
}

describe('shop owner document recognition', () => {
  it('keeps the shared synthetic field-evidence fixture limited to approved non-sensitive cases', () => {
    const ids = recognitionEvidence.passes.map((item) => item.id);
    expect(recognitionEvidence.source).toBe('synthetic-only');
    expect(ids).toContain('combined_type_signals_one_line');
    expect(ids).toContain('unsupported_government_id');
    expect(ids).toContain('localized_filipino_nationality');
  });

  it('recognizes and matches a DTI registration with localized business, proprietor, and date fields', () => {
    const result = assess('business_registration', 'dti_registration', dtiLines());

    expect(result.score).toBe(100);
    expect(result.entry).toMatchObject({
      assessment_state: 'assessed',
      detected_family: 'dti_registration',
      checks: {
        document_type: { result: 'match', independent_type_signals: true },
        business_identity: { result: 'match', field_associated: true },
        proprietor_identity: { result: 'match', field_associated: true },
        dates: { result: 'match' },
      },
    });
    expect(JSON.stringify(result.entry)).not.toContain('Sample Shoe House');
  });

  it('reports a reliable selected DTI versus detected SEC document conflict', () => {
    const result = assess('business_registration', 'dti_registration', [
      'Securities and Exchange Commission',
      'Certificate of Incorporation',
      'Registered Entity Name: Sample Shoe House',
      'Registration No: SEC-SYN-12',
    ]);

    expect(result.score).toBe(0);
    expect(result.entry.detected_family).toBe('sec_registration');
    expect(result.entry.reason_codes).toContain('wrong_document_type');
  });

  it('requires independent type indicators on separate localized lines', () => {
    const result = assess('business_registration', 'dti_registration', [
      'Department of Trade and Industry Certificate of Business Name Registration Registered Business Name: Sample Shoe House',
      'Proprietor: Mira Sample',
      'Registration No: DTI-SYN-1200',
    ]);

    expect(result.score).toBe(50);
    expect(result.entry.checks.document_type?.result).toBe('uncertain');
    expect(result.entry.reason_codes).toContain('unsupported_layout');
  });

  it('keeps a legal entity name difference uncertain when it is not linked to the trade name', () => {
    const result = assess('business_registration', 'sec_registration', [
      'Securities and Exchange Commission',
      'Certificate of Incorporation',
      'Legal Entity Name: Sample Holdings Corporation',
      'Registration No: SEC-SYN-12',
    ]);

    expect(result.score).toBe(50);
    expect(result.entry.checks.business_identity?.result).toBe('uncertain');
  });

  it('matches a comparable SEC business identity and never requires a registering representative', () => {
    const result = assess('business_registration', 'sec_registration', [
      'Securities and Exchange Commission',
      'Certificate of Incorporation',
      'Registered Entity Name: Sample Shoe House',
      'Registration No: SEC-SYN-12',
      'Date Issued: January 15, 2025',
      'Valid Until: January 15, 2030',
    ]);

    expect(result.score).toBe(100);
    expect(result.entry.checks.business_identity?.result).toBe('match');
    expect(result.entry.checks.proprietor_identity).toBeUndefined();
  });

  it('does not turn weak or O/0/I/1-like business-name readings into conflicts', () => {
    const lowConfidence = assess('business_registration', 'dti_registration', dtiLines({ business: 'Other Shoe House' }), BASE_CONTEXT, 0.72);
    const mixedCharacter = assess('business_registration', 'dti_registration', dtiLines({ business: 'Samp1e Shoe House' }));

    expect(lowConfidence.score).toBe(50);
    expect(lowConfidence.entry.checks.business_identity?.result).toBe('uncertain');
    expect(mixedCharacter.score).toBe(50);
    expect(mixedCharacter.entry.checks.business_identity?.result).toBe('uncertain');
  });

  it('keeps broken, obscured, and unlabeled business-name evidence uncertain', () => {
    const brokenWord = assess('business_registration', 'dti_registration', dtiLines({ business: 'Sample-' }));
    const obscured = assess('business_registration', 'dti_registration', dtiLines({ business: 'Unreadable Shoe House' }));
    const missingLabel = assess('business_registration', 'dti_registration', dtiLines()
      .filter((line) => !line.startsWith('Registered Business Name:')));

    expect(brokenWord.entry.checks.business_identity?.result).toBe('uncertain');
    expect(obscured.entry.checks.business_identity?.result).toBe('uncertain');
    expect(missingLabel.entry.checks.business_identity?.result).toBe('uncertain');
    expect([brokenWord.score, obscured.score, missingLabel.score]).toEqual([50, 50, 50]);
  });

  it('keeps incomplete personal-name evidence uncertain and does not confuse signatories with proprietors', () => {
    const context = { ...BASE_CONTEXT, firstName: 'Maria', lastName: 'De La Cruz' };
    const partial = assess('business_registration', 'dti_registration', dtiLines({ proprietor: 'Maria Cruz' }), context);
    const signatoryOnly = assess('business_registration', 'dti_registration', [
      'Department of Trade and Industry',
      'Certificate of Business Name Registration',
      'Registered Business Name: Sample Shoe House',
      'Authorized Signatory: Mira Sample',
      'Registration No: DTI-SYN-1200',
    ]);

    expect(partial.score).toBe(50);
    expect(partial.entry.checks.proprietor_identity?.result).toBe('uncertain');
    expect(signatoryOnly.score).toBe(50);
    expect(signatoryOnly.entry.checks.proprietor_identity?.result).toBe('uncertain');
  });

  it('accepts two distinct Mayor permit layouts but requires a comparable full business address', () => {
    const layoutA = assess('mayors_permit', 'mayors_permit', mayorLines());
    const layoutB = assess('mayors_permit', 'mayors_permit', [
      'City Government of Imus',
      'Business Permit',
      'Permit Reference: LGU-SYN-2026-10',
      'Trade Name: Sample Shoe House',
      'Premises Address: 123 Example Street, Brgy. Sample, Imus, Cavite',
      'Issued: 10 January 2026',
      'Expiry Date: 31 December 2027',
    ]);
    const cityOnly = assess('mayors_permit', 'mayors_permit', mayorLines('Imus'));

    expect(layoutA.score).toBe(100);
    expect(layoutB.score).toBe(100);
    expect(cityOnly.score).toBe(50);
    expect(cityOnly.entry.checks.business_address?.result).toBe('uncertain');
  });

  it('flags a confidently different full Mayor permit business address', () => {
    const result = assess('mayors_permit', 'mayors_permit', mayorLines('99 Other Road, Barangay Elsewhere, Demo City, Example Province'));

    expect(result.score).toBe(0);
    expect(result.entry.checks.business_address?.result).toBe('mismatch');
    expect(result.entry.reason_codes).toContain('business_address_conflict');
  });

  it('uses BIR taxpayer roles without comparing a corporate taxpayer to the applicant', () => {
    const result = assess('bir_certificate', 'bir_certificate', [
      'Bureau of Internal Revenue',
      'Certificate of Registration Form 2303',
      'Taxpayer Classification: Non-Individual',
      'Taxpayer Name: Sample Holdings Corporation',
      'Business Name: Sample Shoe House',
      'Registered Address: 123 Example Street, Barangay Sample, Imus, Cavite',
      'Date of Registration: January 15, 2025',
    ]);

    expect(result.score).toBe(100);
    expect(result.entry.relationship).toBe('corporate');
    expect(result.entry.checks.proprietor_identity).toBeUndefined();
    expect(result.entry.checks.dates?.result).toBe('match');
  });

  it('requires and compares proprietor identity for an explicit sole-proprietor BIR entry', () => {
    const result = assess('bir_certificate', 'bir_certificate', [
      'Bureau of Internal Revenue',
      'Certificate of Registration Form 2303',
      'Taxpayer Classification: Sole Proprietor',
      'Taxpayer Name: Mira Sample',
      'Business Name: Sample Shoe House',
      'Registered Address: 123 Example Street, Barangay Sample, Imus, Cavite',
      'Date of Registration: January 15, 2025',
    ]);

    expect(result.score).toBe(100);
    expect(result.entry.relationship).toBe('sole_proprietor');
    expect(result.entry.checks.proprietor_identity?.result).toBe('match');
  });

  it('flags a reliable BIR sole-proprietor name conflict but does not invent a TIN comparison', () => {
    const result = assess('bir_certificate', 'bir_certificate', [
      'Bureau of Internal Revenue',
      'Certificate of Registration Form 2303',
      'Taxpayer Classification: Sole Proprietor',
      'Taxpayer Name: Other Sample',
      'Business Name: Sample Shoe House',
      'Registered Address: 123 Example Street, Barangay Sample, Imus, Cavite',
      'Date of Registration: January 15, 2025',
      'TIN: 000-000-000-000',
    ]);

    expect(result.score).toBe(0);
    expect(result.entry.checks.proprietor_identity?.result).toBe('mismatch');
    expect(JSON.stringify(result.entry)).not.toContain('000-000-000-000');
  });

  it('keeps BIR business identity uncertain when taxpayer relationship is unclear', () => {
    const result = assess('bir_certificate', 'bir_certificate', [
      'Bureau of Internal Revenue',
      'Certificate of Registration Form 2303',
      'Taxpayer Name: Sample Shoe House',
      'Business Name: Sample Shoe House',
      'Registered Address: 123 Example Street, Barangay Sample, Imus, Cavite',
    ]);

    expect(result.score).toBe(50);
    expect(result.entry.relationship).toBe('unclear');
    expect(result.entry.checks.business_identity?.result).toBe('uncertain');
  });

  it('recognizes a National ID as non-expiring and compares only its holder name', () => {
    const result = assess('valid_id', 'valid_id', [
      'Republic of the Philippines',
      'Philippine Identification Card',
      'Philippine Statistics Authority',
      'Nationality: Filipino',
      'Full Name: Mira Sample',
      'Date of Birth: 05 February 1990',
    ]);

    expect(result.score).toBe(100);
    expect(result.entry.detected_family).toBe('national_id');
    expect(result.entry.checks.owner_identity?.result).toBe('match');
    expect(result.entry.checks.business_address).toBeUndefined();
  });

  it('recognizes a supported passport family with its own labeled holder and dates', () => {
    const result = assess('valid_id', 'valid_id', [
      'Republic of the Philippines',
      'Passport',
      'Surname: Sample',
      'Given Names: Mira',
      'Passport No: P-SYN-1234',
      'Date Issued: January 15, 2025',
      'Date of Expiration: January 15, 2030',
    ], {
      ...BASE_CONTEXT,
      metadata: { ...BASE_CONTEXT.metadata, valid_id: { issuedOn: '2025-01-15', expirationMode: 'dated', expiresOn: '2030-01-15' } },
    });

    expect(result.score).toBe(100);
    expect(result.entry.detected_family).toBe('passport');
    expect(result.entry.checks.owner_identity?.result).toBe('match');
  });

  it('flags a complete high-confidence ID holder-name conflict', () => {
    const result = assess('valid_id', 'valid_id', [
      'Land Transportation Office',
      "Driver's License",
      'Full Name: Another Person',
      'License Number: DL-SYN-0042',
      'Date Issued: January 15, 2025',
      'Valid Until: January 15, 2030',
    ], {
      ...BASE_CONTEXT,
      metadata: { ...BASE_CONTEXT.metadata, valid_id: { issuedOn: '2025-01-15', expirationMode: 'dated', expiresOn: '2030-01-15' } },
    });

    expect(result.score).toBe(0);
    expect(result.entry.checks.owner_identity?.result).toBe('mismatch');
    expect(result.entry.reason_codes).toContain('owner_identity_conflict');
  });

  it('keeps a plausible unsupported government ID uncertain instead of calling it a conflict', () => {
    const result = assess('valid_id', 'valid_id', [
      'Republic of the Philippines',
      'Government Identification Card',
      'Full Name: Mira Sample',
      'Identification No: ID-SYN-005',
    ]);

    expect(result.score).toBe(50);
    expect(result.entry.detected_family).toBeNull();
    expect(result.entry.checks.document_type?.result).toBe('uncertain');
    expect(result.entry.reason_codes).toContain('unsupported_layout');
  });

  it('recognizes reliable non-ID document content as a conflict for the ID slot', () => {
    const result = assess('valid_id', 'valid_id', [
      'Supplier Billing Statement',
      'Tax Invoice',
      'Invoice No: INV-SYN-12',
      'Total Amount: 500',
    ]);

    expect(result.score).toBe(0);
    expect(result.entry.checks.document_type?.result).toBe('mismatch');
    expect(result.entry.reason_codes).toContain('wrong_document_type');
  });

  it('does not let a document-wide score qualify missing localized field evidence', () => {
    const lines = dtiLines();
    const noWordEvidence = pass(lines, 0.99);
    noWordEvidence.lines = noWordEvidence.lines.map((line) => ({ ...line, words: [] }));
    const result = assess('business_registration', 'dti_registration', lines, BASE_CONTEXT, 0.99, [noWordEvidence]);

    expect(result.score).toBe(50);
    expect(result.entry.checks.business_identity?.result).toBe('uncertain');
  });

  it('leaves contradictory OCR passes uncertain rather than selecting the higher reading', () => {
    const result = assess('business_registration', 'dti_registration', dtiLines(), BASE_CONTEXT, 0.96, [
      pass(dtiLines()),
      pass(dtiLines({ business: 'Other Shoe House' })),
    ]);

    expect(result.score).toBe(50);
    expect(result.entry.checks.business_identity?.result).toBe('uncertain');
  });

  it('marks ambiguous, expired, future, reversed, and conflicting dates conservatively', () => {
    const ambiguous = assess('business_registration', 'dti_registration', dtiLines({ expires: '10/11/2028' }), {
      ...BASE_CONTEXT,
      metadata: { ...BASE_CONTEXT.metadata, business_registration: { expirationMode: 'dated', expiresOn: '2028-11-10' } },
    });
    const expired = assess('business_registration', 'dti_registration', dtiLines({ expires: 'January 15, 2020' }), {
      ...BASE_CONTEXT,
      metadata: { ...BASE_CONTEXT.metadata, business_registration: { expirationMode: 'dated', expiresOn: '2020-01-15' } },
    });
    const future = assess('business_registration', 'dti_registration', dtiLines({ issued: 'January 15, 2028' }));
    const reversed = assess('business_registration', 'dti_registration', dtiLines({ issued: 'January 15, 2031' }));
    const metadataConflict = assess('business_registration', 'dti_registration', dtiLines(), {
      ...BASE_CONTEXT,
      metadata: { ...BASE_CONTEXT.metadata, business_registration: { expirationMode: 'dated', expiresOn: '2031-01-15' } },
    });

    expect(ambiguous.entry.checks.dates?.result).toBe('uncertain');
    expect(expired.entry.checks.dates?.result).toBe('mismatch');
    expect(future.entry.checks.dates?.result).toBe('mismatch');
    expect(reversed.entry.checks.dates?.result).toBe('mismatch');
    expect(metadataConflict.entry.checks.dates?.result).toBe('mismatch');
  });

  it('downgrades an expired date after reference-day rollover without erasing a separate name conflict', () => {
    const lines = dtiLines({ business: 'Other Shoe House', expires: 'October 21, 2026' });
    const context = {
      ...BASE_CONTEXT,
      referenceDay: '2026-10-20',
      metadata: { ...BASE_CONTEXT.metadata, business_registration: { expirationMode: 'dated' as const, expiresOn: '2026-10-21' } },
    };
    const beforeRollover = assess('business_registration', 'dti_registration', lines, context);
    const afterRollover = assess('business_registration', 'dti_registration', lines, { ...context, referenceDay: '2026-10-22' });

    expect(beforeRollover.entry.checks.dates?.result).toBe('match');
    expect(beforeRollover.entry.checks.business_identity?.result).toBe('mismatch');
    expect(afterRollover.entry.checks.dates?.result).toBe('mismatch');
    expect(afterRollover.entry.checks.business_identity?.result).toBe('mismatch');
    expect(afterRollover.score).toBe(0);
  });

  it('does not infer a BIR annual expiry and requires reference-day evidence for dates', () => {
    const noAnnualExpiry = assess('bir_certificate', 'bir_certificate', [
      'Bureau of Internal Revenue',
      'Certificate of Registration Form 2303',
      'Taxpayer Classification: Non-Individual',
      'Business Name: Sample Shoe House',
      'Registered Address: 123 Example Street, Barangay Sample, Imus, Cavite',
    ]);
    const noReferenceDay = assess('valid_id', 'valid_id', [
      'Republic of the Philippines',
      'Philippine Identification Card',
      'Philippine Statistics Authority',
      'Full Name: Mira Sample',
    ], { ...BASE_CONTEXT, referenceDay: null });

    expect(noAnnualExpiry.score).toBe(100);
    expect(noReferenceDay.score).toBe(50);
    expect(noReferenceDay.entry.checks.dates?.result).toBe('uncertain');
  });

  it('returns a nonnumeric unable-to-assess entry when no usable text exists', () => {
    const unreadable = pass(['']);
    unreadable.text = '';
    unreadable.lines = [];
    const result = assess('valid_id', 'valid_id', [], BASE_CONTEXT, 0, [unreadable]);

    expect(result.score).toBeNull();
    expect(result.entry.assessment_state).toBe('unable_to_assess');
  });
});
