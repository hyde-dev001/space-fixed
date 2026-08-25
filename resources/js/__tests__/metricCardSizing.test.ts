import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');

const ruleAfter = (marker: string) => {
  const markerIndex = appCss.indexOf(marker);
  expect(markerIndex).toBeGreaterThanOrEqual(0);

  const openingBrace = appCss.indexOf('{', markerIndex);
  const closingBrace = appCss.indexOf('}', openingBrace);

  return {
    declarations: appCss.slice(openingBrace + 1, closingBrace),
    selectors: appCss.slice(markerIndex + marker.length, openingBrace),
  };
};

describe('shared metric card icon sizing', () => {
  it('matches the dashboard tile and icon dimensions', () => {
    const tileRule = ruleAfter('/* Match legacy primary metric icon tiles to the dashboard dimensions. */');
    const iconRule = ruleAfter('/* Keep the main metric icon outlines as light as the dashboard icons. */');

    expect(tileRule.declarations).toContain('width: 3rem !important;');
    expect(tileRule.declarations).toContain('height: 3rem !important;');
    expect(tileRule.declarations).toContain('border-radius: 0.75rem !important;');
    expect(iconRule.declarations).toContain('width: 1.5rem !important;');
    expect(iconRule.declarations).toContain('height: 1.5rem !important;');
    expect(iconRule.declarations).toContain('stroke-width: 1.5 !important;');
  });

  it('scopes the tile override to primary non-overlay metric icon containers', () => {
    const tileRule = ruleAfter('/* Match legacy primary metric icon tiles to the dashboard dimensions. */');

    expect(tileRule.selectors).toContain('.metrics-card');
    expect(tileRule.selectors).toContain('[class~="group"]');
    expect(tileRule.selectors).toContain(':not([class*="absolute"])');
  });
});
