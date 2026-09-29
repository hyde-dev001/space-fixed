import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const approvalPages = [
  'resources/js/Pages/ERP/Finance/repairPriceApproval.tsx',
  'resources/js/Pages/ERP/Finance/shoePriceApproval.tsx',
];

describe('finance approval page header layout', () => {
  it.each(approvalPages)('pushes the view toggle to the right on large screens in %s', (path) => {
    const source = readFileSync(resolve(path), 'utf8');

    expect(source).toMatch(
      /className="flex gap-2 bg-gray-100 dark:bg-gray-800 p-1 rounded-lg lg:ml-auto"/,
    );
  });
});
