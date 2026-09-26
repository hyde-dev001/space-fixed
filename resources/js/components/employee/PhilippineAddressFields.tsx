import type { ChangeEvent } from 'react';
import Select from '@/components/form/Select';
import {
  getCityMunicipalityOptions,
  PHILIPPINE_LOCATIONS,
} from '@/data/philippineLocations';

export type PhilippineAddressValue = {
  address: string;
  province: string;
  cityMunicipality: string;
  postalCode: string;
};

type PhilippineAddressFieldsProps = {
  idPrefix: string;
  value: PhilippineAddressValue;
  onChange: (value: PhilippineAddressValue) => void;
};

const fieldClassName = 'mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 shadow-theme-xs outline-none transition focus:border-gray-500 focus:ring-2 focus:ring-gray-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
const labelClassName = 'block text-sm font-semibold text-gray-700 dark:text-gray-300';

const provinceOptions = PHILIPPINE_LOCATIONS.map((province) => ({
  value: province.name,
  label: province.name,
}));

export default function PhilippineAddressFields({
  idPrefix,
  value,
  onChange,
}: PhilippineAddressFieldsProps) {
  const cityOptions = getCityMunicipalityOptions(value.province).map((city) => ({
    value: city,
    label: city,
  }));
  const addressIsFilled = value.address.trim().length > 0;

  const updateField = <K extends keyof PhilippineAddressValue>(
    field: K,
    nextValue: PhilippineAddressValue[K],
  ) => {
    onChange({ ...value, [field]: nextValue });
  };

  const handlePostalCodeChange = (event: ChangeEvent<HTMLInputElement>) => {
    updateField('postalCode', event.target.value.replace(/\D/g, '').slice(0, 4));
  };

  return (
    <div className='grid grid-cols-1 gap-4 md:grid-cols-2'>
      <div className='md:col-span-2'>
        <label htmlFor={`${idPrefix}-address`} className={labelClassName}>
          Address
        </label>
        <input
          id={`${idPrefix}-address`}
          type='text'
          value={value.address}
          onChange={(event) => updateField('address', event.target.value)}
          placeholder='House/Unit, Street, Barangay'
          autoComplete='street-address'
          className={fieldClassName}
        />
      </div>

      <Select
        id={`${idPrefix}-province`}
        label='Province'
        aria-label='Province'
        value={value.province}
        options={provinceOptions}
        placeholder='Select province'
        required={addressIsFilled}
        onChange={(province) => onChange({ ...value, province, cityMunicipality: '' })}
        className={fieldClassName}
      />

      <Select
        id={`${idPrefix}-city-municipality`}
        label='City/Municipality'
        aria-label='City/Municipality'
        value={value.cityMunicipality}
        options={cityOptions}
        placeholder={value.province ? 'Select city/municipality' : 'Select province first'}
        disabled={!value.province}
        required={addressIsFilled}
        onChange={(cityMunicipality) => updateField('cityMunicipality', cityMunicipality)}
        className={fieldClassName}
      />

      <div>
        <label htmlFor={`${idPrefix}-postal-code`} className={labelClassName}>
          Postal Code
        </label>
        <input
          id={`${idPrefix}-postal-code`}
          type='text'
          inputMode='numeric'
          maxLength={4}
          pattern='[0-9]{4}'
          value={value.postalCode}
          onChange={handlePostalCodeChange}
          placeholder='e.g., 2800'
          autoComplete='postal-code'
          required={addressIsFilled}
          className={fieldClassName}
        />
      </div>
    </div>
  );
}
