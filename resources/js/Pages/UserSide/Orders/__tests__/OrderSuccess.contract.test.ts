import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/OrderSuccess.tsx'),
  'utf8',
);

describe('OrderSuccess PayMongo confirmation', () => {
  it('queues a one-time success notification for My Orders', () => {
    expect(source).toContain("data?.success && data?.payment_verified");
    expect(source).toContain("sessionStorage.setItem('paymongoPaymentSuccess'");
    expect(source).toContain('data?.order?.order_number');
    expect(source).not.toContain('Payment Successful!');
    expect(source).not.toContain('setStatus');
  });

  it('redirects verified payment to My Orders', () => {
    const verifiedBranchStart = source.indexOf(
      'if (data?.success && data?.payment_verified)',
    );
    const verifiedBranchEnd = source.indexOf('} else {', verifiedBranchStart);
    const verifiedBranch = source.slice(verifiedBranchStart, verifiedBranchEnd);

    expect(verifiedBranch).toContain("router.visit('/my-orders', { replace: true })");
    expect(verifiedBranch).not.toContain("setStatus('success')");
  });
});
