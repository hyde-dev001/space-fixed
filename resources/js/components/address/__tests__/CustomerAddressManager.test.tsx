import React, { useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import CustomerAddressManager from '../CustomerAddressManager';

vi.mock('../CustomerAddressMapPicker', () => ({
  default: ({ onChange }: { onChange: (location: unknown) => void }) => (
    <button type="button" onClick={() => onChange({
      region: 'CALABARZON', province: 'Cavite', city: 'General Trias', barangay: 'Buenavista II',
      postalCode: '4107', latitude: 14.309844, longitude: 120.899874,
    })}>Choose map pin</button>
  ),
}));

const address = {
  id: 4,
  name: 'Miguel Dela Rosa',
  phone: '09171234567',
  address_line: '126 Ilang-ilang Street',
  barangay: 'Buenavista II',
  city: 'General Trias City',
  province: 'Cavite',
  region: 'CALABARZON',
  postal_code: '4107',
  latitude: 14.309844,
  longitude: 120.899874,
  delivery_instructions: null,
  is_default: true,
};

const response = (body: unknown, ok = true, status = ok ? 200 : 422) => ({
  json: vi.fn().mockResolvedValue(body), ok, status,
}) as unknown as Response;

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ addresses: [address] })));
});

it('loads saved addresses and selects the default with an accessible control', async () => {
  const onSelect = vi.fn();
  render(<CustomerAddressManager onSelect={onSelect} />);

  expect(screen.getByRole('status')).toHaveTextContent('Loading saved addresses');
  const select = await screen.findByRole('button', { name: /use address at 126 ilang-ilang street/i });
  fireEvent.click(select);

  expect(onSelect).toHaveBeenLastCalledWith(address);
  expect(select).toHaveAttribute('aria-pressed', 'true');
});

it('opens the add form in a modal only after the add trigger is clicked', async () => {
  render(<CustomerAddressManager onSelect={vi.fn()} />);
  await screen.findByText(/126 Ilang-ilang Street/);

  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));

  expect(screen.getByRole('dialog', { name: 'Add delivery address' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save address' })).toBeInTheDocument();

  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('supports an add trigger placed outside the address summary', async () => {
  const ControlledManager = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);

    return (
      <>
        <button type="button" onClick={() => setIsModalOpen(true)}>Add address</button>
        <CustomerAddressManager
          onSelect={vi.fn()}
          showAddTrigger={false}
          isModalOpen={isModalOpen}
          onModalOpenChange={setIsModalOpen}
        />
      </>
    );
  };

  render(<ControlledManager />);
  await screen.findByText(/126 Ilang-ilang Street/);

  expect(screen.getAllByRole('button', { name: 'Add address' })).toHaveLength(1);
  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  await waitFor(() => expect(screen.getByRole('dialog', { name: 'Add delivery address' })).toBeInTheDocument());
});

it('requires a map pin before saving an address', async () => {
  render(<CustomerAddressManager onSelect={vi.fn()} />);
  await screen.findByText(/126 Ilang-ilang Street/);

  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Ana Reyes' } });
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '09987654321' } });
  fireEvent.change(screen.getByLabelText('House no., street, subdivision or building'), { target: { value: '5 Test Street' } });
  fireEvent.change(screen.getByLabelText('Province'), { target: { value: 'Cavite' } });
  fireEvent.change(screen.getByLabelText('City/Municipality'), { target: { value: 'General Trias City' } });
  fireEvent.change(screen.getByLabelText('Barangay'), { target: { value: 'Buenavista II' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save address' }));

  expect(await screen.findByText('Pin the exact delivery entrance on the map before saving.')).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledTimes(1);
});

it('adds a pinned address and immediately selects it', async () => {
  const created = { ...address, id: 9, name: 'Ana Reyes', is_default: false };
  vi.mocked(fetch)
    .mockResolvedValueOnce(response({ addresses: [address] }))
    .mockResolvedValueOnce(response({ address: created }, true, 201));
  const onSelect = vi.fn();
  render(<CustomerAddressManager onSelect={onSelect} />);
  await screen.findByText(/126 Ilang-ilang Street/);

  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Ana Reyes' } });
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '09987654321' } });
  fireEvent.change(screen.getByLabelText('House no., street, subdivision or building'), { target: { value: '5 Test Street' } });
  fireEvent.click(screen.getByRole('button', { name: 'Choose map pin' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save address' }));

  await waitFor(() => expect(onSelect).toHaveBeenLastCalledWith(created));
  expect(fetch).toHaveBeenLastCalledWith('/api/user/addresses', expect.objectContaining({ method: 'POST' }));
  expect(screen.getByText('Address saved and selected.')).toBeInTheDocument();
});

it('opens an existing address for editing and shows server errors', async () => {
  vi.mocked(fetch)
    .mockResolvedValueOnce(response({ addresses: [address] }))
    .mockResolvedValueOnce(response({ message: 'Unable to save.' }, false));
  render(<CustomerAddressManager onSelect={vi.fn()} />);
  await screen.findByText(/126 Ilang-ilang Street/);

  fireEvent.click(screen.getByRole('button', { name: /edit 126 ilang-ilang street/i }));
  expect(screen.getByRole('dialog', { name: 'Edit delivery address' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

  expect(await screen.findByText('Unable to save.')).toBeInTheDocument();
});
