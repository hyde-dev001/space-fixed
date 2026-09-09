import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (relativePath: string) => readFileSync(resolve(relativePath), 'utf8');

const collectSources = (relativeDirectory: string): string[] => readdirSync(resolve(relativeDirectory), { withFileTypes: true }).flatMap((entry) => {
  const relativePath = join(relativeDirectory, entry.name);

  if (entry.isDirectory()) {
    return collectSources(relativePath);
  }

  return /\.(tsx|jsx)$/.test(entry.name) && !relativePath.includes('__tests__') ? [relativePath] : [];
});

const applicationSources = [
  ...collectSources('resources/js/Pages'),
  ...collectSources('resources/js/components'),
  ...collectSources('resources/js/layout'),
];

describe('global modal backdrop adoption', () => {
  it('defines the global backdrop contract without changing ERP-only surface normalization', () => {
    const css = source('resources/css/app.css');

    expect(css).toMatch(/body \.erp-modal-backdrop\s*\{/);
    expect(css).toContain('background: rgba(0, 0, 0, 0.25) !important;');
    expect(css).toContain('backdrop-filter: blur(2px) !important;');
    expect(css).toContain("body .erp-modal-backdrop:not([class~='opacity-0'])");
    expect(css).toContain('body.swal2-shown:not(.swal2-toast-shown) .swal2-container');
  });

  it('marks every full-screen painted application overlay and removes transparent duplicate shields', () => {
    const unmarkedBackdropLines = applicationSources.flatMap((relativePath) => source(relativePath)
      .split(/\r?\n/)
      .filter((line) => {
        const isFullScreen = /\bfixed\b/.test(line) && /\binset-0\b/.test(line);
        const paintsBackdrop = /\bbg-[^\s"'`]+|\bbg-opacity-|\bbackdrop-blur/.test(line);

        return isFullScreen && paintsBackdrop && !line.includes('erp-modal-backdrop');
      })
      .map((line) => `${relativePath}: ${line.trim()}`));

    expect(unmarkedBackdropLines).toEqual([]);

  });
});
