import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const productShowSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Products/ProductShow.tsx'),
  'utf8',
);

const sizeSelectorStart = productShowSource.indexOf('              <div className="mt-5">');
const sizeSelectorEnd = productShowSource.indexOf('              {showSizeChart', sizeSelectorStart);
const sizeSelector = productShowSource.slice(sizeSelectorStart, sizeSelectorEnd);

describe('ProductShow size selector', () => {
  it('keeps the selected size visible in the dark customer theme', () => {
    expect(sizeSelector).toContain('aria-pressed={isSameSize(selectedSize, option.value)}');
    expect(sizeSelector).toContain('dark:bg-gray-900');
    expect(sizeSelector).toContain('dark:text-gray-100');
    expect(sizeSelector).toContain('dark:border-blue-300');
    expect(sizeSelector).toContain('dark:bg-blue-900/50');
    expect(sizeSelector).toContain('dark:text-white');
  });
});
