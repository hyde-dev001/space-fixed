import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/OrderSuccess.tsx'),
  'utf8',
);

describe('OrderSuccess PayMongo confirmation', () => {
  it('renders a verified confirmation with explicit navigation actions', () => {
    expect(source).toContain("data?.success && data?.payment_verified");
    expect(source).toContain('Payment Successful!');
    expect(source).toContain('View My Orders');
    expect(source).toContain('Continue Shopping');
    expect(source).toContain('data?.order?.order_number');
  });

  it('does not auto-redirect after verified payment', () => {
    const verifiedBranchStart = source.indexOf(
      'if (data?.success && data?.payment_verified)',
    );
    const verifiedBranchEnd = source.indexOf('} else {', verifiedBranchStart);
    const verifiedBranch = source.slice(verifiedBranchStart, verifiedBranchEnd);

    expect(verifiedBranch).not.toContain('router.visit(postReturnDestination');
  });
});
