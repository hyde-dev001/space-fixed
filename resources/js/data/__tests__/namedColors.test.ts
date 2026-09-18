import { describe, expect, it } from 'vitest';
import { NAMED_COLORS, searchNamedColors } from '../namedColors';

describe('named color catalog', () => {
  it('contains the browser named-color vocabulary, including Indigo', () => {
    expect(NAMED_COLORS.length).toBeGreaterThanOrEqual(148);
    expect(NAMED_COLORS).toContainEqual({ name: 'Indigo', code: '#4B0082' });
  });

  it('searches names case-insensitively and trims the query', () => {
    expect(searchNamedColors('  INDIGO ')).toContainEqual({ name: 'Indigo', code: '#4B0082' });
  });

  it('returns no results for an empty or unknown query', () => {
    expect(searchNamedColors('')).toEqual([]);
    expect(searchNamedColors('not-a-real-color-name')).toEqual([]);
  });
});
