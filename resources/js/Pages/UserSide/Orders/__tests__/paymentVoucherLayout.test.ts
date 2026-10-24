import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);
const appCss = readFileSync(
  join(process.cwd(), 'resources/css/app.css'),
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
    expect(desktopVoucherSection).toContain('data-testid="desktop-voucher-table"');
    expect(desktopVoucherSection).toContain('className="mt-2 w-full min-w-0 rounded-xl border');
    expect(desktopVoucherSection).not.toContain('max-h-[min(20rem,calc(100vh-12rem))]');
    expect(desktopVoucherSection).not.toContain('overflow-y-auto');
    expect(desktopVoucherSection).toContain('group relative w-full min-w-0 overflow-hidden');
    expect(desktopVoucherSection).not.toContain('absolute left-0 right-0 top-full');
    expect(desktopVoucherSection).toContain('handleApplyVoucherCode');
    expect(desktopVoucherSection).toContain('handleClearVoucherSelection');
    expect(desktopVoucherSection).toContain('data-testid="voucher-suggestion-card"');
    expect(desktopVoucherSection).toContain('min-h-[5.5rem]');
    expect(desktopVoucherSection).toContain('grid-cols-[3rem_minmax(0,1fr)_5.75rem]');
    expect(desktopVoucherSection).toContain('text-base font-semibold');
    expect(desktopVoucherSection).toContain('min-h-11 w-full whitespace-nowrap rounded-xl bg-gray-900 px-1.5 text-xs font-medium text-white');
    expect(desktopVoucherSection).toContain('flex flex-col gap-7');
    expect(desktopVoucherSection).toContain('w-24');
    expect(desktopVoucherSection).toContain('text-xs font-medium text-white');
    expect(desktopVoucherSection).toContain('h-11');
    expect(desktopVoucherSection).toContain('rounded-xl bg-gray-900');
    expect(desktopVoucherSection).toContain('hover:bg-gray-800');
    expect(desktopVoucherSection).toContain('handleUseVoucher');
    expect(desktopVoucherSection).toContain('handleClaimVoucher');
    expect(desktopVoucherSection).not.toContain('showVoucherSuggestionDropdown');
    expect(desktopVoucherSection).not.toContain('isVoucherSuggestionOpen');
    expect(desktopVoucherSection).toContain('voucherClaimStatusClass');
    expect(desktopVoucherSection).toContain('bg-[#fafafa]');
    expect(paymentSource).not.toContain('setVoucherCodeInput(normalizedCode)');
    expect(paymentSource).not.toContain('suggestedVoucher');
    expect(paymentSource).toContain('if (!voucherSearchTerm)');
    expect(desktopVoucherSection).not.toContain('min-h-[7rem]');
    expect(desktopVoucherSection).not.toContain('min-h-11 w-full whitespace-nowrap rounded-xl bg-gray-900 px-1 text-sm font-medium text-white');
    expect(desktopVoucherSection).not.toContain('grid-cols-[3.25rem_minmax(0,1fr)_6.5rem]');
    expect(desktopVoucherSection).not.toContain('text-lg font-semibold');
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

  it('keeps an active claimed voucher reusable without the global black selected state', () => {
    expect(paymentSource).toContain("if (status === 'claimed') return 'Active';");
    expect(paymentSource).toContain('data-voucher-option');
    expect(paymentSource).toContain('Active · use when eligible');
    expect(paymentSource).toContain('border-gray-300 bg-gray-100 text-gray-700');
    expect(paymentSource).toContain('border-gray-600 bg-gray-200 ring-1 ring-gray-400');
    expect(appCss).toContain("[data-voucher-option][aria-selected='true']");
    expect(appCss).toContain('background-color: #e5e7eb !important;');
    expect(appCss).toContain('background-color: #4b5563 !important;');
  });

  it('keeps one selection per voucher target and submits both target selections', () => {
    expect(paymentSource).toContain("Record<'items' | 'shipping', number | null>");
    expect(paymentSource).toContain('voucher_campaign_ids');
    expect(paymentSource).toContain('voucher_codes');
    expect(paymentSource).toContain('applied_vouchers');
  });

  it('does not keep the voucher input inside the narrow order-summary sidebar', () => {
    const summaryIndex = paymentSource.indexOf('{/* Right: Order Summary (sticky on md) */}');
    const summarySource = paymentSource.slice(summaryIndex);

    expect(summaryIndex).toBeGreaterThan(-1);
    expect(summarySource).not.toContain('aria-label="Voucher code"');
  });
});
