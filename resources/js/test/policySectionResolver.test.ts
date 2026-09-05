import { describe, expect, it } from 'vitest';
import { requiredPolicySectionKeys, resolvePolicySectionsForFlow } from '../utils/policySectionResolver';

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

  it('keeps shared terms and filters channel-specific terms for retail flow', () => {
    const sections = {
      refund_payment_terms: 'Shared refund terms',
      retail_terms: 'Retail terms',
      repair_service_terms: 'Repair terms',
      custom_terms_retail_1: 'Retail custom terms',
      custom_terms_repair_1: 'Repair custom terms',
      custom_terms_1: 'Legacy retail terms',
      __section_title__retail_terms: 'Retail title',
      __section_title__repair_service_terms: 'Repair title',
      __section_title__custom_terms_repair_1: 'Repair custom title',
    };

    expect(resolvePolicySectionsForFlow(sections, 'retail', 'both')).toEqual({
      refund_payment_terms: 'Shared refund terms',
      retail_terms: 'Retail terms',
      custom_terms_retail_1: 'Retail custom terms',
      custom_terms_1: 'Legacy retail terms',
      __section_title__retail_terms: 'Retail title',
    });
  });

  it('keeps shared terms and filters channel-specific terms for repair flow', () => {
    const sections = {
      refund_payment_terms: 'Shared refund terms',
      retail_terms: 'Retail terms',
      repair_service_terms: 'Repair terms',
      custom_terms_retail_1: 'Retail custom terms',
      custom_terms_repair_1: 'Repair custom terms',
      custom_terms_1: 'Legacy retail terms',
      __section_title__retail_terms: 'Retail title',
      __section_title__repair_service_terms: 'Repair title',
      __section_title__custom_terms_repair_1: 'Repair custom title',
    };

    expect(resolvePolicySectionsForFlow(sections, 'repair', 'both')).toEqual({
      refund_payment_terms: 'Shared refund terms',
      repair_service_terms: 'Repair terms',
      custom_terms_repair_1: 'Repair custom terms',
      __section_title__repair_service_terms: 'Repair title',
      __section_title__custom_terms_repair_1: 'Repair custom title',
    });
  });

  it('keeps legacy custom terms for repair-only policies', () => {
    expect(resolvePolicySectionsForFlow({
      refund_payment_terms: 'Shared refund terms',
      repair_service_terms: 'Repair terms',
      custom_terms_1: 'Legacy repair terms',
    }, 'repair', 'repair')).toEqual({
      refund_payment_terms: 'Shared refund terms',
      repair_service_terms: 'Repair terms',
      custom_terms_1: 'Legacy repair terms',
    });
  });
});
