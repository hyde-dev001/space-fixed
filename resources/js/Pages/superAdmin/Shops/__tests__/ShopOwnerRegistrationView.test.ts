import { describe, expect, it } from 'vitest';
import {
  areAllDocumentsViewed,
  canDecideRegistration,
  getRegistrationDecisionErrorMessage,
} from '../ShopOwnerRegistrationView';

describe('areAllDocumentsViewed', () => {
  it('requires every submitted document to be viewed', () => {
    expect(areAllDocumentsViewed(0, new Set())).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0]))).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0, 1]))).toBe(true);
  });
});

describe('registration decision guards', () => {
  it('allows decisions only while the server-visible registration is pending', () => {
    expect(canDecideRegistration('pending')).toBe(true);
    expect(canDecideRegistration('approved')).toBe(false);
    expect(canDecideRegistration('rejected')).toBe(false);
  });

  it('surfaces server conflict and validation messages without inventing success', () => {
    expect(getRegistrationDecisionErrorMessage({
      registration: ['This registration was already decided.'],
    })).toBe('This registration was already decided.');
    expect(getRegistrationDecisionErrorMessage({
      rejection_reason: ['A rejection reason is required.'],
    })).toBe('A rejection reason is required.');
    expect(getRegistrationDecisionErrorMessage({})).toContain('server did not apply');
  });
});
