import { describe, expect, it } from 'vitest';
import { areAllDocumentsViewed } from '../ShopOwnerRegistrationView';

describe('areAllDocumentsViewed', () => {
  it('requires every submitted document to be viewed', () => {
    expect(areAllDocumentsViewed(0, new Set())).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0]))).toBe(false);
    expect(areAllDocumentsViewed(2, new Set([0, 1]))).toBe(true);
  });
});
