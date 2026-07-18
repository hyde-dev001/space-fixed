import { describe, expect, it } from 'vitest';
import { parsePhilippineAddress } from '../registrationAddress';

describe('parsePhilippineAddress', () => {
  it('maps a Philippine Nominatim result to shipping fields', () => {
    expect(parsePhilippineAddress({
      lat: '14.5832',
      lon: '120.9822',
      display_name: '123 Rizal Street, Ermita, Manila',
      address: {
        region: 'National Capital Region',
        state: 'Metro Manila',
        city: 'Manila',
        suburb: 'Ermita',
        postcode: '1000',
        country_code: 'ph',
      },
    })).toEqual({
      displayName: '123 Rizal Street, Ermita, Manila',
      region: 'National Capital Region',
      province: 'Metro Manila',
      city: 'Manila',
      barangay: 'Ermita',
      postalCode: '1000',
      latitude: 14.5832,
      longitude: 120.9822,
    });
  });

  it('rejects incomplete or non-Philippine results', () => {
    expect(parsePhilippineAddress({ lat: '35', lon: '139', address: { country_code: 'jp' } })).toBeNull();
    expect(parsePhilippineAddress({ lat: '14.5', lon: '121', address: { country_code: 'ph' } })).toBeNull();
    expect(parsePhilippineAddress({
      lat: '10.2', lon: '123.7',
      address: { state: 'Central Visayas', village: 'Mountain Barangay', country_code: 'ph' },
    })).toBeNull();
  });
});
