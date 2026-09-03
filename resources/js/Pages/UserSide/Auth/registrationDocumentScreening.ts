import {
  fingerprintRegistrationImage,
  readRegistrationId,
} from './registrationOcr';
import type {
  RegistrationDocumentSide,
  RegistrationDuplicateKind,
  RegistrationImageFingerprint,
  RegistrationOcrResult,
} from './registrationOcr';

export type RegistrationScreeningOutcome = 'reject_upload' | 'screening_passed' | 'manual_review_required' | 'screening_error';
export type RegistrationSideOutcome = 'plausible' | 'uncertain' | 'reject_upload' | 'screening_error';
export type RegistrationNationalIdFormat = 'physical_card' | 'digital_image';
export type RegistrationNameMatchOutcome = 'matched' | 'mismatch' | 'unreadable' | null;

export type RegistrationConfidenceBand = 'low' | 'medium' | 'high' | null;

export type RegistrationDocumentSideResult = {
  side: RegistrationDocumentSide;
  outcome: RegistrationSideOutcome;
  ocrText: string;
  ocrConfidence: number;
  detectedDocumentFamily: string | null;
  detectedAnchorKeys: string[];
  confidenceBand: RegistrationConfidenceBand;
  qrDetected: boolean;
  fingerprint: string | null;
  imageFingerprint: RegistrationImageFingerprint | null;
  validationNotes: string[];
};

export type RegistrationSubmissionResult = {
  documentType: string;
  outcome: RegistrationScreeningOutcome;
  front?: RegistrationDocumentSideResult;
  back?: RegistrationDocumentSideResult;
  biodata?: RegistrationDocumentSideResult;
  duplicateKind: RegistrationDuplicateKind;
  failureSide?: RegistrationDocumentSide;
  message?: string;
};

export type RegistrationDocumentRejectionReason =
  | 'unsupported_document'
  | 'document_type_mismatch'
  | 'insufficient_document_evidence'
  | 'duplicate_sides';

export interface RegistrationDocumentRejection {
  reason: RegistrationDocumentRejectionReason;
  message: string;
}

const DOCUMENT_TYPES = ['national_id', 'drivers_license', 'passport', 'umid'] as const;

const OBVIOUS_NON_DOCUMENT_SIGNALS = [
  'receipt',
  'invoice',
  'subtotal',
  'total amount',
  'thank you for your purchase',
  'order number',
  'novelty',
  'specimen',
  'not a real id',
  'fictional',
  'mockup',
  'meme',
  'cartoon',
  'screenshot',
  'website',
  'bikini bottom',
  'spongebob',
  'selfie',
  'room render',
  'store render',
  'may be accessed via',
  'for illustration purposes',
  'this guide shows',
  'document features',
  'is made of polycarbonate',
  'qr code that may be scanned',
];

const FOREIGN_DRIVER_SIGNALS = [
  'hawaii',
  'california',
  'texas',
  'new york dmv',
  'united states',
  'usa',
  'australia',
  'canada',
  'united kingdom',
];

const DIGITAL_NATIONAL_ID_SIGNALS = [
  'digital id',
  'digital national id',
  'digital id number',
  'show front id',
  'show back id',
  'enlarge qr',
  'egovph',
];

const NATIONAL_ID_DATE_VALUE = /\b(?:0?[1-9]|[12]\d|3[01])[./-](?:0?[1-9]|1[0-2])[./-](?:19|20)?\d{2}\b|\b(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+(?:0?[1-9]|[12]\d|3[01])(?:,|\s)\s*(?:19|20)\d{2}\b/i;
const NATIONAL_ID_PCN_VALUE = /\b(?:\d{4}[-\s]?){3}\d{4}\b|\b\d{16}\b/i;
const NATIONAL_ID_ADDRESS_VALUE = /\b(?:barangay|brgy|street|st\.?|block|blk|lot|city|municipality|province|ncr|metro manila)\b/i;

const normalize = (value: string): string => value
  .toLowerCase()
  .replace(/[’']/g, '')
  .split('\n')
  .map(line => line
    .replace(/[^\p{L}\p{N}<>/.\-]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim())
  .filter(Boolean)
  .join('\n');

const containsPhrase = (text: string, phrase: string): boolean => {
  const normalizedPhrase = normalize(phrase);
  if (!normalizedPhrase) return false;
  const collapsedText = text.replace(/\s+/g, ' ');

  return new RegExp(
    `(?<![\\p{L}\\p{N}])${normalizedPhrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\p{L}\\p{N}])`,
    'u',
  ).test(collapsedText);
};

const hasAny = (text: string, signals: string[]): boolean => signals.some(signal => containsPhrase(text, signal));

const hasDigitalNationalIdMarker = (text: string): boolean => hasAny(text, DIGITAL_NATIONAL_ID_SIGNALS);

const hasAllGroups = (text: string, groups: string[][]): boolean => groups.every(group => hasAny(text, group));

const countMatchingGroups = (text: string, groups: string[][]): number => (
  groups.filter(group => hasAny(text, group)).length
);

const hasSupportingField = (text: string, patterns: RegExp[]): boolean => patterns.some(pattern => pattern.test(text));

const hasMrz = (text: string): boolean => {
  const lines = text.split('\n').map(line => line.replace(/\s/g, ''));
  const mrzLines = lines.filter(line => line.length >= 25 && /[<A-Z0-9]/.test(line));

  return mrzLines.length >= 2 && mrzLines.some(line => line.startsWith('p<') || line.includes('phl'));
};

const confidenceBand = (confidence: number): RegistrationConfidenceBand => {
  if (!Number.isFinite(confidence) || confidence <= 0) return 'low';
  if (confidence >= 0.85) return 'high';
  if (confidence >= 0.65) return 'medium';
  return 'low';
};

const familyFromText = (text: string): string | null => {
  if (hasAny(text, [
    'philippine national id',
    'philippine identification',
    'pambansang pagkakakilanlan',
    'national id',
    'digital id number',
    'ephilid',
    'ephil id',
  ])) return 'national_id';
  if (hasAny(text, ['passport', 'pasaporte']) || hasMrz(text)) return 'passport';
  if (hasAny(text, [
    'philsys',
    'philippine national id',
    'philippine identification',
    'philippine identification system',
    'pambansang pagkakakilanlan',
    'national id',
    'digital national id',
    'ephilid',
    'ephil id',
    'philippine statistics authority',
    'psa',
    'republica ng pilipinas',
  ])) return 'national_id';
  const hasUmidTitle = hasAny(text, [
    'umid',
    'unified multipurpose identification',
    'unified multi purpose identification',
    'unified multi-purpose identification',
  ]);
  const hasUmidReference = hasAny(text, ['crn', 'common reference number'])
    && hasAny(text, ['sss', 'gsis', 'philhealth', 'pag-ibig']);

  if (hasUmidTitle || hasUmidReference) return 'umid';
  if (hasAny(text, ['driver license', 'drivers license', 'land transportation office', 'lto'])) return 'drivers_license';

  return null;
};

const rejectionMessage = (side: RegistrationDocumentSide, reason: RegistrationDocumentRejectionReason): string => {
  if (reason === 'duplicate_sides') {
    return 'The front and back images appear to be the same. Please upload the back side of your ID.';
  }

  if (side === 'back') {
    return 'The back image does not appear to match the selected ID. Please upload the back side of your valid ID.';
  }

  return 'This image does not appear to match the selected ID type. Please upload a clear image of your valid Philippine ID.';
};

const sideResult = (
  side: RegistrationDocumentSide,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
  outcome: RegistrationSideOutcome,
  family: string | null,
  anchors: string[],
  notes: string[],
): RegistrationDocumentSideResult => ({
  side,
  outcome,
  ocrText: ocr.text,
  ocrConfidence: ocr.confidence,
  detectedDocumentFamily: family,
  detectedAnchorKeys: anchors,
  confidenceBand: confidenceBand(ocr.confidence),
  qrDetected: ocr.qrDetected === true,
  fingerprint: fingerprint?.exact ?? null,
  imageFingerprint: fingerprint,
  validationNotes: notes,
});

const rejectSide = (
  side: RegistrationDocumentSide,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
  note: string,
  family: string | null = familyFromText(normalize(ocr.text)),
): RegistrationDocumentSideResult => sideResult(
  side,
  ocr,
  fingerprint,
  'reject_upload',
  family,
  [],
  [note],
);

const uncertainSide = (
  side: RegistrationDocumentSide,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
  note: string,
  family: string | null = familyFromText(normalize(ocr.text)),
  anchors: string[] = [],
): RegistrationDocumentSideResult => sideResult(
  side,
  ocr,
  fingerprint,
  'uncertain',
  family,
  anchors,
  [note],
);

const classifyDriverFront = (
  side: RegistrationDocumentSide,
  text: string,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
): RegistrationDocumentSideResult => {
  const issuer = hasAny(text, ['land transportation office', 'lto']);
  const document = hasAny(text, ['driver license', 'drivers license']);
  const philippine = hasAny(text, ['republic of the philippines', 'philippines']);
  const fields = [
    /\bname\b/i,
    /\b(?:date of birth|dob)\b/i,
    /\b(?:license|licence)\s*(?:no|number|#)\b/i,
    /\b(?:exp|expiry|expiration|valid until)\b/i,
    /\baddress\b/i,
  ].filter(pattern => pattern.test(text)).length;

  if (hasAny(text, FOREIGN_DRIVER_SIGNALS)) return rejectSide(side, ocr, fingerprint, 'foreign_document');
  if (!issuer || !document || !philippine) {
    if (document || (fields >= 2 && (issuer || philippine))) {
      return uncertainSide(side, ocr, fingerprint, 'weak_driver_anchors', 'drivers_license');
    }

    return rejectSide(side, ocr, fingerprint, 'missing_philippine_driver_anchors', 'drivers_license');
  }
  if (fields < 2) return uncertainSide(side, ocr, fingerprint, 'weak_driver_identity_fields', 'drivers_license');

  return sideResult(side, ocr, fingerprint, 'plausible', 'drivers_license', [
    'lto_issuer',
    'driver_license',
    'philippine_issuer',
    'identity_fields',
  ], []);
};

const classifyNationalFront = (
  side: RegistrationDocumentSide,
  text: string,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
  nationalIdFormat: RegistrationNationalIdFormat,
): RegistrationDocumentSideResult => {
  const hasDigitalMarker = hasDigitalNationalIdMarker(text);
  const hasDigitalFormatEvidence = hasDigitalMarker || ocr.qrDetected;

  if (nationalIdFormat !== 'digital_image' && hasDigitalMarker) {
    return rejectSide(side, ocr, fingerprint, 'digital_national_id_in_physical_mode', 'national_id');
  }

  const groups = [
    [
      'philippine identification card',
      'philippine identification system',
      'pambansang pagkakakilanlan',
      'national id',
      'digital national id',
      'ephilid',
      'ephil id',
    ],
    [
      'philsys',
      'republic of the philippines',
      'republika ng pilipinas',
      'republica ng pilipinas',
      'philippine statistics authority',
      'psa',
    ],
  ];
  const fields = nationalIdFormat === 'digital_image'
    ? [
        hasSupportingField(text, [
          /\b(?:name|full name|last name|given names?)\b/i,
          /\b(?:apelyido|mga pangalan|pangalan)\b/i,
        ]),
        hasSupportingField(text, [
          /\b(?:date of birth|dob|birth date)\b/i,
          /\b(?:petsa|araw) ng kapanganakan\b/i,
          NATIONAL_ID_DATE_VALUE,
        ]),
        hasSupportingField(text, [
          /\b(?:pcn|personal card number|(?:philsys\s+)?card\s*(?:no|number|#)|id(?:entification)?\s*(?:no|number|#))\b/i,
          NATIONAL_ID_PCN_VALUE,
        ]),
        hasSupportingField(text, [
          /\b(?:address|present address|tirahan)\b/i,
          NATIONAL_ID_ADDRESS_VALUE,
        ]),
      ].filter(Boolean).length
    : [
        /\bname\b/i,
        /\b(?:date of birth|dob)\b/i,
        /\b(?:pcn|(?:philsys\s+)?card\s*(?:no|number|#)|id(?:entification)?\s*(?:no|number|#))\b/i,
        /\baddress\b/i,
      ].filter(pattern => pattern.test(text)).length;

  const matchingAnchorGroups = countMatchingGroups(text, groups);
  const hasPhilippineIssuer = hasAny(text, groups[1]);
  const hasNationalIdNumberEvidence = hasSupportingField(text, [
    /\b(?:pcn|personal card number|(?:philsys\s+)?card\s*(?:no|number|#)|id(?:entification)?\s*(?:no|number|#))\b/i,
    NATIONAL_ID_PCN_VALUE,
  ]);
  const hasStrongAnchorEvidence = matchingAnchorGroups >= 2;
  const hasQrBackedAnchorEvidence = hasPhilippineIssuer && ocr.qrDetected && fields >= 2;
  const hasFieldBackedAnchorEvidence = hasPhilippineIssuer && hasNationalIdNumberEvidence && fields >= 3;
  const hasNationalIdSpecificEvidence = hasAny(text, groups[0])
    || hasNationalIdNumberEvidence
    || fields >= 2
    || hasDigitalMarker
    || ocr.qrDetected;
  const detectedFamily = familyFromText(text);

  if (detectedFamily && detectedFamily !== 'national_id' && !hasNationalIdSpecificEvidence) {
    return rejectSide(side, ocr, fingerprint, 'document_type_mismatch', detectedFamily);
  }

  if (!hasStrongAnchorEvidence && !hasQrBackedAnchorEvidence && !hasFieldBackedAnchorEvidence) {
    if (hasNationalIdSpecificEvidence) {
      return uncertainSide(side, ocr, fingerprint, 'weak_philsys_anchors', 'national_id');
    }

    return rejectSide(side, ocr, fingerprint, 'missing_philsys_anchors', 'national_id');
  }
  if (fields < 2) return uncertainSide(side, ocr, fingerprint, 'weak_national_id_fields', 'national_id');

  return sideResult(side, ocr, fingerprint, 'plausible', 'national_id', [
    'philsys_document',
    'philippine_issuer',
    'identity_fields',
    ...(hasDigitalFormatEvidence ? ['digital_document'] : []),
    ...(ocr.qrDetected ? ['qr_supplemental'] : []),
  ], []);
};

const classifyUmidFront = (
  side: RegistrationDocumentSide,
  text: string,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
): RegistrationDocumentSideResult => {
  const document = hasAny(text, [
    'umid',
    'unified multipurpose identification',
    'unified multi purpose identification',
    'unified multi-purpose identification',
    'crn',
    'common reference number',
  ]);
  const issuer = hasAny(text, [
    'sss',
    'gsis',
    'philhealth',
    'pag ibig',
    'pag-ibig',
    'home development mutual fund',
    'republic of the philippines',
    'republika ng pilipinas',
  ]);
  const fields = [
    /\b(?:name|surname|given name|first name|last name)\b/i,
    /\b(?:date of birth|dob)\b/i,
    /\b(?:crn|common reference number|id(?:entification)?\s*(?:no|number|#))\b/i,
    /\b(?:address|tirahan)\b/i,
  ].filter(pattern => pattern.test(text)).length;

  if (!document || !issuer) {
    if (document || issuer || fields >= 2) {
      return uncertainSide(side, ocr, fingerprint, 'weak_umid_anchors', 'umid');
    }

    return rejectSide(side, ocr, fingerprint, 'missing_umid_anchors', 'umid');
  }
  if (fields < 1) return uncertainSide(side, ocr, fingerprint, 'weak_umid_identity_fields', 'umid');

  return sideResult(side, ocr, fingerprint, 'plausible', 'umid', [
    'umid_document',
    'philippine_issuer',
    'identity_fields',
  ], []);
};

const classifyCardBack = (
  documentType: 'national_id' | 'drivers_license',
  side: RegistrationDocumentSide,
  text: string,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
  nationalIdFormat: RegistrationNationalIdFormat = 'physical_card',
): RegistrationDocumentSideResult => {
  if (documentType === 'drivers_license') {
    if (hasAny(text, FOREIGN_DRIVER_SIGNALS)) return rejectSide(side, ocr, fingerprint, 'foreign_document', 'drivers_license');

    if (!hasAny(text, ['dl code', 'dl codes', 'driver license codes', 'drivers license codes', 'driving conditions', 'condition', 'conditions', 'restriction', 'restrictions'])) {
      if (hasAny(text, ['driver', 'license', 'lto', 'code', 'condition', 'restriction'])) {
        return uncertainSide(side, ocr, fingerprint, 'weak_driver_back_structure', familyFromText(text));
      }

      return rejectSide(side, ocr, fingerprint, 'missing_driver_back_structure', familyFromText(text));
    }

    return sideResult(side, ocr, fingerprint, 'plausible', 'drivers_license', ['driver_back_structure'], []);
  }

  if (documentType === 'national_id') {
    if (nationalIdFormat !== 'digital_image' && hasDigitalNationalIdMarker(text)) {
      return rejectSide(side, ocr, fingerprint, 'digital_national_id_in_physical_mode', 'national_id');
    }

    const nationalBackFieldGroups = [
      ['sex', 'gender', 'kasarian'],
      ['blood type', 'blood group', 'uri ng dugo'],
      ['marital status', 'civil status', 'kalagayang sibil'],
      ['place of birth', 'birthplace', 'lugar ng kapanganakan'],
    ];
    const nationalBackFieldCount = countMatchingGroups(text, nationalBackFieldGroups);
    const philsysAnchor = hasAny(text, [
      'philsys',
      'philippine identification',
      'pambansang pagkakakilanlan',
      'philippine statistics authority',
      'psa',
      'republic of the philippines',
      'republika ng pilipinas',
      'republica ng pilipinas',
    ]) || nationalBackFieldCount >= 3;
    const secondaryStructure = hasAny(text, [
      'pcn',
      'personal card number',
      'document number',
      'qr',
      'digital signature',
      'psa.gov.ph',
      'psa office',
      'card serial number',
    ]) || nationalBackFieldCount >= 2;
    const nationalBackCandidate = philsysAnchor
      || nationalBackFieldCount > 0
      || hasAny(text, ['national id', 'philsys', 'psa', 'philippine identification']);

    if (!philsysAnchor) {
      if (nationalBackCandidate) {
        return uncertainSide(side, ocr, fingerprint, 'weak_philsys_back_anchor', 'national_id');
      }

      return rejectSide(side, ocr, fingerprint, 'missing_philsys_back_structure', familyFromText(text));
    }
    if (!secondaryStructure && !ocr.qrDetected) {
      return uncertainSide(side, ocr, fingerprint, 'weak_philsys_back_structure', 'national_id');
    }

    return sideResult(side, ocr, fingerprint, 'plausible', 'national_id', [
      'philsys_back_structure',
      ...(ocr.qrDetected ? ['qr_supplemental'] : []),
    ], []);
  }

  return rejectSide(side, ocr, fingerprint, 'invalid_card_side', familyFromText(text));
};

const classifyPassport = (
  side: RegistrationDocumentSide,
  text: string,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null,
): RegistrationDocumentSideResult => {
  const passport = hasAny(text, ['passport', 'pasaporte']);
  const philippine = hasAny(text, ['republic of the philippines', 'republika ng pilipinas', 'phl'])
    || text.includes('phl');

  if (!passport || !philippine) {
    if (passport || philippine || hasMrz(text)) {
      return uncertainSide(side, ocr, fingerprint, 'weak_passport_anchors', 'passport');
    }

    return rejectSide(side, ocr, fingerprint, 'missing_philippine_passport_anchors', 'passport');
  }
  if (!hasMrz(text)) return uncertainSide(side, ocr, fingerprint, 'weak_mrz_structure', 'passport');

  return sideResult(side, ocr, fingerprint, 'plausible', 'passport', [
    'passport_document',
    'philippine_issuer',
    'mrz_structure',
  ], []);
};

export const screenRegistrationDocumentSide = (
  documentType: string,
  side: RegistrationDocumentSide,
  ocr: RegistrationOcrResult,
  fingerprint: RegistrationImageFingerprint | null = null,
  nationalIdFormat: RegistrationNationalIdFormat = 'physical_card',
): RegistrationDocumentSideResult => {
  const text = normalize(ocr.text);

  if (!DOCUMENT_TYPES.includes(documentType as typeof DOCUMENT_TYPES[number])) {
    return rejectSide(side, ocr, fingerprint, 'unsupported_document', null);
  }

  const allowDigitalNationalIdScreenshot = documentType === 'national_id' && nationalIdFormat === 'digital_image';
  const nonDocumentSignals = allowDigitalNationalIdScreenshot
    ? OBVIOUS_NON_DOCUMENT_SIGNALS.filter(signal => signal !== 'screenshot')
    : OBVIOUS_NON_DOCUMENT_SIGNALS;

  if (hasAny(text, nonDocumentSignals)) {
    return rejectSide(side, ocr, fingerprint, 'obvious_non_document', familyFromText(text));
  }

  if (!text) return rejectSide(side, ocr, fingerprint, 'no_document_evidence', null);

  const detectedFamily = familyFromText(text);
  let result: RegistrationDocumentSideResult;

  if (documentType === 'passport') {
    result = side === 'biodata'
      ? classifyPassport(side, text, ocr, fingerprint)
      : rejectSide(side, ocr, fingerprint, 'passport_has_no_back_side', 'passport');
  } else if (side === 'front') {
    if (documentType === 'drivers_license') result = classifyDriverFront(side, text, ocr, fingerprint);
    else if (documentType === 'national_id') result = classifyNationalFront(side, text, ocr, fingerprint, nationalIdFormat);
    else result = classifyUmidFront(side, text, ocr, fingerprint);
  } else if (side !== 'back') {
    result = rejectSide(side, ocr, fingerprint, 'invalid_card_side', detectedFamily);
  } else if (documentType === 'umid') {
    result = rejectSide(side, ocr, fingerprint, 'umid_requires_front_only', 'umid');
  } else {
    result = classifyCardBack(
      documentType as 'national_id' | 'drivers_license',
      side,
      text,
      ocr,
      fingerprint,
      nationalIdFormat,
    );
  }

  if ((result.outcome === 'plausible' || result.outcome === 'uncertain')
    && detectedFamily
    && detectedFamily !== documentType) {
    return {
      ...result,
      outcome: 'uncertain',
      validationNotes: [...result.validationNotes, 'document_family_conflict'],
    };
  }

  return result;
};

export async function screenRegistrationDocumentSideFromFile(
  documentType: string,
  side: RegistrationDocumentSide,
  file: File,
  onStage?: (stage: 'loading' | 'recognizing') => void,
  nationalIdFormat: RegistrationNationalIdFormat = 'physical_card',
): Promise<RegistrationDocumentSideResult> {
  try {
    const fingerprint = await fingerprintRegistrationImage(file);
    const ocr = await readRegistrationId(file, onStage, {
      includeIdentityTextPasses: (side === 'front'
        && ['national_id', 'umid', 'drivers_license'].includes(documentType))
        || (documentType === 'passport' && side === 'biodata'),
      includeNationalIdBackCrops: documentType === 'national_id' && side === 'back',
    });

    return screenRegistrationDocumentSide(documentType, side, ocr, fingerprint, nationalIdFormat);
  } catch {
    return createRegistrationScreeningError(documentType, side);
  }
}

export const screenRegistrationSubmission = (
  documentType: string,
  sideResults: Partial<Record<RegistrationDocumentSide, RegistrationDocumentSideResult>>,
  duplicateKind: RegistrationDuplicateKind = 'none',
  nationalIdFormat: RegistrationNationalIdFormat = 'physical_card',
  nameMatchResult: RegistrationNameMatchOutcome = 'matched',
): RegistrationSubmissionResult => {
  const requiredSides: RegistrationDocumentSide[] = documentType === 'passport'
    ? ['biodata']
    : documentType === 'umid'
      ? ['front']
      : ['front', 'back'];
  const hasUnexpectedSide = Object.keys(sideResults)
    .some(side => !requiredSides.includes(side as RegistrationDocumentSide));

  if (hasUnexpectedSide) {
    return {
      documentType,
      outcome: 'reject_upload',
      duplicateKind,
      message: documentType === 'passport'
        ? 'Passport requires the biodata page only.'
        : 'Please upload only the required sides of the selected ID.',
    };
  }

  if (duplicateKind !== 'none') {
    return {
      documentType,
      outcome: 'reject_upload',
      duplicateKind,
      failureSide: 'back',
      message: rejectionMessage('back', 'duplicate_sides'),
    };
  }

  for (const side of requiredSides) {
    const result = sideResults[side];
    if (!result) {
      return {
        documentType,
        outcome: 'reject_upload',
        duplicateKind,
        failureSide: side,
        message: rejectionMessage(side, 'insufficient_document_evidence'),
      };
    }

    if (result.outcome === 'screening_error') {
      return {
        documentType,
        outcome: 'screening_error',
        duplicateKind,
        failureSide: side,
        message: 'We couldn\'t check this image right now. Please try again or select another image.',
      };
    }

    if (result.outcome === 'reject_upload') {
      return {
        documentType,
        outcome: 'reject_upload',
        duplicateKind,
        failureSide: side,
        message: rejectionMessage(side, 'insufficient_document_evidence'),
      };
    }

    if (result.outcome !== 'plausible' && result.outcome !== 'uncertain') {
      return {
        documentType,
        outcome: 'reject_upload',
        duplicateKind,
        failureSide: side,
        message: rejectionMessage(side, 'insufficient_document_evidence'),
      };
    }
  }

  const families = requiredSides
    .map(side => sideResults[side]?.detectedDocumentFamily)
    .filter((family): family is string => typeof family === 'string');

  const hasFamilyConflict = families.some(family => family !== documentType) || new Set(families).size > 1;
  const attentionSide = requiredSides.find(side => {
    const result = sideResults[side];

    return result?.outcome === 'uncertain'
      || result?.confidenceBand === 'low'
      || result?.validationNotes.includes('document_family_conflict');
  });

  const needsManualReview = nameMatchResult !== 'matched'
    || hasFamilyConflict
    || requiredSides.some(side => sideResults[side]?.outcome === 'uncertain')
    || requiredSides.some(side => sideResults[side]?.confidenceBand === 'low');

  if (needsManualReview) {
    return {
      documentType,
      outcome: 'manual_review_required',
      duplicateKind,
      failureSide: attentionSide ?? (documentType === 'passport' ? 'biodata' : 'front'),
      message: 'Your ID was received and will be reviewed before transaction access is enabled.',
      ...sideResults,
    };
  }

  return {
    documentType,
    outcome: 'screening_passed',
    duplicateKind,
    ...sideResults,
  };
};

export const createRegistrationScreeningError = (
  documentType: string,
  side: RegistrationDocumentSide,
  fingerprint: RegistrationImageFingerprint | null = null,
): RegistrationDocumentSideResult => ({
  side,
  outcome: 'screening_error',
  ocrText: '',
  ocrConfidence: 0,
  detectedDocumentFamily: null,
  detectedAnchorKeys: [],
  confidenceBand: null,
  qrDetected: false,
  fingerprint: fingerprint?.exact ?? null,
  imageFingerprint: fingerprint,
  validationNotes: [`screening_error:${documentType}`],
});

/**
 * Compatibility helper for callers that only need a single-side rejection.
 * It returns null for sides that passed plausibility or are reviewable
 * uncertainty; hard rejections remain non-null.
 */
export const detectRegistrationDocumentRejection = (
  selectedDocumentType: string,
  ocrText: string,
  side: RegistrationDocumentSide = selectedDocumentType === 'passport' ? 'biodata' : 'front',
): RegistrationDocumentRejection | null => {
  const result = screenRegistrationDocumentSide(
    selectedDocumentType,
    side,
    { text: ocrText, confidence: 1, qrDetected: false },
  );

  if (result.outcome === 'plausible' || result.outcome === 'uncertain') return null;

  const reason: RegistrationDocumentRejectionReason = result.validationNotes.includes('document_type_mismatch')
    || result.validationNotes.includes('foreign_document')
    ? 'document_type_mismatch'
    : result.validationNotes.includes('obvious_non_document')
      ? 'unsupported_document'
      : 'insufficient_document_evidence';

  return {
    reason,
    message: rejectionMessage(side, reason),
  };
};
