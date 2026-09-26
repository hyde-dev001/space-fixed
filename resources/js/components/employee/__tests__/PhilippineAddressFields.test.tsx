import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PhilippineAddressFields, { type PhilippineAddressValue } from '../PhilippineAddressFields';

const initialValue: PhilippineAddressValue = {
  address: '123 Main Street',
  province: '',
  cityMunicipality: '',
  postalCode: '',
};

describe('PhilippineAddressFields', () => {
  it('keeps city disabled until a province is selected and clears it when the province changes', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <PhilippineAddressFields
        idPrefix='employee'
        value={{ ...initialValue, cityMunicipality: 'Bangued' }}
        onChange={onChange}
      />,
    );

    expect(screen.getByRole('combobox', { name: 'City/Municipality' })).toBeDisabled();

    fireEvent.click(screen.getByRole('combobox', { name: 'Province' }));
    fireEvent.click(screen.getByRole('option', { name: 'Abra' }));

    expect(onChange).toHaveBeenCalledWith({
      ...initialValue,
      cityMunicipality: '',
      province: 'Abra',
    });

    rerender(
      <PhilippineAddressFields
        idPrefix='employee'
        value={{ ...initialValue, province: 'Abra', cityMunicipality: 'Bangued' }}
        onChange={onChange}
      />,
    );

    expect(screen.getByRole('combobox', { name: 'City/Municipality' })).not.toBeDisabled();
    fireEvent.click(screen.getByRole('combobox', { name: 'City/Municipality' }));
    expect(screen.getByRole('option', { name: 'Bangued' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('combobox', { name: 'Province' }));
    fireEvent.click(screen.getByRole('option', { name: 'Agusan del Norte' }));

    expect(onChange).toHaveBeenLastCalledWith({
      ...initialValue,
      cityMunicipality: '',
      province: 'Agusan del Norte',
    });
  });

  it('limits postal code input to four digits', () => {
    const onChange = vi.fn();

    render(
      <PhilippineAddressFields
        idPrefix='employee'
        value={initialValue}
        onChange={onChange}
      />,
    );

    fireEvent.change(screen.getByLabelText('Postal Code'), {
      target: { value: '12a345' },
    });

    expect(onChange).toHaveBeenCalledWith({
      ...initialValue,
      postalCode: '1234',
    });
  });
});
