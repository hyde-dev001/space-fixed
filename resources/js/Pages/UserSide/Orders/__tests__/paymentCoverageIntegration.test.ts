import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment shop-owned coverage integration', () => {
  it('requests coverage for the latest selected saved address and retains the response', () => {
    expect(paymentSource).toContain('address_id: checkoutData.address_id');
    expect(paymentSource).toMatch(/shop_owned\?:\s*\{[\s\S]*available:\s*boolean;[\s\S]*reason:\s*'address_needs_pin'\s*\|\s*'shop_needs_pin'\s*\|\s*'outside_coverage'\s*\|\s*'logistics_unavailable'\s*\|\s*null;[\s\S]*distance_km:\s*number\s*\|\s*null;[\s\S]*coverage_radius_km:\s*number\s*\|\s*null;/);
    expect(paymentSource).toContain('shop_owned: data.shop_owned');
    expect(paymentSource).toContain('checkoutData,');
  });

  it('announces plain-language eligibility states and offers the selected address a repin path', () => {
    expect(paymentSource).toContain('Eligible for Shop-owned Logistics');
    expect(paymentSource).toContain('Outside Shop-owned coverage');
    expect(paymentSource).toContain('Pin this address to check Shop-owned coverage');
    expect(paymentSource).toContain('Shop location is not pinned');
    expect(paymentSource).toContain('Shop-owned logistics is unavailable');
    expect(paymentSource).toContain('distance_km');
    expect(paymentSource).toContain('coverage_radius_km');
    expect(paymentSource).toContain('role="status"');
    expect(paymentSource).toContain('aria-live="polite"');
    expect(paymentSource).toContain('Repin address');
    expect(paymentSource).toContain('handleEditAddressFromList(selectedSavedAddress, true)');
    expect(paymentSource).not.toContain('{shopOwnedCoverage.reason}');
  });

  it('keeps shop-owned coverage informational and preserves third-party calculated shipping', () => {
    expect(paymentSource).not.toMatch(/type=["']radio["'][^>]*(?:carrier|shop-owned|logistics)/i);
    expect(paymentSource).not.toContain('selectedShippingCarrier');
    expect(paymentSource).toContain('shippingEstimate?.max_fee');
    expect(paymentSource).toContain('if (!shippingEstimate || computedShippingFee <= 0)');
    expect(paymentSource).toContain('shipping_fee: computedShippingFee');
  });
});
