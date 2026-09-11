import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment desktop voucher layout', () => {
  it('places a compact voucher picker below Phone at the payment-form width', () => {
    const phoneFieldIndex = paymentSource.indexOf('{/* Phone */}');
    const voucherSectionIndex = paymentSource.indexOf('data-testid="desktop-voucher-section"');
    const deliveryPersistenceIndex = paymentSource.indexOf('{/* Delivery address persistence */}');

    expect(phoneFieldIndex).toBeGreaterThan(-1);
    expect(voucherSectionIndex).toBeGreaterThan(-1);
    expect(deliveryPersistenceIndex).toBeGreaterThan(voucherSectionIndex);
    expect(voucherSectionIndex).toBeGreaterThan(phoneFieldIndex);

    const phoneFieldSource = paymentSource.slice(phoneFieldIndex, voucherSectionIndex);
    const desktopVoucherSection = paymentSource.slice(voucherSectionIndex, deliveryPersistenceIndex);

    expect(phoneFieldSource).toContain('className="w-full px-4 py-3');
    expect(desktopVoucherSection).toContain('className="mt-2 w-full rounded-xl');
    expect(desktopVoucherSection).toContain('data-testid="desktop-voucher-suggestions"');
    expect(desktopVoucherSection).toContain('hide-scrollbar mt-1 max-h-[min(20rem,calc(100vh-12rem))]');
    expect(desktopVoucherSection).toContain('overflow-y-auto');
    expect(desktopVoucherSection).not.toContain('absolute left-0 right-0 top-full');
    expect(desktopVoucherSection).toContain('handleApplyVoucherCode');
    expect(desktopVoucherSection).toContain('handleClearVoucherSelection');
    expect(desktopVoucherSection).toContain('data-testid="voucher-suggestion-card"');
    expect(desktopVoucherSection).toContain('min-h-[7rem]');
    expect(desktopVoucherSection).toContain('grid-cols-[3.25rem_minmax(0,1fr)_6.5rem]');
    expect(desktopVoucherSection).toContain('w-24');
    expect(desktopVoucherSection).toContain('text-lg font-semibold');
    expect(desktopVoucherSection).toContain('text-xs font-medium text-white');
    expect(desktopVoucherSection).toContain('h-11');
    expect(desktopVoucherSection).toContain('rounded-xl bg-gray-900');
    expect(desktopVoucherSection).toContain('hover:bg-gray-800');
    expect(desktopVoucherSection).toContain('handleUseVoucher');
    expect(desktopVoucherSection).toContain('handleClaimVoucher');
    expect(desktopVoucherSection).not.toContain('min-h-[10rem]');
    expect(desktopVoucherSection).not.toContain('min-h-[20rem]');
    expect(desktopVoucherSection).not.toContain('min-h-[8rem]');
    expect(desktopVoucherSection).not.toContain('grid-cols-[3.5rem_minmax(0,1fr)_6rem]');
    expect(desktopVoucherSection).not.toContain('w-64');
    expect(desktopVoucherSection).not.toContain('w-48');
    expect(desktopVoucherSection).not.toContain('shadow-sm');
    expect(desktopVoucherSection).not.toContain('shadow-xl');
    expect(desktopVoucherSection).not.toContain('shadow-md');
  });

  it('keeps the voucher controls mounted while the promo preview refreshes', () => {
    const voucherSectionIndex = paymentSource.indexOf('data-testid="desktop-voucher-section"');
    const deliveryPersistenceIndex = paymentSource.indexOf('{/* Delivery address persistence */}');
    const desktopVoucherSection = paymentSource.slice(voucherSectionIndex, deliveryPersistenceIndex);

    expect(desktopVoucherSection).toContain('data-testid="desktop-voucher-loading"');
    expect(desktopVoucherSection).toContain('isPromoPreviewLoading &&');
    expect(desktopVoucherSection).not.toContain('{isPromoPreviewLoading ? (');
  });

  it('separates product and shipping voucher suggestions', () => {
    const voucherSectionIndex = paymentSource.indexOf('data-testid="desktop-voucher-section"');
    const deliveryPersistenceIndex = paymentSource.indexOf('{/* Delivery address persistence */}');
    const desktopVoucherSection = paymentSource.slice(voucherSectionIndex, deliveryPersistenceIndex);

    expect(paymentSource).toContain('productVoucherSuggestions');
    expect(paymentSource).toContain('shippingVoucherSuggestions');
    expect(paymentSource).toContain('Product Vouchers');
    expect(paymentSource).toContain('Shipping Vouchers');
    expect(desktopVoucherSection).toContain('data-testid={`voucher-group-${group.key}`}');
  });

  it('does not keep the voucher input inside the narrow order-summary sidebar', () => {
    const summaryIndex = paymentSource.indexOf('{/* Right: Order Summary (sticky on md) */}');
    const summarySource = paymentSource.slice(summaryIndex);

    expect(summaryIndex).toBeGreaterThan(-1);
    expect(summarySource).not.toContain('aria-label="Voucher code"');
  });
});
