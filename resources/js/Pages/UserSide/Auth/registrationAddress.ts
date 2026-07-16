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

export const parsePhilippineAddress = (result: any): RegistrationAddress | null => {
  const latitude = Number(result?.lat);
  const longitude = Number(result?.lon);
  const address = result?.address || {};
  const province = address.province || address.state || '';
  const region = address.region || address.state || province;
  const city = address.city || address.municipality || address.town || address.village || '';
  const barangay = address.suburb || address.quarter || address.neighbourhood || address.village || '';

  if (
    !Number.isFinite(latitude)
    || !Number.isFinite(longitude)
    || latitude < 4.5 || latitude > 21.5
    || longitude < 116 || longitude > 127
    || (address.country_code && address.country_code !== 'ph')
    || !region || !province || !city || !barangay
  ) return null;

  return {
    displayName: result.display_name || '',
    region,
    province,
    city,
    barangay,
    postalCode: address.postcode || '',
    latitude,
    longitude,
  };
};
