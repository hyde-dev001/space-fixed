import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/MyOrders.tsx'),
  'utf8',
);

describe('MyOrders PayMongo success notification', () => {
  it('consumes the one-time payload and shows a success Swal', () => {
    expect(source).toContain("sessionStorage.getItem('paymongoPaymentSuccess')");
    expect(source).toContain("sessionStorage.removeItem('paymongoPaymentSuccess')");
    expect(source).toContain("icon: 'success'");
    expect(source).toContain("title: 'Payment Successful!'");
    expect(source).toContain('Payment confirmed. We’ll update your order once it moves to the next step.');
    expect(source).not.toContain('orderNumber');
  });
});
