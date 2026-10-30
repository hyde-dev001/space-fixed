export const CARTO_BASEMAP_KEY = import.meta.env.VITE_CARTO_BASEMAP_KEY ?? '';

export const CARTO_ATTRIBUTION =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>';

if (import.meta.env.DEV && !CARTO_BASEMAP_KEY) {
  console.warn('CARTO basemap key is missing. Set VITE_CARTO_BASEMAP_KEY.');
}

export function getCartoRasterUrl(style = 'light_all', retina = false): string {
  return `https://{s}.basemaps.cartocdn.com/rastertiles/${style}/{z}/{x}/{y}${retina ? '{r}' : ''}.png?key=${encodeURIComponent(CARTO_BASEMAP_KEY)}`;
}
