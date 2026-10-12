import { describe, expect, it } from 'vitest';

import { getManagerBusinessCapabilities } from '../managerBusinessCapabilities';

describe('Manager business capabilities', () => {
  it.each([
    ['retail', { businessType: 'retail', canRetail: true, canRepair: false }],
    ['repair', { businessType: 'repair', canRetail: false, canRepair: true }],
    ['both', { businessType: 'both', canRetail: true, canRepair: true }],
  ])('maps %s to the corresponding operational capabilities', (businessType, expected) => {
    expect(getManagerBusinessCapabilities(businessType)).toEqual(expected);
  });
});
