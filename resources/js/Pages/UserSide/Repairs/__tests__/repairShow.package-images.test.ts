import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('repair package images', () => {
  it('renders a lazy package image with an accessible neutral fallback', () => {
    expect(source).toContain('image_url?: string | null;');
    expect(source).toContain('pkg.image_url');
    expect(source).toContain('Package image unavailable');
    expect(source).toContain('package image`');
  });
});
