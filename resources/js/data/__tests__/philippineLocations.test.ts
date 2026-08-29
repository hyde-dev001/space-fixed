import { describe, expect, it } from 'vitest';
import {
  PHILIPPINE_LOCATIONS,
  getCityMunicipalityOptions,
  normalizeCityMunicipalitySelection,
  normalizeLocationKey,
  normalizeProvinceSelection,
} from '../philippineLocations';

const EXPECTED_PROVINCES = 'Abra|Agusan del Norte|Agusan del Sur|Aklan|Albay|Antique|Apayao|Aurora|Basilan|Bataan|Batanes|Batangas|Benguet|Biliran|Bohol|Bukidnon|Bulacan|Cagayan|Camarines Norte|Camarines Sur|Camiguin|Capiz|Catanduanes|Cavite|Cebu|Cotabato|Davao de Oro|Davao del Norte|Davao del Sur|Davao Occidental|Davao Oriental|Dinagat Islands|Eastern Samar|Guimaras|Ifugao|Ilocos Norte|Ilocos Sur|Iloilo|Isabela|Kalinga|La Union|Laguna|Lanao del Norte|Lanao del Sur|Leyte|Maguindanao del Norte|Maguindanao del Sur|Marinduque|Masbate|Metro Manila|Misamis Occidental|Misamis Oriental|Mountain Province|Negros Occidental|Negros Oriental|Northern Samar|Nueva Ecija|Nueva Vizcaya|Occidental Mindoro|Oriental Mindoro|Palawan|Pampanga|Pangasinan|Quezon|Quirino|Rizal|Romblon|Samar|Sarangani|Siquijor|Sorsogon|South Cotabato|Southern Leyte|Sultan Kudarat|Sulu|Surigao del Norte|Surigao del Sur|Tarlac|Tawi-Tawi|Zambales|Zamboanga del Norte|Zamboanga del Sur|Zamboanga Sibugay'.split('|');

const SGA_MUNICIPALITIES = [
  'Kadayangan', 'Kapalawan', 'Ligawasan', 'Malidegao',
  'Nabalawag', 'Old Kaabakan', 'Pahamuddin', 'Tugunan',
];

describe('Philippine location hierarchy', () => {
  it('contains unique, non-empty province and city/municipality choices', () => {
    expect(PHILIPPINE_LOCATIONS).toHaveLength(83);
    expect(PHILIPPINE_LOCATIONS.map(({ name }) => name)).toEqual(EXPECTED_PROVINCES);
    expect(PHILIPPINE_LOCATIONS.flatMap(({ citiesMunicipalities }) => citiesMunicipalities)).toHaveLength(1642);

    for (const province of PHILIPPINE_LOCATIONS) {
      expect(province.citiesMunicipalities.length).toBeGreaterThan(0);
      expect(new Set(province.citiesMunicipalities).size).toBe(province.citiesMunicipalities.length);
      expect(province.citiesMunicipalities).toEqual(
        [...province.citiesMunicipalities].sort((a, b) => a.localeCompare(b, 'en')),
      );
    }
  });

  it('places all Special Geographic Area municipalities under Cotabato', () => {
    const cotabato = getCityMunicipalityOptions('Cotabato');
    expect(cotabato.filter((name) => SGA_MUNICIPALITIES.includes(name))).toEqual(SGA_MUNICIPALITIES);
  });

  it('includes Metro Manila and representative locations nationwide', () => {
    expect(getCityMunicipalityOptions('Metro Manila')).toEqual(expect.arrayContaining([
      'City of Manila', 'Quezon City', 'Pateros',
    ]));
    expect(getCityMunicipalityOptions('Metro Manila')).toHaveLength(17);
    expect(getCityMunicipalityOptions('Batanes')).toContain('Basco');
    expect(getCityMunicipalityOptions('Cebu')).toContain('Cebu City');
    expect(getCityMunicipalityOptions('Davao del Sur')).toContain('Davao City');
  });

  it('normalizes saved province and city aliases within the selected province', () => {
    expect(normalizeProvinceSelection('NCR')).toBe('Metro Manila');
    expect(normalizeProvinceSelection('cavite')).toBe('Cavite');
    expect(normalizeCityMunicipalitySelection('Cavite', 'Dasmarinas')).toBe('Dasmariñas');
    expect(normalizeCityMunicipalitySelection('Cavite', 'Dasmarinas City')).toBe('Dasmariñas');
    expect(normalizeCityMunicipalitySelection('Cavite', 'City of Dasmarinas')).toBe('Dasmariñas');
    expect(normalizeCityMunicipalitySelection('Cavite', 'Bacoor')).toBe('Bacoor City');
    expect(normalizeCityMunicipalitySelection('Cavite', 'City of Cavite')).toBe('Cavite City');
    expect(normalizeCityMunicipalitySelection('Cavite', 'General Trias')).toBe('General Trias City');
    expect(normalizeCityMunicipalitySelection('Metro Manila', 'Manila')).toBe('City of Manila');
    expect(normalizeCityMunicipalitySelection('Cebu', 'Dasmarinas')).toBe('');
  });

  it('keeps every city alias reachable and unambiguous within its province', () => {
    for (const province of PHILIPPINE_LOCATIONS) {
      const canonicalKeys = province.citiesMunicipalities.map(normalizeLocationKey);
      const aliasKeys: string[] = [];

      for (const [city, aliases] of Object.entries(province.cityAliases || {})) {
        expect(province.citiesMunicipalities).toContain(city);
        aliasKeys.push(...aliases.map(normalizeLocationKey));
      }

      const allKeys = [...canonicalKeys, ...aliasKeys];
      expect(new Set(allKeys).size).toBe(allKeys.length);
    }
  });
});
