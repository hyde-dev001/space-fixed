import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment desktop voucher layout', () => {
  it('places the voucher picker before the desktop checkout grid at full width', () => {
    const voucherSectionIndex = paymentSource.indexOf('data-testid="desktop-voucher-section"');
    const desktopGridIndex = paymentSource.indexOf('<div className="hidden xl:grid grid-cols-1 md:grid-cols-3 gap-6 items-start">');

    expect(voucherSectionIndex).toBeGreaterThan(-1);
    expect(desktopGridIndex).toBeGreaterThan(voucherSectionIndex);

    const desktopVoucherSection = paymentSource.slice(voucherSectionIndex, desktopGridIndex);
    expect(desktopVoucherSection).toContain('data-testid="desktop-voucher-suggestions"');
    expect(desktopVoucherSection).toContain('absolute left-0 right-0 top-full');
    expect(desktopVoucherSection).toContain('handleApplyVoucherCode');
    expect(desktopVoucherSection).toContain('handleClearVoucherSelection');
    expect(desktopVoucherSection).toContain('data-testid="voucher-suggestion-card"');
  });

  it('does not keep the voucher input inside the narrow order-summary sidebar', () => {
    const summaryIndex = paymentSource.indexOf('{/* Right: Order Summary (sticky on md) */}');
    const summarySource = paymentSource.slice(summaryIndex);

    expect(summaryIndex).toBeGreaterThan(-1);
    expect(summarySource).not.toContain('aria-label="Voucher code"');
  });
});
