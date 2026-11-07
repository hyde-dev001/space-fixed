import { matchRegistrationName } from './registrationNameMatch';
import type { ShopOwnerOcrLine, ShopOwnerOcrPass, ShopOwnerOcrWord } from './registrationOcr';
import type {
  ShopOwnerOcrCheck,
  ShopOwnerOcrCheckKey,
  ShopOwnerOcrDocumentType,
  ShopOwnerOcrEntry,
  ShopOwnerOcrFamily,
  ShopOwnerOcrReasonCode,
  ShopOwnerOcrSlot,
} from './shopOwnerScreening';

export type ShopOwnerOcrContext = {
  firstName: string;
  lastName: string;
  businessName: string;
  businessAddress: string;
  registrationType: string;
  referenceDay: string | null;
  metadata: Partial<Record<ShopOwnerOcrSlot, {
    issuedOn?: string;
    expirationMode: 'dated' | 'none';
    expiresOn?: string | null;
  }>>;
};

type FieldEvidence = {
  value: string;
  confidence: number | null;
  complete_value: boolean;
  field_associated: boolean;
  unambiguous: boolean;
};
type Candidate = FieldEvidence & { key: string };
type FamilySignal = { family: ShopOwnerOcrFamily; groups: number; confidence: number | null };
type FamilyEvidence = Pick<FamilySignal, 'groups' | 'confidence'> & { matchedGroups: number[] };
type DateValue = { day: string } | { ambiguous: true } | null;
export type ShopOwnerOcrRelevance =
  | 'plausible_document'
  | 'clearly_irrelevant'
  | 'incompatible_document_type'
  | 'uncertain_relevance'
  | 'technical_failure';

const labels = [
  /\b(?:registered business name|business\s*\/\s*trade name|registered trade name|trade name(?:\s+\d+)?|business name)\b\s*:?\s*/i,
  /\b(?:legal entity name|registered entity name|entity name|legal name|corporate name|company name)\b\s*:?\s*/i,
  /\b(?:name of proprietor|proprietor(?:'s)? name|proprietor)\b\s*:?\s*/i,
  /\b(?:taxpayer classification|type of taxpayer|taxpayer types?|entity type)\b\s*:?\s*/i,
  /\b(?:name of taxpayer|taxpayer name|registered taxpayer)\b\s*:?\s*/i,
  /\b(?:business premises|place of business|premises address|business address|registered business address|registered address|address)\b\s*:?\s*/i,
  /\b(?:full name|name of holder|cardholder name|holder name|surname|given names?|name)\b\s*:?\s*/i,
  /\b(?:date of issue|date issued|issue date|issued on|issued|date of registration|registration date)\b\s*:?\s*/i,
  /\b(?:date of expiration|expiration date|expiry date|date expires|valid until|valid through|valid thru|date of validity|expires|expiry|expiration)\b\s*:?\s*/i,
  /\b(?:permit reference|permit no\.?|permit number)\b\s*:?\s*/i,
  /\b(?:company reg\.? no\.?|company registration no\.?|registration no\.?|registration number|certificate no\.?|certificate number)\b\s*:?\s*/i,
];

const businessNameLabel = /\b(?:registered business name|business\s*\/\s*trade name|registered trade name|trade name(?:\s+\d+)?|business name)\b\s*:?\s*/i;
const legalNameLabel = /\b(?:legal entity name|registered entity name|entity name|legal name|corporate name|company name)\b\s*:?\s*/i;
const proprietorLabel = /\b(?:name of proprietor|proprietor(?:'s)? name|proprietor)\b\s*:?\s*/i;
const taxpayerNameLabel = /\b(?:name of taxpayer|taxpayer name|registered taxpayer)\b\s*:?\s*/i;
const addressLabel = /\b(?:business premises|place of business|premises address|business address|registered business address|registered address|address)\b\s*:?\s*/i;
const holderNameLabel = /\b(?:full name|name of holder|cardholder name|holder name|name)\b\s*:?\s*/i;
const surnameLabel = /\b(?:surname|last name)\b\s*:?\s*/i;
const givenNameLabel = /\b(?:given names?|first name)\b\s*:?\s*/i;
const issueDateLabel = /\b(?:date of issue|date issued|issue date|issued on|issued|date of registration|registration date)\b\s*:?\s*/i;
const expiryDateLabel = /\b(?:date of expiration|expiration date|expiry date|date expires|valid until|valid through|valid thru|date of validity|expires|expiry|expiration)\b\s*:?\s*/i;
const relationshipLabel = /\b(?:taxpayer classification|type of taxpayer|taxpayer types?|entity type)\b\s*:?\s*/i;
const familyDefinitions: Array<{ family: ShopOwnerOcrFamily; groups: RegExp[] }> = [
  { family: 'dti_registration', groups: [
    /\b(?:department of trade and industry|\bdti\b)/i,
    /\b(?:business name registration|certificate of business name|business name certificate)\b/i,
    /\b(?:registered business name|business name|registration no\.?|registration number|certificate no\.?|certificate number)\b/i,
  ] },
  { family: 'sec_registration', groups: [
    /\b(?:securities and exchange commission|\bsec\b)/i,
    /\b(?:certificate of incorporation|articles of incorporation|corporate registration certificate)\b/i,
    /\b(?:registered entity name|legal entity name|company name|company reg\.? no\.?|company registration no\.?|registration no\.?|registration number|articles of incorporation)\b/i,
  ] },
  { family: 'mayors_permit', groups: [
    /\b(?:city government|municipal government|municipality of|city of|[\p{L} .'-]+\s+city|\blgu\b)/iu,
    /\b(?:mayor'?s permit|business permit|permit to operate)\b/i,
    /\b(?:permit reference|permit no\.?|permit number|business address|business name)\b/i,
  ] },
  { family: 'bir_certificate', groups: [
    /\b(?:bureau of internal revenue|kawanihan ng rentas internas|\bbir\b)/i,
    /\b(?:(?:form\s*)?2303|certificate of registration|BIR COR)\b/i,
    /\b(?:taxpayer classification|name of taxpayer|taxpayer name|business name|trade name|registered address)\b/i,
  ] },
  { family: 'national_id', groups: [
    /\b(?:philippine statistics authority|\bpsa\b|\bphilsys\b)/i,
    /\b(?:philippine identification card|national id|philid|ephilid|ephil id|pambansang pagkakakilanlan)\b/i,
    /\b(?:full name|name of holder|date of birth|nationality|psn)\b/i,
  ] },
  { family: 'drivers_license', groups: [
    /\b(?:land transportation office|\blto\b)/i,
    /\b(?:driver'?s? license|drivers license|driver licence)\b/i,
    /\b(?:license no\.?|license number|date of birth|valid until|expiration date)\b/i,
  ] },
  { family: 'passport', groups: [
    /\b(?:republic of the philippines|republika ng pilipinas|\bphl\b)/i,
    /\b(?:passport|pasaporte)\b/i,
    /\b(?:surname|given names?|passport no\.?|passport number|p<phl)\b/i,
  ] },
  { family: 'umid', groups: [
    /\b(?:sss|gsis|philhealth|pag-ibig)\b/i,
    /\b(?:umid|unified multipurpose identification|unified multi-purpose identification)\b/i,
    /\b(?:crn|common reference number|cardholder name|full name)\b/i,
  ] },
  { family: 'other_document', groups: [
    /\b(?:supplier|seller|billing statement|official receipt|tax invoice)\b/i,
    /\b(?:invoice|receipt|purchase order|utility bill)\b/i,
    /\b(?:invoice no\.?|receipt no\.?|subtotal|total amount|item description)\b/i,
  ] },
];

const confidenceOfWords = (words: readonly ShopOwnerOcrWord[]): number | null => {
  if (words.length === 0 || words.some((word) => word.confidence === null)) return null;
  return Math.min(...words.map((word) => word.confidence as number));
};

const normalizeComparable = (value: string): string => value
  .normalize('NFD')
  .replace(/\p{M}+/gu, '')
  .toLowerCase()
  .replace(/[^\p{L}\p{N}]+/gu, ' ')
  .replace(/\s+/g, ' ')
  .trim();

const hasOcrCharacterAmbiguity = (value: string): boolean => normalizeComparable(value)
  .split(' ')
  .some((token) => /\p{L}/u.test(token) && /[01]/.test(token));

const isCompleteValue = (value: string): boolean => Boolean(value.trim())
  && !/(?:\.\.\.|\u2026|\?{2,}|\b(?:unreadable|illegible)\b)/i.test(value)
  && !/[-\u2010\u2011]$/.test(value.trim());

const validDay = (value: string | null | undefined): value is string => {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const day = new Date(`${value}T00:00:00.000Z`);
  return !Number.isNaN(day.valueOf()) && day.toISOString().slice(0, 10) === value;
};

function lineBoxAssociated(label: ShopOwnerOcrLine['bbox'], value: ShopOwnerOcrLine['bbox']): boolean {
  if (!label || !value) return false;
  const height = Math.max(1, label.y1 - label.y0);
  const verticalGap = value.y0 - label.y1;
  const horizontalGap = Math.max(0, label.x0 - value.x1, value.x0 - label.x1);
  return verticalGap >= -height * 0.25
    && verticalGap <= Math.max(96, height * 4)
    && horizontalGap <= Math.max(48, height * 2);
}

function hasAnyFieldLabel(text: string): boolean {
  return labels.some((label) => label.test(text));
}

function candidateFromLine(lines: ShopOwnerOcrLine[], index: number, label: RegExp): Candidate | null {
  const line = lines[index];
  const match = label.exec(line.text);
  if (!match) return null;
  const prefix = line.text.slice(0, match.index).trim();
  const suffix = line.text.slice(match.index + match[0].length).replace(/^[\s:\uFF1A\u2013\u2014-]+/, '').trim();
  if (prefix && !/[:\uFF1A]\s*$/.test(match[0]) && /^(?:registration|certificate|incorporation|permit)\b/i.test(suffix)) return null;
  let value = suffix;
  let evidenceWords = line.words;
  let associated = line.words.length > (match[0].match(/[\p{L}\p{N}]+/gu)?.length ?? 0);

  if (!suffix) {
    const nextLine = lines[index + 1];
    if (!nextLine || hasAnyFieldLabel(nextLine.text)) return null;
    value = nextLine.text.trim();
    evidenceWords = [...line.words, ...nextLine.words];
    associated = lineBoxAssociated(line.bbox, nextLine.bbox) && nextLine.words.length > 0;
  }

  const confidence = confidenceOfWords(evidenceWords);
  return {
    value,
    key: normalizeComparable(value),
    confidence,
    complete_value: isCompleteValue(value) && confidence !== null,
    field_associated: associated,
    unambiguous: isCompleteValue(value) && !hasOcrCharacterAmbiguity(value),
  };
}

function fieldEvidence(passes: readonly ShopOwnerOcrPass[], label: RegExp): FieldEvidence {
  const candidates = passes.flatMap((pass) => pass.lines.flatMap((line, index) => {
    const candidate = candidateFromLine(pass.lines, index, label);
    return candidate ? [candidate] : [];
  }));
  if (candidates.length === 0) {
    return { value: '', confidence: null, complete_value: false, field_associated: false, unambiguous: false };
  }

  const keys = new Set(candidates.map((candidate) => candidate.key));
  return {
    value: candidates[0].value,
    confidence: candidates.every((candidate) => candidate.confidence !== null)
      ? Math.min(...candidates.map((candidate) => candidate.confidence as number))
      : null,
    complete_value: candidates.every((candidate) => candidate.complete_value),
    field_associated: candidates.every((candidate) => candidate.field_associated),
    unambiguous: keys.size === 1 && candidates.every((candidate) => candidate.unambiguous),
  };
}

function combineNameFields(given: FieldEvidence, surname: FieldEvidence): FieldEvidence {
  if (!given.value || !surname.value) {
    return { value: '', confidence: null, complete_value: false, field_associated: false, unambiguous: false };
  }
  return {
    value: `${given.value} ${surname.value}`,
    confidence: given.confidence === null || surname.confidence === null
      ? null
      : Math.min(given.confidence, surname.confidence),
    complete_value: given.complete_value && surname.complete_value,
    field_associated: given.field_associated && surname.field_associated,
    unambiguous: given.unambiguous && surname.unambiguous,
  };
}

function check(result: ShopOwnerOcrCheck['result'], evidence: FieldEvidence, independentTypeSignals = false): ShopOwnerOcrCheck {
  const reliable = evidence.confidence !== null && evidence.confidence >= 0.85
    && evidence.complete_value && evidence.field_associated && evidence.unambiguous;
  return {
    result: reliable ? result : 'uncertain',
    confidence: evidence.confidence,
    complete_value: evidence.complete_value,
    field_associated: evidence.field_associated,
    unambiguous: evidence.unambiguous,
    independent_type_signals: independentTypeSignals,
  };
}

function matchingSignals(lines: readonly ShopOwnerOcrLine[], pattern: RegExp) {
  const signals: Array<{ lines: ShopOwnerOcrLine[]; confidence: number }> = [];
  lines.forEach((line, index) => {
    const adjacent = [line];
    for (let end = index; end < Math.min(index + 3, lines.length); end++) {
      if (end > index) {
        if (!lineBoxAssociated(lines[end - 1].bbox, lines[end].bbox)) break;
        adjacent.push(lines[end]);
      }
      const words = adjacent.flatMap(item => item.words);
      const match = pattern.exec(words.map(word => word.text).join(' '));
      if (!match) continue;
      let offset = 0;
      const matched = words.filter(word => {
        const start = offset;
        offset += word.text.length + 1;
        return start < match.index + match[0].length && start + word.text.length > match.index;
      });
      const confidence = confidenceOfWords(matched);
      if (confidence !== null) {
        signals.push({
          lines: adjacent.filter(item => item.words.some(word => matched.includes(word))),
          confidence,
        });
      }
      break;
    }
  });
  return signals.sort((left, right) => right.confidence - left.confidence);
}

function signalForPass(pass: ShopOwnerOcrPass, groups: readonly RegExp[]): FamilyEvidence {
  const usedLines = new Set<ShopOwnerOcrLine>();
  const confidences: number[] = [];
  const matchedGroups: number[] = [];
  for (const [index, group] of groups.entries()) {
    const signal = matchingSignals(pass.lines, group).find(item => item.lines.every(line => !usedLines.has(line)));
    if (!signal) continue;
    signal.lines.forEach(line => usedLines.add(line));
    confidences.push(signal.confidence);
    matchedGroups.push(index);
  }
  return {
    groups: confidences.length,
    matchedGroups,
    confidence: confidences.length > 0 ? Math.min(...confidences) : null,
  };
}

function detectFamily(passes: readonly ShopOwnerOcrPass[]): {
  family: ShopOwnerOcrFamily | null;
  confidence: number | null;
  independent: boolean;
  unambiguous: boolean;
} {
  const signals = passes.flatMap((pass) => familyDefinitions.map((definition) => ({
    ...signalForPass(pass, definition.groups),
    family: definition.family,
  })));
  const strong = signals.filter((signal) => signal.groups === 3 && signal.confidence !== null && signal.confidence >= 0.85);
  const families = [...new Set(strong.map((signal) => signal.family))];
  const family = families.length === 1 ? families[0] : null;
  // Shared city/field labels cannot compete without that family's category heading.
  const competing = family !== null && signals.some((signal) => signal.family !== family
    && signal.groups >= 2 && signal.matchedGroups.includes(1)
    && signal.confidence !== null && signal.confidence >= 0.65);
  const unambiguous = family !== null && !competing;
  const matching = strong.filter((signal) => signal.family === family);
  const confidence = matching.length > 0 ? Math.min(...matching.map((signal) => signal.confidence as number)) : null;
  return { family: unambiguous ? family : null, confidence, independent: strong.length > 0, unambiguous };
}

function hasPlausibleGovernmentDocumentStructure(passes: readonly ShopOwnerOcrPass[]): boolean {
  const issuer = /\b(?:republic of the philippines|republika ng pilipinas|department|bureau|commission|government|municipality|\blgu\b|kawanihan ng rentas internas)\b/i;
  const heading = /\b(?:certificate|permit|registration|identification|identity card|license|licence|form\s*\d+|affidavit)\b/i;
  const structure = new RegExp([...labels.map(label => label.source), '\\b(?:reference|number|no\\.?|date issued|date expires|valid until)\\b'].join('|'), 'i');
  return passes.some(pass => [[issuer, heading], [heading, structure, structure], [issuer, structure, structure]]
    .some(groups => {
      const evidence = signalForPass(pass, groups);
      return evidence.groups === groups.length && evidence.confidence !== null && evidence.confidence >= 0.65;
    }));
}

export function classifyShopOwnerDocumentRelevance(
  selectedType: ShopOwnerOcrDocumentType,
  passes: readonly ShopOwnerOcrPass[],
): Exclude<ShopOwnerOcrRelevance, 'technical_failure'> {
  const detected = detectFamily(passes);
  const selectedId = selectedType === 'valid_id';
  const detectedId = detected.family === 'national_id' || detected.family === 'drivers_license'
    || detected.family === 'passport' || detected.family === 'umid';

  if (detected.family) {
    return (selectedId ? detectedId : detected.family === selectedType)
      ? 'plausible_document'
      : 'incompatible_document_type';
  }

  if (hasPlausibleGovernmentDocumentStructure(passes)) return 'uncertain_relevance';
  // A completed read needs positive document evidence; pixel texture cannot supply it.
  return 'clearly_irrelevant';
}

function businessNameCheck(
  type: ShopOwnerOcrDocumentType,
  relationship: 'sole_proprietor' | 'corporate' | 'unclear' | undefined,
  passes: readonly ShopOwnerOcrPass[],
  context: ShopOwnerOcrContext,
): ShopOwnerOcrCheck {
  const business = fieldEvidence(passes, businessNameLabel);
  let candidate = business;
  let roleIsEquivalent = true;

  if (type === 'sec_registration' && !business.value) {
    candidate = fieldEvidence(passes, legalNameLabel);
    roleIsEquivalent = false;
  }
  if (type === 'bir_certificate' && !business.value && relationship === 'corporate') {
    candidate = fieldEvidence(passes, legalNameLabel);
    roleIsEquivalent = false;
  }
  if (!candidate.value || (type === 'bir_certificate' && relationship === 'unclear')) {
    return check('uncertain', candidate);
  }

  const same = normalizeComparable(candidate.value) === normalizeComparable(context.businessName);
  if (same) return check('match', candidate);
  return check(roleIsEquivalent ? 'mismatch' : 'uncertain', candidate);
}

function personalNameResult(value: string, context: ShopOwnerOcrContext): ShopOwnerOcrCheck['result'] {
  const expectedTokens = normalizeComparable(`${context.firstName} ${context.lastName}`).split(' ').filter(Boolean);
  const actualTokens = normalizeComparable(value).split(' ').filter(Boolean);
  if (actualTokens.length < Math.max(2, expectedTokens.length)) return 'uncertain';
  if (matchRegistrationName(context.firstName, context.lastName, value) === 'matched') return 'match';
  return actualTokens.length >= expectedTokens.length ? 'mismatch' : 'uncertain';
}

function completeAddress(value: string): boolean {
  const tokens = normalizeComparable(value).split(' ').filter(Boolean);
  const hasLocality = tokens.some((token) => ['barangay', 'city', 'municipality', 'province'].includes(token));
  return tokens.length >= 3 && (hasLocality || /\d/.test(value));
}

function addressCheck(passes: readonly ShopOwnerOcrPass[], context: ShopOwnerOcrContext): ShopOwnerOcrCheck {
  const evidence = fieldEvidence(passes, addressLabel);
  if (!evidence.value || !completeAddress(evidence.value) || !completeAddress(context.businessAddress)) {
    return check('uncertain', { ...evidence, complete_value: false });
  }
  const normalizeAddress = (value: string) => normalizeComparable(value)
    .replace(/\bbrgy\b/g, 'barangay')
    .replace(/\bblk\b/g, 'block');
  const actual = normalizeAddress(evidence.value);
  const expected = normalizeAddress(context.businessAddress);
  if (actual !== expected && actual.split(' ').every((token) => expected.split(' ').includes(token))) {
    return check('uncertain', { ...evidence, complete_value: false });
  }
  return check(actual === expected ? 'match' : 'mismatch', evidence);
}

function parsedDate(value: string): DateValue {
  const normalized = value.trim().replace(/[.,]$/, '').replace(/\s+/g, ' ');
  const toDay = (year: number, month: number, day: number): DateValue => {
    const date = new Date(Date.UTC(year, month - 1, day));
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
    return { day: `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}` };
  };

  const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(normalized);
  if (iso) return toDay(Number(iso[1]), Number(iso[2]), Number(iso[3]));
  const monthNames = 'january february march april may june july august september october november december'.split(' ');
  const monthIndex = (value: string) => monthNames.findIndex((month) => month.startsWith(value.toLowerCase().slice(0, 3))) + 1;
  const monthFirst = /^(\w+)\s+(\d{1,2}),?\s+(\d{4})$/i.exec(normalized);
  if (monthFirst) {
    const month = monthIndex(monthFirst[1]);
    if (month) return toDay(Number(monthFirst[3]), month, Number(monthFirst[2]));
  }
  const dayFirst = /^(\d{1,2})\s+(\w+)\s+(\d{4})$/i.exec(normalized);
  if (dayFirst) {
    const month = monthIndex(dayFirst[2]);
    if (month) return toDay(Number(dayFirst[3]), month, Number(dayFirst[1]));
  }

  const yearFirst = /^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$/.exec(normalized);
  if (yearFirst) return toDay(Number(yearFirst[1]), Number(yearFirst[2]), Number(yearFirst[3]));
  const numeric = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/.exec(normalized);
  if (!numeric) return null;
  const first = Number(numeric[1]);
  const second = Number(numeric[2]);
  if (first <= 12 && second <= 12) return { ambiguous: true };
  if (first > 12 && second <= 12) return toDay(Number(numeric[3]), second, first);
  if (second > 12 && first <= 12) return toDay(Number(numeric[3]), first, second);
  return null;
}

function aggregateDateEvidence(evidence: FieldEvidence[]): FieldEvidence {
  if (evidence.length === 0) return { value: '', confidence: null, complete_value: false, field_associated: false, unambiguous: false };
  return {
    value: evidence.map((item) => item.value).join(' '),
    confidence: evidence.every((item) => item.confidence !== null)
      ? Math.min(...evidence.map((item) => item.confidence as number))
      : null,
    complete_value: evidence.every((item) => item.complete_value),
    field_associated: evidence.every((item) => item.field_associated),
    unambiguous: evidence.every((item) => item.unambiguous),
  };
}

function datesCheck(
  slot: ShopOwnerOcrSlot,
  type: ShopOwnerOcrDocumentType,
  family: ShopOwnerOcrFamily | null,
  typeConfidence: number | null,
  typeUnambiguous: boolean,
  passes: readonly ShopOwnerOcrPass[],
  context: ShopOwnerOcrContext,
): ShopOwnerOcrCheck {
  const referenceDay = context.referenceDay;
  const metadata = context.metadata[slot];
  const weak = { value: '', confidence: null, complete_value: false, field_associated: false, unambiguous: false };
  if (!validDay(referenceDay) || !metadata) return check('uncertain', weak);

  const expiry = fieldEvidence(passes, expiryDateLabel);
  const issue = fieldEvidence(passes, issueDateLabel);
  const expiryDate = expiry.value ? parsedDate(expiry.value) : null;
  const issueDate = issue.value ? parsedDate(issue.value) : null;
  const canUse = (field: FieldEvidence, parsed: DateValue) => field.confidence !== null
    && field.confidence >= 0.85 && field.complete_value && field.field_associated && field.unambiguous
    && parsed !== null && !('ambiguous' in parsed);
  const issueRequired = Boolean(metadata.issuedOn);
  const evidenceFields: FieldEvidence[] = [];
  const mismatchFields: FieldEvidence[] = [];
  let uncertain = false;

  if (metadata.expirationMode === 'dated') {
    if (!validDay(metadata.expiresOn) || !expiry.value || !canUse(expiry, expiryDate)) uncertain = true;
    else {
      evidenceFields.push(expiry);
      const date = (expiryDate as { day: string }).day;
      if (date !== metadata.expiresOn || date < referenceDay) mismatchFields.push(expiry);
    }
  } else if (expiry.value) {
    if (!canUse(expiry, expiryDate)) uncertain = true;
    else {
      evidenceFields.push(expiry);
      if (!validDay(metadata.expiresOn) || (expiryDate as { day: string }).day !== metadata.expiresOn) {
        mismatchFields.push(expiry);
      }
    }
  } else {
    const nationality = type === 'valid_id' && family === 'national_id'
      ? fieldEvidence(passes, /\b(?:nationality|citizenship)\b\s*[:\uFF1A]?\s*/i)
      : null;
    const filipinoCitizen = nationality !== null && nationality.confidence !== null && nationality.confidence >= 0.85
      && nationality.complete_value && nationality.field_associated && nationality.unambiguous
      && /^(?:filipino|philippine citizen)$/.test(normalizeComparable(nationality.value));
    const knownNoExpiry = type === 'bir_certificate' || filipinoCitizen;
    if (knownNoExpiry && typeConfidence !== null && typeConfidence >= 0.85 && typeUnambiguous) {
      // PSA identifies the Filipino National ID as lifetime-valid; do not infer the same for resident-alien IDs.
      evidenceFields.push({
        value: 'known non-expiring document policy',
        confidence: typeConfidence,
        complete_value: true,
        field_associated: true,
        unambiguous: true,
      });
    } else {
      uncertain = true;
    }
  }

  if (issueRequired && !issue.value) uncertain = true;
  if (issue.value) {
    if (!canUse(issue, issueDate)) uncertain = true;
    else {
      evidenceFields.push(issue);
      const day = (issueDate as { day: string }).day;
      if (day > referenceDay || (metadata.issuedOn && day !== metadata.issuedOn)) mismatchFields.push(issue);
    }
  }

  if (expiry.value && issue.value && canUse(expiry, expiryDate) && canUse(issue, issueDate)
    && (issueDate as { day: string }).day > (expiryDate as { day: string }).day) {
    mismatchFields.push(aggregateDateEvidence([issue, expiry]));
  }

  if (mismatchFields.length > 0) return check('mismatch', aggregateDateEvidence(mismatchFields));
  if (uncertain || evidenceFields.length === 0) return check('uncertain', aggregateDateEvidence(evidenceFields));
  return check('match', aggregateDateEvidence(evidenceFields));
}

function relationshipFromBIR(passes: readonly ShopOwnerOcrPass[]): 'sole_proprietor' | 'corporate' | 'unclear' {
  const evidence = fieldEvidence(passes, relationshipLabel);
  if (evidence.confidence === null || evidence.confidence < 0.85 || !evidence.complete_value
    || !evidence.field_associated || !evidence.unambiguous) return 'unclear';
  const value = normalizeComparable(evidence.value);
  if (/\b(?:sole proprietor|sole proprietorship|single proprietorship)\b/.test(value)) return 'sole_proprietor';
  if (/\b(?:non individual|corporation|corporate|partnership|company)\b/.test(value)) return 'corporate';
  return 'unclear';
}

function documentTypeCheck(
  selected: ShopOwnerOcrDocumentType,
  family: ShopOwnerOcrFamily | null,
  confidence: number | null,
  independent: boolean,
  unambiguous: boolean,
): ShopOwnerOcrCheck {
  const evidence: FieldEvidence = {
    value: family ?? '',
    confidence,
    complete_value: family !== null,
    field_associated: family !== null,
    unambiguous,
  };
  const selectedId = selected === 'valid_id';
  const detectedId = family === 'national_id' || family === 'drivers_license' || family === 'passport' || family === 'umid';
  const matches = family !== null && (selectedId ? detectedId : family === selected);
  return check(family === null || !unambiguous ? 'uncertain' : matches ? 'match' : 'mismatch', evidence, independent);
}

function reasonCodesFor(checks: Partial<Record<ShopOwnerOcrCheckKey, ShopOwnerOcrCheck>>, family: ShopOwnerOcrFamily | null): ShopOwnerOcrReasonCode[] {
  const reasons = new Set<ShopOwnerOcrReasonCode>();
  if (checks.document_type?.result === 'mismatch') reasons.add('wrong_document_type');
  if (checks.business_identity?.result === 'mismatch') reasons.add('business_identity_conflict');
  if (checks.owner_identity?.result === 'mismatch') reasons.add('owner_identity_conflict');
  if (checks.proprietor_identity?.result === 'mismatch') reasons.add('proprietor_identity_conflict');
  if (checks.business_address?.result === 'mismatch') reasons.add('business_address_conflict');
  if (checks.dates?.result === 'mismatch') reasons.add('date_conflict');
  if (Object.values(checks).some((item) => item?.result === 'uncertain')) reasons.add('uncertain_field');
  if (Object.values(checks).some((item) => item?.confidence !== null && item?.confidence !== undefined && item.confidence < 0.85)) {
    reasons.add('low_recognition_confidence');
  }
  if (family === null) reasons.add('unsupported_layout');
  return [...reasons].slice(0, 8);
}

export function assessShopOwnerDocument(
  slot: ShopOwnerOcrSlot,
  selectedType: ShopOwnerOcrDocumentType,
  passes: readonly ShopOwnerOcrPass[],
  context: ShopOwnerOcrContext,
): ShopOwnerOcrEntry {
  const hasText = passes.some((pass) => pass.text.trim().length > 0
    || pass.lines.some((line) => line.text.trim().length > 0 || line.words.some((word) => word.text.trim().length > 0)));
  const type = detectFamily(passes);
  const relationship = selectedType === 'bir_certificate' ? relationshipFromBIR(passes) : undefined;
  const checks: Partial<Record<ShopOwnerOcrCheckKey, ShopOwnerOcrCheck>> = {
    document_type: documentTypeCheck(selectedType, type.family, type.confidence, type.independent, type.unambiguous),
    dates: datesCheck(slot, selectedType, type.family, type.confidence, type.unambiguous, passes, context),
  };

  if (selectedType === 'dti_registration') {
    const proprietor = fieldEvidence(passes, proprietorLabel);
    checks.business_identity = businessNameCheck(selectedType, undefined, passes, context);
    checks.proprietor_identity = check(
      type.family === 'dti_registration' ? personalNameResult(proprietor.value, context) : 'uncertain',
      proprietor,
    );
  } else if (selectedType === 'sec_registration') {
    checks.business_identity = businessNameCheck(selectedType, undefined, passes, context);
  } else if (selectedType === 'mayors_permit') {
    checks.business_identity = businessNameCheck(selectedType, undefined, passes, context);
    checks.business_address = addressCheck(passes, context);
  } else if (selectedType === 'bir_certificate') {
    checks.business_identity = businessNameCheck(selectedType, relationship, passes, context);
    checks.business_address = addressCheck(passes, context);
    if (relationship === 'sole_proprietor') {
      const taxpayer = fieldEvidence(passes, taxpayerNameLabel);
      const proprietor = fieldEvidence(passes, proprietorLabel);
      const owner = proprietor.value ? proprietor : taxpayer;
      checks.proprietor_identity = check(personalNameResult(owner.value, context), owner);
    }
  } else {
    let holder = fieldEvidence(passes, holderNameLabel);
    if (!holder.value) {
      holder = combineNameFields(fieldEvidence(passes, givenNameLabel), fieldEvidence(passes, surnameLabel));
    }
    checks.owner_identity = check(
      type.family === 'national_id' || type.family === 'drivers_license' || type.family === 'passport' || type.family === 'umid'
        ? personalNameResult(holder.value, context)
        : 'uncertain',
      holder,
    );
  }

  const entry: ShopOwnerOcrEntry = {
    assessment_state: hasText ? 'assessed' : 'unable_to_assess',
    detected_family: type.family,
    ...(relationship ? { relationship } : {}),
    ...(validDay(context.referenceDay) ? { reference_day: context.referenceDay } : {}),
    ...((passes.map((pass) => pass.confidence).filter((value) => Number.isFinite(value)).length > 0)
      ? { recognition_confidence: Math.max(...passes.map((pass) => pass.confidence).filter((value): value is number => Number.isFinite(value))) }
      : {}),
    checks,
    reason_codes: reasonCodesFor(checks, type.family),
  };
  return entry;
}
