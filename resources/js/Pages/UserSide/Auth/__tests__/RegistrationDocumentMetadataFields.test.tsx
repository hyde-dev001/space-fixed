import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RegistrationDocumentMetadataFields from '@/components/form/RegistrationDocumentMetadataFields';

describe('registration document metadata fields', () => {
  it('renders and updates the issued date picker', () => {
    const onChange = vi.fn();

    render(
      <RegistrationDocumentMetadataFields
        idPrefix="business_registration"
        label="Business registration"
        metadata={{ expirationMode: 'none', expiresOn: '', issuedOn: '' }}
        onChange={onChange}
      />,
    );

    const issuedDate = screen.getByLabelText('Business registration issued date');

    expect(issuedDate).toHaveAttribute('type', 'date');

    fireEvent.change(issuedDate, { target: { value: '2026-01-15' } });

    expect(onChange).toHaveBeenCalledWith({ issuedOn: '2026-01-15' });
  });
});
