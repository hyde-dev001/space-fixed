import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const discountSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ShopOwner/Orders/order management/discount.tsx'),
  'utf8',
);

describe('shop-owner logistics voucher integration', () => {
  it('maps the logistics capability and target on the campaign contract', () => {
    expect(discountSource).toContain('discount_target');
    expect(discountSource).toContain('data?.logistics');
    expect(discountSource).toContain("discountTarget: 'items' | 'shipping'");
    expect(discountSource).toContain('shippingVouchersAvailable');
  });

  it('lets an eligible owner choose shipping and keeps the campaign shop-wide', () => {
    expect(discountSource).toContain('Shipping voucher');
    expect(discountSource).toContain('Shop-owned Logistics');
    expect(discountSource).toContain('discountTarget === "shipping"');
    expect(discountSource).toContain('next.productId = ""');
    expect(discountSource).toContain('discount_target: form.discountTarget');
    expect(discountSource).toContain('scope: form.discountTarget === "shipping" ? "shop_wide"');
  });

  it('does not expose an unusable shipping target when logistics is unavailable', () => {
    expect(discountSource).toContain('Shipping vouchers are unavailable');
    expect(discountSource).toContain('disabled={!shippingVouchersAvailable}');
    expect(discountSource).toContain('Shipping voucher requires accessible Shop-owned Logistics');
    expect(discountSource).toContain('discountTarget: "items"');
  });
});
