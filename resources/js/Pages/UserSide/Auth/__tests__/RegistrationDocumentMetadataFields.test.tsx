import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import RegistrationDocumentMetadataFields from '@/components/form/RegistrationDocumentMetadataFields';

describe('registration document metadata fields', () => {
  it('renders and updates the issued date picker', () => {
    const onChange = vi.fn();

    function MetadataFieldsWithState() {
      const [metadata, setMetadata] = useState({ expirationMode: 'none' as const, expiresOn: '', issuedOn: '' });

      return (
        <RegistrationDocumentMetadataFields
          idPrefix="business_registration"
          label="Business registration"
          metadata={metadata}
          onChange={(updates) => {
            onChange(updates);
            setMetadata((current) => ({ ...current, ...updates }));
          }}
        />
      );
    }

    render(<MetadataFieldsWithState />);

    const issuedDate = screen.getByLabelText('Business registration issued date');
    const expirationMode = screen.getByLabelText('Business registration expiration');

    expect(issuedDate).toHaveAttribute('type', 'date');
    expect(expirationMode).toHaveValue('none');
    expect(screen.queryByLabelText('Business registration expiration date')).not.toBeInTheDocument();

    fireEvent.change(issuedDate, { target: { value: '2026-01-15' } });

    expect(onChange).toHaveBeenCalledWith({ issuedOn: '2026-01-15' });

    fireEvent.change(expirationMode, { target: { value: 'dated' } });

    expect(screen.getByLabelText('Business registration expiration date')).toHaveAttribute('type', 'date');
  });
});
