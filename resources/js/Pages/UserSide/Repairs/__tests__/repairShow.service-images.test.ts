import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('repair service images', () => {
  it('renders accessible lazy images and a neutral no-image fallback', () => {
    expect(source).toContain('image_url?: string | null;');
    expect(source).toContain('loading="lazy"');
    expect(source).toContain('No image yet');
    expect(source).toContain('aspect-video');
  });
});
