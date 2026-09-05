import { describe, expect, it } from 'vitest';
import { matchRegistrationName } from './registrationNameMatch';

describe('registration ID holder-name matching', () => {
  it('matches normalized punctuation, accents, spacing, and suffix formatting', () => {
    expect(matchRegistrationName(
      'José Anne-Marie',
      'Dela  Cruz, Jr.',
      'APELYIDO/LAST NAME DELA-CRUZ JR MGA PANGALAN/GIVEN NAMES JOSE ANNE MARIE',
    )).toBe('matched');
  });

  it('allows one OCR edit for a given-name token containing at least five letters', () => {
    expect(matchRegistrationName(
      'Franszine Miguel',
      'Bato',
      'LAST NAME BATO GIVEN NAMES FRANSZlNE MIGUEL',
    )).toBe('matched');
  });

  it('allows one OCR edit in a longer surname token', () => {
    expect(matchRegistrationName(
      'John Daniel',
      'Paragas',
      'APELYIDO/LAST NAME PARAGA5 MGA PANGALAN/GIVEN NAMES JOHN DANIEL',
    )).toBe('matched');
  });

  it('requires short given-name tokens to match exactly', () => {
    expect(matchRegistrationName(
      'Ian Miguel',
      'Bato',
      'LAST NAME BATO GIVEN NAMES JAN MIGUEL',
    )).toBe('mismatch');
  });

  it('rejects a different surname even when the given name matches', () => {
    expect(matchRegistrationName(
      'Juan Miguel',
      'Dela Cruz',
      'LAST NAME SANTOS GIVEN NAMES JUAN MIGUEL',
    )).toBe('mismatch');
  });

  it('rejects a different given name even when the surname matches', () => {
    expect(matchRegistrationName(
      'Juan Miguel',
      'Dela Cruz',
      'LAST NAME DELA CRUZ GIVEN NAMES PEDRO MIGUEL',
    )).toBe('mismatch');
  });

  it('reports unreadable when OCR contains no usable text', () => {
    expect(matchRegistrationName('Juan', 'Dela Cruz', '   ')).toBe('unreadable');
  });

  it('reports unreadable when document OCR contains no entered-name evidence', () => {
    expect(matchRegistrationName(
      'Juan',
      'Dela Cruz',
      'REPUBLIC OF THE PHILIPPINES DRIVER LICENSE NAME DATE OF BIRTH ADDRESS',
    )).toBe('unreadable');
  });
});
