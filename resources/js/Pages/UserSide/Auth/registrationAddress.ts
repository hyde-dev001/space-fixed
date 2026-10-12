export type RegistrationAddress = {
  displayName: string;
  region: string;
  province: string;
  city: string;
  barangay: string;
  postalCode: string;
  latitude: number;
  longitude: number;
};

export const getRegistrationAddressFields = (result: unknown): {
  businessAddress: string;
  postalCode: string;
} | null => {
  const payload = result as {
    display_name?: unknown;
    address?: { postcode?: unknown };
  } | null;
  const businessAddress = typeof payload?.display_name === 'string'
    ? payload.display_name
    : '';
  if (!businessAddress) return null;

  const postalCode = typeof payload?.address?.postcode === 'string'
    ? payload.address.postcode.replace(/\D/g, '')
    : '';

  return { businessAddress, postalCode };
};

export type ParsePhilippineAddressOptions = {
  allowIncomplete?: boolean;
};

export const parsePhilippineAddress = (
  result: any,
  options: ParsePhilippineAddressOptions = {},
): RegistrationAddress | null => {
  const latitude = Number(result?.lat);
  const longitude = Number(result?.lon);
  const address = result?.address || {};
  const firstText = (...values: unknown[]) => values.find(
    (value) => typeof value === 'string' && value.trim(),
  ) as string | undefined || '';
  const province = firstText(address.province, address.state, address.state_district);
  const region = firstText(address.region, address.state, province);
  const city = firstText(
    address.city,
    address.municipality,
    address.town,
    address.locality,
    address.county,
    address.state_district,
  );
  const barangay = firstText(
    address.suburb,
    address.quarter,
    address.neighbourhood,
    address.village,
    address.city_district,
    address.district,
    address.hamlet,
    address.locality,
  );
  const countryCode = typeof address.country_code === 'string'
    ? address.country_code.toLowerCase()
    : '';

  if (
    !Number.isFinite(latitude)
    || !Number.isFinite(longitude)
    || latitude < 4.5 || latitude > 21.5
    || longitude < 116 || longitude > 127
    || (countryCode && countryCode !== 'ph')
    || (!options.allowIncomplete && (!region || !province || !city || !barangay))
  ) return null;

  return {
    displayName: firstText(result.display_name),
    region,
    province,
    city,
    barangay,
    postalCode: firstText(address.postcode),
    latitude,
    longitude,
  };
};
