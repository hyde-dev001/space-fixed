import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment shop-owned coverage integration', () => {
  it('requests coverage for the latest selected saved address and retains the response', () => {
    expect(paymentSource).toContain('address_id: addressId');
    expect(paymentSource).toContain('requestShippingEstimate(checkoutData.address_id, controller.signal)');
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

  it('refreshes preview coverage from the latest draft pin with stale-response protection', () => {
    expect(paymentSource).toContain('const shippingEstimateRequestRef = useRef(0);');
    expect(paymentSource).toContain('const requestId = ++shippingEstimateRequestRef.current;');
    expect(paymentSource).toContain('requestId !== shippingEstimateRequestRef.current');
    expect(paymentSource).toContain('shipping_latitude: shippingLatitude');
    expect(paymentSource).toContain('shipping_longitude: shippingLongitude');
    expect(paymentSource).toMatch(/shippingLatitude,[\s\S]*shippingLongitude,[\s\S]*\]\);/);
    expect(paymentSource.match(/requestShippingEstimate\(/g)?.length || 0).toBeGreaterThanOrEqual(2);
  });

  it('makes the saved-address manager reachable on desktop', () => {
    const desktopCheckout = paymentSource.slice(paymentSource.indexOf('hidden xl:grid'));

    expect(paymentSource).toContain("import { createPortal } from 'react-dom';");
    expect(paymentSource).toContain('isAddressSheetOpen && createPortal(');
    expect(desktopCheckout).toContain('onClick={openAddressSheet}');
    expect(desktopCheckout).toContain('Manage addresses');
    expect(paymentSource).toContain('role="dialog"');
    expect(paymentSource).toContain('aria-modal="true"');
    expect(paymentSource).toContain('aria-labelledby="address-sheet-title"');
  });

  it('restores the selected address when an edit draft is cancelled', () => {
    expect(paymentSource).toContain('const restoreSelectedAddress = () =>');
    expect(paymentSource).toMatch(/addressSheetMode === 'form'[\s\S]*restoreSelectedAddress\(\);[\s\S]*setAddressSheetMode\('list'\)/);
  });

  it('saves the address then requires a fresh saved-id estimate before creating the order', () => {
    const saveIndex = paymentSource.indexOf('const savedAddressId = await saveAddressToAccount()');
    const finalEstimateIndex = paymentSource.indexOf('await requestShippingEstimate(savedAddressId)');
    const orderIndex = paymentSource.indexOf("fetch('/api/checkout/create-order'");

    expect(saveIndex).toBeGreaterThan(-1);
    expect(finalEstimateIndex).toBeGreaterThan(saveIndex);
    expect(orderIndex).toBeGreaterThan(finalEstimateIndex);
    expect(paymentSource).toContain('shipping_fee: computedShippingFee');
    expect(paymentSource).toContain('Your address was saved, but shipping could not be refreshed. Please retry checkout.');

    const retryBlock = paymentSource.slice(finalEstimateIndex, orderIndex);
    const alertIndex = retryBlock.indexOf('await Swal.fire');
    const resetIndex = retryBlock.indexOf('setIsProcessing(false);');
    const returnIndex = retryBlock.indexOf('return;', resetIndex);
    expect(alertIndex).toBeGreaterThan(-1);
    expect(resetIndex).toBeGreaterThan(alertIndex);
    expect(returnIndex).toBeGreaterThan(resetIndex);
  });
});
