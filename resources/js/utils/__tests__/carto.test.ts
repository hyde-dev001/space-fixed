import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('CARTO basemap configuration', () => {
  beforeEach(() => {
    vi.resetModules();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllEnvs();
  });

  it('builds an authenticated URL for the requested CARTO style', async () => {
    vi.stubEnv('VITE_CARTO_BASEMAP_KEY', 'test-carto-key');
    const { CARTO_ATTRIBUTION, CARTO_BASEMAP_KEY, getCartoRasterUrl } = await import('../carto');

    expect(CARTO_BASEMAP_KEY).toBe('test-carto-key');
    expect(getCartoRasterUrl('voyager')).toBe(
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png?key=test-carto-key',
    );
    expect(getCartoRasterUrl('voyager', true)).toBe(
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png?key=test-carto-key',
    );
    expect(CARTO_ATTRIBUTION).toContain('OpenStreetMap');
    expect(CARTO_ATTRIBUTION).toContain('CARTO');
  });

  it('warns without exposing a missing key and still returns a tile URL', async () => {
    vi.stubEnv('VITE_CARTO_BASEMAP_KEY', '');
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const { getCartoRasterUrl } = await import('../carto');

    expect(warning).toHaveBeenCalledWith(
      'CARTO basemap key is missing. Set VITE_CARTO_BASEMAP_KEY.',
    );
    expect(getCartoRasterUrl()).toBe(
      'https://{s}.basemaps.cartocdn.com/rastertiles/light_all/{z}/{x}/{y}.png?key=',
    );
    expect(warning.mock.calls.flat().join(' ')).not.toContain('test-carto-key');
  });
});
