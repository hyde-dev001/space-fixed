import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const readSource = (file: string) => readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders', file),
  'utf8',
);

const checkoutSource = readSource('Checkout.tsx');
const paymentSource = readSource('payment.tsx');

describe('customer address map integration', () => {
  it('pins new and edited checkout addresses and persists their coordinates', () => {
    expect(checkoutSource).toContain("import CustomerAddressMapPicker from '@/components/address/CustomerAddressMapPicker'");
    expect(checkoutSource.match(/<CustomerAddressMapPicker/g)).toHaveLength(2);
    expect(checkoutSource).toContain('latitude: null');
    expect(checkoutSource).toContain('longitude: null');
    expect(checkoutSource).toContain('latitude: newAddressData.latitude');
    expect(checkoutSource).toContain('longitude: newAddressData.longitude');
    expect(checkoutSource).toContain('latitude: editingAddressData.latitude');
    expect(checkoutSource).toContain('longitude: editingAddressData.longitude');
    expect(checkoutSource).toContain('postal_code: location.postalCode');
  });

  it('pins the payment address and applies every parsed address field', () => {
    expect(paymentSource).toContain("import CustomerAddressMapPicker from '@/components/address/CustomerAddressMapPicker'");
    expect(paymentSource).toContain('<CustomerAddressMapPicker');
    expect(paymentSource).toContain('{ latitude: shippingLatitude, longitude: shippingLongitude }');
    expect(paymentSource).toContain('setShippingLatitude(location.latitude)');
    expect(paymentSource).toContain('setShippingLongitude(location.longitude)');
    expect(paymentSource).toContain('setShippingRegion(location.province || location.region)');
    expect(paymentSource).toContain('setShippingCity(location.city)');
    expect(paymentSource).toContain('setShippingBarangay(location.barangay)');
    expect(paymentSource).toContain('setShippingPostalCode(location.postalCode)');
  });

  it('preserves street-only address lines when the picker returns a full display address', () => {
    expect(checkoutSource).not.toContain('address: location.displayName');
    expect(checkoutSource).not.toContain('address_line: location.displayName');
    expect(checkoutSource).toContain('shipping_address_line: selectedAddress?.address_line || null');
    expect(checkoutSource).not.toContain('shipping_address_line: selectedAddress?.address || null');
    expect(paymentSource).not.toContain('setShippingAddressLine(location.displayName)');
  });

  it('offers legacy saved addresses a direct repin path', () => {
    expect(checkoutSource).toContain('Repin address');
    expect(checkoutSource).toContain('address.latitude == null || address.longitude == null');
    expect(paymentSource).toContain('Repin address');
    expect(paymentSource).toContain('addr.latitude == null || addr.longitude == null');
    expect(paymentSource).toContain('setIsAddressSheetOpen(true)');
  });

  it('makes checkout saved-address controls reachable at every screen size', () => {
    expect(checkoutSource).toContain("import { createPortal } from 'react-dom';");
    expect(checkoutSource).toContain('setShowAddressSelector(true)');
    expect(checkoutSource).toContain('Manage delivery address');
    expect(checkoutSource).toContain('showAddressSelector && createPortal(');
    expect(checkoutSource).toContain('showAddAddressModal && createPortal(');
    expect(checkoutSource).toContain('editingAddressId !== null && editingAddressData && createPortal(');
  });

  it('preserves the selected checkout address when saved addresses reload', () => {
    expect(checkoutSource).toContain('formattedAddresses.find((address: any) => address.id === selectedAddressId)');
    expect(checkoutSource).toContain('selectedAddress || defaultAddress');
  });

  it('keeps payment address saving recoverable and announced inline', () => {
    expect(paymentSource).toContain('isAddressSaving');
    expect(paymentSource).toContain('aria-live="polite"');
    expect(paymentSource).toContain('Saving address...');
    expect(paymentSource).toContain('Please try again.');
  });

  it('persists the current address before order creation and uses the returned id', () => {
    const saveIndex = paymentSource.indexOf('const savedAddressId = await saveAddressToAccount()');
    const orderIndex = paymentSource.indexOf("fetch('/api/checkout/create-order'");

    expect(saveIndex).toBeGreaterThan(-1);
    expect(orderIndex).toBeGreaterThan(saveIndex);
    expect(paymentSource).toContain("method: targetAddressId ? 'PUT' : 'POST'");
    expect(paymentSource).toContain('return savedAddress.id');
    expect(paymentSource).toContain('address_id: savedAddressId');
    expect(paymentSource).not.toContain('address_id: checkoutData.address_id ?? null');
    expect(paymentSource).not.toContain('await saveAddressToAccount(orderId)');
  });

  it('discloses mandatory address persistence and limits the checkbox to default selection', () => {
    expect(paymentSource).not.toContain('Save my information for faster checkout');
    expect(paymentSource).not.toContain('Save my information for a faster checkout');
    expect(paymentSource).toContain('Your delivery address will be saved or updated for order fulfillment.');
    expect(paymentSource).toContain('Set as my default delivery address');
    expect(paymentSource).toContain('checked={setAsDefaultAddress}');
    expect(paymentSource.match(/is_default: setAsDefaultAddress/g)).toHaveLength(2);
    expect(paymentSource).not.toContain('is_default: userAddresses.length === 0');
  });

  it('does not silently promote addresses when the default checkbox is hidden on mobile', () => {
    expect(paymentSource).toContain('const [setAsDefaultAddress, setSetAsDefaultAddress] = useState(false);');
    expect(paymentSource).toContain('setSetAsDefaultAddress(false);');
    expect(paymentSource.match(/setSetAsDefaultAddress\(Boolean\(addr\.is_default\)\)/g)).toHaveLength(2);
  });
});
