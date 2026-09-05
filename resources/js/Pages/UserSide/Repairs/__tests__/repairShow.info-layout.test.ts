import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const repairShowSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('Repair shop information and rating layout', () => {
  it('places the customer rating below the full-width shop information content', () => {
    expect(repairShowSource).toContain('data-testid="repair-shop-info-rating"');
    expect(repairShowSource).toContain('aria-labelledby="repair-shop-information-heading"');
    expect(repairShowSource).toContain('id="repair-shop-information-heading"');
    expect(repairShowSource).toContain('className="flex flex-col"');
    expect(repairShowSource).toContain('data-testid="customer-rating-landscape"');
    expect(repairShowSource).toContain('sm:flex-row sm:items-center sm:justify-between');
    expect(repairShowSource).not.toContain('grid grid-cols-1 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.85fr)]');
    expect(repairShowSource).not.toContain('border-t border-gray-200 lg:border-l lg:border-t-0');
    expect(repairShowSource).toContain('Shop Information');
    expect(repairShowSource).toContain('Customer Rating');
    expect(repairShowSource).toContain('Message');
    expect(repairShowSource).toContain('No reviews yet');
    expect(repairShowSource).not.toContain('from-yellow-50 to-white');
    expect(repairShowSource).not.toContain('border-blue-100 bg-blue-50/70');
    expect(repairShowSource).not.toContain('bg-yellow-400');
  });
});
