import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('Flatpickr calendar layout contract', () => {
  it('keeps the date grid inside the padded calendar on narrow layouts', () => {
    expect(appCss).toMatch(
      /\.flatpickr-days,\s*\.flatpickr-days \.dayContainer\s*\{[\s\S]*?width:\s*100% !important;[\s\S]*?min-width:\s*0 !important;[\s\S]*?max-width:\s*none !important;/,
    );
    expect(appCss).toMatch(
      /\.flatpickr-day\s*\{[\s\S]*?flex-basis:\s*calc\(14\.2857143% - 0\.25rem\) !important;[\s\S]*?max-width:\s*none !important;/,
    );
  });
});
