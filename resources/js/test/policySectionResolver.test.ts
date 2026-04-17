import { describe, expect, it } from 'vitest';
import { requiredPolicySectionKeys } from '../utils/policySectionResolver';

describe('requiredPolicySectionKeys', () => {
  it('returns all sections for both', () => {
    expect(requiredPolicySectionKeys('both')).toEqual([
      'refund_payment_terms',
      'repair_service_terms',
      'retail_terms',
    ]);
  });

  it('returns repair-only sections for repair shops', () => {
    expect(requiredPolicySectionKeys('repair')).toEqual([
      'refund_payment_terms',
      'repair_service_terms',
    ]);
  });
});