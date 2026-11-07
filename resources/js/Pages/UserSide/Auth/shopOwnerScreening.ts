export const SHOP_OWNER_OCR_SLOTS = [
  'business_registration',
  'mayors_permit',
  'bir_certificate',
  'valid_id',
] as const;

export type ShopOwnerOcrSlot = typeof SHOP_OWNER_OCR_SLOTS[number];
export type ShopOwnerOcrDocumentType =
  | 'dti_registration'
  | 'sec_registration'
  | 'mayors_permit'
  | 'bir_certificate'
  | 'valid_id';
export type ShopOwnerOcrResult = 'match' | 'mismatch' | 'uncertain';
export type ShopOwnerOcrFamily = ShopOwnerOcrDocumentType | 'national_id' | 'drivers_license' | 'passport' | 'umid' | 'other_document';
export type ShopOwnerOcrCheckKey =
  | 'document_type'
  | 'business_identity'
  | 'proprietor_identity'
  | 'business_address'
  | 'owner_identity'
  | 'dates';
export type ShopOwnerOcrReasonCode =
  | 'wrong_document_type'
  | 'business_identity_conflict'
  | 'owner_identity_conflict'
  | 'proprietor_identity_conflict'
  | 'business_address_conflict'
  | 'date_conflict'
  | 'uncertain_field'
  | 'low_recognition_confidence'
  | 'unsupported_layout'
  | 'ocr_unavailable'
  | 'ocr_failed'
  | 'ocr_timeout'
  | 'scan_unfinished';
export type ShopOwnerOcrCheck = {
  result: ShopOwnerOcrResult;
  confidence: number | null;
  complete_value: boolean;
  field_associated: boolean;
  unambiguous: boolean;
  independent_type_signals: boolean;
};
export type ShopOwnerOcrEntry = {
  assessment_state: 'assessed' | 'unable_to_assess';
  detected_family: ShopOwnerOcrFamily | null;
  relationship?: 'sole_proprietor' | 'corporate' | 'unclear';
  reference_day?: string;
  recognition_confidence?: number;
  checks: Partial<Record<ShopOwnerOcrCheckKey, ShopOwnerOcrCheck>>;
  reason_codes?: ShopOwnerOcrReasonCode[];
};

const requiredChecks: Record<ShopOwnerOcrDocumentType, readonly string[]> = {
  dti_registration: ['document_type', 'business_identity', 'proprietor_identity', 'dates'],
  sec_registration: ['document_type', 'business_identity', 'dates'],
  mayors_permit: ['document_type', 'business_identity', 'business_address', 'dates'],
  bir_certificate: ['document_type', 'business_identity', 'business_address', 'dates'],
  valid_id: ['document_type', 'owner_identity', 'dates'],
};

const families = [
  'dti_registration', 'sec_registration', 'mayors_permit', 'bir_certificate',
  'national_id', 'drivers_license', 'passport', 'umid', 'other_document',
] as const;
const idFamilies = ['national_id', 'drivers_license', 'passport', 'umid'];
const reasonCodes = [
  'wrong_document_type', 'business_identity_conflict', 'owner_identity_conflict',
  'proprietor_identity_conflict', 'business_address_conflict', 'date_conflict',
  'uncertain_field', 'low_recognition_confidence', 'unsupported_layout',
  'ocr_unavailable', 'ocr_failed', 'ocr_timeout', 'scan_unfinished',
] as const;
const allCheckKeys = [
  'document_type', 'business_identity', 'proprietor_identity', 'business_address',
  'owner_identity', 'dates',
] as const;
const entryKeys = [
  'assessment_state', 'detected_family', 'relationship', 'reference_day',
  'recognition_confidence', 'checks', 'reason_codes',
] as const;
const checkKeys = [
  'result', 'confidence', 'complete_value', 'field_associated',
  'unambiguous', 'independent_type_signals',
] as const;

type EntryResult = { score: 0 | 50 | 100 | null; state: 'assessed' | 'unable_to_assess' };
type AggregateResult = {
  score: 0 | 50 | 100 | null;
  state: 'assessed' | 'assessment_incomplete';
  incomplete: boolean;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function hasOnlyKeys(value: Record<string, unknown>, allowed: readonly string[]): boolean {
  return Object.keys(value).every((key) => allowed.includes(key));
}

function isCalendarDay(value: unknown): value is string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00.000Z`);
  return !Number.isNaN(date.valueOf()) && date.toISOString().slice(0, 10) === value;
}

function isConfidence(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 1;
}

export function shopOwnerOcrConfidenceBand(confidence: number): 'high' | 'medium' | 'low' {
  if (!isConfidence(confidence)) throw new Error('Invalid confidence');
  return confidence >= 0.85 ? 'high' : confidence >= 0.65 ? 'medium' : 'low';
}

function assertValidEntry(
  slot: string,
  selectedDocumentType: string,
  raw: unknown,
): asserts raw is Record<string, unknown> {
  if (!SHOP_OWNER_OCR_SLOTS.includes(slot as ShopOwnerOcrSlot)) throw new Error('Invalid slot');
  if (!Object.hasOwn(requiredChecks, selectedDocumentType)) throw new Error('Invalid document type');
  if (slot === 'business_registration') {
    if (selectedDocumentType !== 'dti_registration' && selectedDocumentType !== 'sec_registration') {
      throw new Error('Document type does not match slot');
    }
  } else if (slot !== selectedDocumentType) {
    throw new Error('Document type does not match slot');
  }
  if (!isRecord(raw) || !hasOnlyKeys(raw, entryKeys)) throw new Error('Invalid entry');
  if (raw.assessment_state !== 'assessed' && raw.assessment_state !== 'unable_to_assess') {
    throw new Error('Invalid assessment state');
  }
  if (raw.detected_family !== null && !families.includes(raw.detected_family as typeof families[number])) {
    throw new Error('Invalid detected family');
  }
  if (raw.reference_day !== undefined && !isCalendarDay(raw.reference_day)) throw new Error('Invalid reference day');
  if (raw.recognition_confidence !== undefined && !isConfidence(raw.recognition_confidence)) {
    throw new Error('Invalid recognition confidence');
  }
  if (slot === 'bir_certificate') {
    if (raw.relationship !== undefined && !['sole_proprietor', 'corporate', 'unclear'].includes(String(raw.relationship))) {
      throw new Error('Invalid relationship');
    }
  } else if (raw.relationship !== undefined) {
    throw new Error('Relationship is not applicable');
  }
  if (!isRecord(raw.checks)) throw new Error('Invalid checks');
  const allowedChecks = new Set(requiredChecks[selectedDocumentType as ShopOwnerOcrDocumentType]);
  if (selectedDocumentType === 'bir_certificate' && raw.relationship === 'sole_proprietor') {
    allowedChecks.add('proprietor_identity');
  }
  if (!hasOnlyKeys(raw.checks, [...allowedChecks])) throw new Error('Invalid check key');
  for (const check of Object.values(raw.checks)) {
    if (!isRecord(check) || !hasOnlyKeys(check, checkKeys)) throw new Error('Invalid check');
    if (!['match', 'mismatch', 'uncertain'].includes(String(check.result))) throw new Error('Invalid check result');
    if (check.confidence !== null && !isConfidence(check.confidence)) throw new Error('Invalid check confidence');
    for (const flag of checkKeys.slice(2)) {
      if (typeof check[flag] !== 'boolean') throw new Error('Invalid check flag');
    }
  }
  const reasons = raw.reason_codes === undefined ? [] : raw.reason_codes;
  if (!Array.isArray(reasons) || reasons.length > 8 || new Set(reasons).size !== reasons.length
      || reasons.some((reason) => !reasonCodes.includes(reason as typeof reasonCodes[number]))) {
    throw new Error('Invalid reason codes');
  }
}

function reliable(check: Record<string, unknown>, key: string): boolean {
  return isConfidence(check.confidence) && check.confidence >= 0.85
    && check.complete_value === true
    && check.field_associated === true
    && check.unambiguous === true
    && (key !== 'document_type' || check.independent_type_signals === true);
}

function familyConflicts(selectedDocumentType: string, family: unknown): boolean {
  if (family === null) return false;
  if (selectedDocumentType === 'valid_id') {
    return !idFamilies.includes(String(family));
  }
  return family !== selectedDocumentType;
}

function normalizedCheckResult(
  entry: Record<string, unknown>,
  selectedDocumentType: string,
  key: string,
): ShopOwnerOcrResult {
  const checks = entry.checks as Record<string, Record<string, unknown>>;
  const check = checks[key];
  if (!check || !reliable(check, key)) return 'uncertain';
  if (key === 'dates' && !isCalendarDay(entry.reference_day)) return 'uncertain';
  if (selectedDocumentType === 'bir_certificate' && entry.relationship !== 'sole_proprietor'
      && entry.relationship !== 'corporate' && key === 'business_identity') return 'uncertain';
  if (key === 'document_type') {
    if (entry.detected_family === null) return 'uncertain';
    if (familyConflicts(selectedDocumentType, entry.detected_family)) return 'mismatch';
  }
  return check.result as ShopOwnerOcrResult;
}

export function scoreOwnerOcrEntry(
  slot: string,
  selectedDocumentType: string,
  raw: unknown,
): EntryResult {
  assertValidEntry(slot, selectedDocumentType, raw);
  const required = [...requiredChecks[selectedDocumentType as ShopOwnerOcrDocumentType]];
  if (selectedDocumentType === 'bir_certificate' && raw.relationship === 'sole_proprietor') {
    required.push('proprietor_identity');
  }
  const results = required.map((key) => normalizedCheckResult(raw, selectedDocumentType, key));
  if (raw.assessment_state === 'unable_to_assess') {
    if (results.some((result) => result === 'match' || result === 'mismatch')) {
      throw new Error('Unable-to-assess evidence contains an eligible check');
    }
    return { score: null, state: 'unable_to_assess' };
  }
  if (results.includes('mismatch')) return { score: 0, state: 'assessed' };
  if (results.every((result) => result === 'match')) return { score: 100, state: 'assessed' };
  return { score: 50, state: 'assessed' };
}

export function summarizeOwnerOcrScores(
  scoreMap: Partial<Record<ShopOwnerOcrSlot, number | null>>,
): AggregateResult {
  const scores = SHOP_OWNER_OCR_SLOTS.map((slot) => scoreMap[slot] ?? null);
  if (scores.some((score) => score !== null && score !== 0 && score !== 50 && score !== 100)) {
    throw new Error('Invalid document score');
  }
  const incomplete = scores.some((score) => score === null);
  const score = scores.includes(0) ? 0 : incomplete ? null : Math.min(...scores) as 50 | 100;
  return { score, state: incomplete ? 'assessment_incomplete' : 'assessed', incomplete };
}

export function serializeOwnerOcrEnvelope(
  entries: Partial<Record<ShopOwnerOcrSlot, unknown>>,
  selectedDocumentTypes: Partial<Record<ShopOwnerOcrSlot, string>>,
): string | null {
  try {
    if (!isRecord(entries) || !hasOnlyKeys(entries, SHOP_OWNER_OCR_SLOTS)) return null;
    for (const [slot, entry] of Object.entries(entries)) {
      const selectedType = selectedDocumentTypes[slot as ShopOwnerOcrSlot];
      if (!selectedType) return null;
      assertValidEntry(slot, selectedType, entry);
      scoreOwnerOcrEntry(slot, selectedType, entry);
    }
    const serialized = JSON.stringify(entries);
    if (new TextEncoder().encode(serialized).byteLength > 32768) return null;
    return serialized;
  } catch {
    return null;
  }
}
