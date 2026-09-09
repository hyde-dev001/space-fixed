import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ShopOwner/DssInsights.tsx'), 'utf8');
const header = source.slice(
  source.indexOf('{/* Header */}'),
  source.indexOf('{/* Error state */}'),
);

describe('DSS insights header controls', () => {
  it('keeps the controls in place while preventing accidental title selection and using a red notification number', () => {
    expect(header).toContain('<div className="select-none">');
    expect(header).toContain('rounded-full bg-white text-red-600 text-[10px]');
    expect(header).toContain('className="flex items-center gap-3"');
    expect(header).toContain('aria-label="Show actionable recommendations"');
    expect(header).toContain('aria-label="Analysis period"');
    expect(header).toContain('onClick={fetchData}');

    const notificationIndex = header.indexOf('aria-label="Show actionable recommendations"');
    const periodIndex = header.indexOf('aria-label="Analysis period"');
    const refreshIndex = header.indexOf('onClick={fetchData}');

    expect(notificationIndex).toBeLessThan(periodIndex);
    expect(periodIndex).toBeLessThan(refreshIndex);
  });
});
