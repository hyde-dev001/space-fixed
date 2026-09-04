import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const footerSource = readFileSync(resolve(process.cwd(), 'resources/js/components/common/CustomerFooter.tsx'), 'utf8');
const customerPageSources = [
  'Pages/UserSide/Orders/Checkout.tsx',
  'Pages/UserSide/Products/Products.tsx',
  'Pages/UserSide/Repairs/Repair.tsx',
  'Pages/UserSide/Orders/MyOrders.tsx',
  'Pages/UserSide/Repairs/myRepairs.tsx',
  'Pages/UserSide/Profile/customerProfile.tsx',
  'Pages/UserSide/Profile/ShopProfile.tsx',
  'Pages/UserSide/Auth/ShopOwnerRegistration.tsx',
  'Pages/UserSide/app/apk.tsx',
].map((relativePath) => ({
  relativePath,
  source: readFileSync(resolve(process.cwd(), `resources/js/${relativePath}`), 'utf8'),
}));

describe('customer footer curtain reveal', () => {
  it('keeps the footer fixed beneath a measured spacer and gates interaction until reveal', () => {
    expect(footerSource).toContain('CustomerFooterReveal');
    expect(footerSource).toContain('ResizeObserver');
    expect(footerSource).toContain('customer-footer-page__spacer');
    expect(footerSource).toContain('customer-footer--fixed');
    expect(footerSource).toContain('toggleAttribute(\'inert\'');
  });

  it('uses the shared curtain shell on every requested footer page', () => {
    customerPageSources.forEach(({ relativePath, source }) => {
      expect(source, relativePath).toContain('CustomerFooterReveal');
      expect(source, relativePath).not.toContain('<CustomerFooter ');
    });
  });
});
