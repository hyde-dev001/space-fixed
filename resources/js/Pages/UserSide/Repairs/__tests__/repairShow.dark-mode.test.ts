import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const repairShowSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('Repair shop package selection dark mode', () => {
  it('keeps selected packages distinct on the customer dark surface', () => {
    expect(repairShowSource).toContain('repair-package-card--selected');
    expect(repairShowSource).toContain('dark:border-[#7da2ff]');
    expect(repairShowSource).toContain('dark:bg-[#1b2f50]');
    expect(repairShowSource).toContain('dark:border-[#b8cdff]');
  });
});
