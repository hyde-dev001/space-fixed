import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  scoreOwnerOcrEntry,
  serializeOwnerOcrEnvelope,
  shopOwnerOcrConfidenceBand,
  summarizeOwnerOcrScores,
} from '../shopOwnerScreening';

type ScreeningFixture = {
  cases: Record<string, {
    slot: string;
    selected_document_type: string;
    entry: unknown;
    expected_score: number | null;
  }>;
  summaries: Array<{
    id: string;
    score_map: Record<string, number | null>;
    expected: { score: number | null; state: string; incomplete: boolean };
  }>;
};

const fixture = JSON.parse(readFileSync(
  resolve(process.cwd(), 'tests/Fixtures/ShopOwnerOcr/screening-contract.json'),
  'utf8',
)) as ScreeningFixture;

describe('shop owner OCR scoring contract', () => {
  it.each(Object.entries(fixture.cases))('scores %s from its required localized checks', (_name, testCase) => {
    expect(scoreOwnerOcrEntry(testCase.slot, testCase.selected_document_type, testCase.entry).score)
      .toBe(testCase.expected_score);
  });

  it.each(fixture.summaries)('aggregates $id without hiding conflicts or missing documents', ({ score_map, expected }) => {
    expect(summarizeOwnerOcrScores(score_map)).toEqual(expected);
  });

  it('serializes only valid optional evidence and never includes a client score', () => {
    const entry = fixture.cases.dti100.entry as Record<string, unknown>;
    const serialized = serializeOwnerOcrEnvelope(
      { business_registration: entry },
      { business_registration: 'dti_registration' },
    );

    expect(serialized).not.toBeNull();
    expect(serialized).not.toContain('"score"');
    expect(serializeOwnerOcrEnvelope(
      { business_registration: { ...entry, applicant_score: 100 } },
      { business_registration: 'dti_registration' },
    )).toBeNull();
  });

  it('keeps an unclear BIR taxpayer relationship uncertain instead of passing business identity', () => {
    const entry = fixture.cases.birCorporate100.entry as Record<string, unknown>;

    expect(scoreOwnerOcrEntry('bir_certificate', 'bir_certificate', {
      ...entry,
      relationship: 'unclear',
    }).score).toBe(50);
  });

  it('uses the agreed normalized confidence bands', () => {
    expect(shopOwnerOcrConfidenceBand(0.85)).toBe('high');
    expect(shopOwnerOcrConfidenceBand(0.65)).toBe('medium');
    expect(shopOwnerOcrConfidenceBand(0.64999)).toBe('low');
    expect(() => shopOwnerOcrConfidenceBand(Number.POSITIVE_INFINITY)).toThrow();
  });
});
