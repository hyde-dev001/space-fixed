import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const checkoutSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/Checkout.tsx'),
  'utf8',
);
const appCssSource = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('Checkout customer dark mode', () => {
  it('marks the checkout surface and remaps its light empty-state gradient', () => {
    expect(checkoutSource).toContain('userside-checkout-page');
    expect(appCssSource).toContain('.userside-checkout-page');
    expect(appCssSource).toContain('[class~="bg-linear-to-b"][class~="from-white"][class~="to-slate-50"]');
    expect(appCssSource).toContain('linear-gradient(180deg, #111827 0%, #0f172a 100%)');
  });
});
