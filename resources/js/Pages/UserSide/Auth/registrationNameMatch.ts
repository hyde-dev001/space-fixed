export type RegistrationNameMatchResult = 'matched' | 'mismatch' | 'unreadable';

const NAME_SUFFIXES = new Set(['jr', 'sr', 'ii', 'iii', 'iv']);

const normalizeNameText = (value: string): string => value
  .normalize('NFD')
  .replace(/\p{M}+/gu, '')
  .toLowerCase()
  .replace(/[^\p{L}\p{N}]+/gu, ' ')
  .replace(/\s+/g, ' ')
  .trim();

const differsByAtMostOneEdit = (left: string, right: string): boolean => {
  if (left === right) return true;
  if (Math.abs(left.length - right.length) > 1) return false;

  const [shorter, longer] = left.length <= right.length ? [left, right] : [right, left];
  let shorterIndex = 0;
  let longerIndex = 0;
  let edits = 0;

  while (shorterIndex < shorter.length && longerIndex < longer.length) {
    if (shorter[shorterIndex] === longer[longerIndex]) {
      shorterIndex += 1;
      longerIndex += 1;
      continue;
    }

    edits += 1;
    if (edits > 1) return false;

    if (shorter.length === longer.length) shorterIndex += 1;
    longerIndex += 1;
  }

  return edits + (longer.length - longerIndex) <= 1;
};

const tokenMatches = (expected: string, actual: string): boolean => (
  expected === actual
  || (expected.length >= 5 && differsByAtMostOneEdit(expected, actual))
);

const containsTokenSequence = (tokens: string[], expectedTokens: string[]): boolean => {
  if (expectedTokens.length === 0 || tokens.length < expectedTokens.length) return false;

  for (let start = 0; start <= tokens.length - expectedTokens.length; start += 1) {
    if (expectedTokens.every((expectedToken, offset) => tokenMatches(expectedToken, tokens[start + offset]))) {
      return true;
    }
  }

  return false;
};

export const matchRegistrationName = (
  firstName: string,
  lastName: string,
  ocrText: string,
): RegistrationNameMatchResult => {
  const normalizedOcr = normalizeNameText(ocrText);
  const givenTokens = normalizeNameText(firstName).split(' ').filter(Boolean);
  const surnameTokens = normalizeNameText(lastName)
    .split(' ')
    .filter(token => token && !NAME_SUFFIXES.has(token));

  if (!normalizedOcr || givenTokens.length === 0 || surnameTokens.length === 0) {
    return 'unreadable';
  }

  const ocrTokens = normalizedOcr.split(' ');
  const givenTokenMatches = givenTokens.map(givenToken => ocrTokens.some(ocrToken => (
      tokenMatches(givenToken, ocrToken)
    )));
  const surnameMatches = containsTokenSequence(ocrTokens, surnameTokens);

  if (!surnameMatches && !givenTokenMatches.some(Boolean)) return 'unreadable';
  if (!surnameMatches) return 'mismatch';

  return givenTokenMatches.every(Boolean) ? 'matched' : 'mismatch';
};
