import { describe, expect, it } from 'vitest';
import {
  computeCanPay,
  getPhoneDisplayForReceipt,
  normalizeOptionalCustomerId,
} from '../posPaymentValidation';

describe('computeCanPay', () => {
  it('requires proof reference for non-cash', () => {
    const canPay = computeCanPay({
      itemsCount: 1,
      customerName: 'Juan',
      customerPhone: '',
      paymentMethod: 'gcash',
      cashReceivedInput: '',
      hasInsufficientCash: false,
      proofReference: '',
    });

    expect(canPay).toBe(false);
  });

  it('allows non-cash without phone when proof reference exists', () => {
    const canPay = computeCanPay({
      itemsCount: 1,
      customerName: 'Juan',
      customerPhone: '',
      paymentMethod: 'card',
      cashReceivedInput: '',
      hasInsufficientCash: false,
      proofReference: 'AUTH-1',
    });

    expect(canPay).toBe(true);
  });

  it('still requires phone and cash input for cash payments', () => {
    const canPay = computeCanPay({
      itemsCount: 1,
      customerName: 'Juan',
      customerPhone: '',
      paymentMethod: 'cash',
      cashReceivedInput: '',
      hasInsufficientCash: false,
      proofReference: 'IGNORED',
    });

    expect(canPay).toBe(false);
  });
});

describe('getPhoneDisplayForReceipt', () => {
  it('returns N/A for non-cash when phone missing', () => {
    expect(getPhoneDisplayForReceipt('gcash', '')).toBe('N/A');
  });
});

describe('normalizeOptionalCustomerId', () => {
  it('maps guest and invalid identifiers to null while preserving registered IDs', () => {
    expect(normalizeOptionalCustomerId(null)).toBeNull();
    expect(normalizeOptionalCustomerId(0)).toBeNull();
    expect(normalizeOptionalCustomerId('')).toBeNull();
    expect(normalizeOptionalCustomerId('42')).toBe(42);
    expect(normalizeOptionalCustomerId(42.5)).toBeNull();
  });
});
