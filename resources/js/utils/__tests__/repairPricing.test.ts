import { describe, expect, it } from 'vitest';
import { buildRepairBreakdown } from '../repairPricing';

describe('buildRepairBreakdown', () => {
  it('extracts VAT from inclusive total', () => {
    const row = buildRepairBreakdown({ finalTotal: 500, vatRate: 12, taxMode: 'vat_inclusive' });

    expect(row.grandTotal).toBe(500);
    expect(row.vatAmount).toBe(53.57);
    expect(row.netSubtotal).toBe(446.43);
  });

  it('keeps legacy add-on math for legacy mode', () => {
    const row = buildRepairBreakdown({ finalTotal: 500, vatRate: 12, taxMode: 'legacy_additive' });

    expect(row.vatAmount).toBe(60);
    expect(row.grandTotal).toBe(560);
    expect(row.netSubtotal).toBe(500);
  });
});