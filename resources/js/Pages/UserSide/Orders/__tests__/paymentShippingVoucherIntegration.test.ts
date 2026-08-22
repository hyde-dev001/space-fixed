import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment shipping voucher integration', () => {
  it('sends the raw shipping context and understands target-aware voucher responses', () => {
    expect(paymentSource).toContain("target: 'items' | 'shipping'");
    expect(paymentSource).toContain('shipping_fee: Math.max(0, toFiniteNumber(shippingEstimate?.max_fee ?? checkoutData.shipping_fee, 0))');
    expect(paymentSource).toContain('address_id: checkoutData.address_id');
    expect(paymentSource).toContain('shipping_voucher_discount');
    expect(paymentSource).toContain('discounted_shipping_fee');
    expect(paymentSource).toContain('shipping_voucher_error');
  });

  it('shows shipping savings separately and uses the discounted fee in totals', () => {
    expect(paymentSource).toContain('shippingVoucherDiscount');
    expect(paymentSource).toContain('discountedShipping');
    expect(paymentSource).toContain('Shipping voucher');
    expect(paymentSource).toContain('Original shipping');
    expect(paymentSource).toMatch(/const total = [\s\S]*discountedShipping/);
    expect(paymentSource).toContain('shipping_voucher_error');
  });

  it('keeps the raw fee in checkout/retry requests while the server persists the discount', () => {
    expect(paymentSource).toContain('shipping_fee: computedShippingFee');
    expect(paymentSource).toContain('subtotal_amount: normalizedSubtotalAmount');
    expect(paymentSource).toMatch(/shipping voucher/i);
  });

  it('renders complete voucher suggestion states and supports claim and use', () => {
    expect(paymentSource).toContain("type VoucherClaimStatus = 'claimed' | 'claimable' | 'redeemed' | 'unavailable'");
    expect(paymentSource).toContain("type VoucherEligibility = 'eligible' | 'minimum_spend' | 'not_applicable' | 'shipping_unavailable' | 'shipping_fee_required' | 'unavailable'");
    expect(paymentSource).toContain('eligibility_message');
    expect(paymentSource).toContain('remaining_spend');
    expect(paymentSource).toContain('claim_product_id');
    expect(paymentSource).toContain('Claim & use');
    expect(paymentSource).toContain('Claim for later');
    expect(paymentSource).toContain('role="listbox"');
    expect(paymentSource).toContain('aria-expanded={showVoucherSuggestionDropdown}');
    expect(paymentSource).toContain('/api/products/${voucher.claim_product_id}/vouchers/${voucher.id}/claim');
    expect(paymentSource).toContain("'X-CSRF-TOKEN'");
  });
});
