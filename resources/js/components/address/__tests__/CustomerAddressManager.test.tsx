import React, { useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
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

const secondAddress = {
  ...address,
  id: 8,
  address_line: '25 Sampaguita Avenue',
  is_default: false,
};

const response = (body: unknown, ok = true, status = ok ? 200 : 422) => ({
  json: vi.fn().mockResolvedValue(body), ok, status,
}) as unknown as Response;

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ addresses: [address] })));
});

afterEach(() => {
  vi.unstubAllGlobals();
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

it('renders saved address actions as accessible icon buttons', async () => {
  vi.mocked(fetch).mockResolvedValueOnce(response({ addresses: [address, secondAddress] }));
  render(<CustomerAddressManager onSelect={vi.fn()} />);

  await screen.findByText(/25 Sampaguita Avenue/);

  const edit = screen.getByRole('button', { name: /edit 126 ilang-ilang street/i });
  const setDefault = screen.getByRole('button', { name: /set as default.*25 sampaguita avenue/i });
  const remove = screen.getByRole('button', { name: /delete.*25 sampaguita avenue/i });

  expect(edit).toHaveAttribute('title', 'Edit saved address');
  expect(setDefault).toHaveAttribute('title', 'Set as default address');
  expect(remove).toHaveAttribute('title', 'Delete saved address');
  expect(edit.querySelector('svg')).toBeInTheDocument();
  expect(setDefault.querySelector('svg')).toBeInTheDocument();
  expect(remove.querySelector('svg')).toBeInTheDocument();
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

it('keeps the address summary inside a modal-only flow and supports adding another address', async () => {
  const ControlledManager = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);

    return (
      <>
        <button type="button" onClick={() => setIsModalOpen(true)}>Edit address</button>
        <CustomerAddressManager
          onSelect={vi.fn()}
          showAddTrigger={false}
          showAddressSummary={false}
          modalMode="edit"
          isModalOpen={isModalOpen}
          onModalOpenChange={setIsModalOpen}
        />
      </>
    );
  };

  render(<ControlledManager />);
  await waitFor(() => expect(fetch).toHaveBeenCalledWith('/api/user/addresses', expect.any(Object)));

  expect(screen.queryByText(/126 Ilang-ilang Street/)).not.toBeInTheDocument();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Edit address' }));
  expect(await screen.findByRole('dialog', { name: 'Edit delivery address' })).toBeInTheDocument();
  expect(screen.getByLabelText('Full name')).toHaveValue(address.name);

  fireEvent.click(screen.getByRole('button', { name: 'Add new address' }));
  expect(screen.getByRole('dialog', { name: 'Add delivery address' })).toBeInTheDocument();
});

it('shows saved addresses before opening the add form in an external modal flow', async () => {
  const ControlledManager = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);

    return (
      <>
        <button type="button" onClick={() => setIsModalOpen(true)}>Manage addresses</button>
        <CustomerAddressManager
          onSelect={vi.fn()}
          showAddTrigger={false}
          showAddressSummary={false}
          showSavedAddressesInModal
          modalMode="edit"
          isModalOpen={isModalOpen}
          onModalOpenChange={setIsModalOpen}
        />
      </>
    );
  };

  render(<ControlledManager />);
  await waitFor(() => expect(fetch).toHaveBeenCalledWith('/api/user/addresses', expect.any(Object)));
  fireEvent.click(screen.getByRole('button', { name: 'Manage addresses' }));

  expect(await screen.findByRole('dialog', { name: 'Saved delivery addresses' })).toBeInTheDocument();
  expect(screen.getByText(/126 Ilang-ilang Street/)).toBeInTheDocument();
  expect(screen.queryByLabelText('Full name')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: /edit 126 ilang-ilang street/i }));
  expect(await screen.findByRole('dialog', { name: 'Edit delivery address' })).toBeInTheDocument();
  expect(screen.getByLabelText('Full name')).toHaveValue(address.name);
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
    .mockResolvedValueOnce(response({ address: created }, true, 201))
    .mockResolvedValueOnce(response({ addresses: [address, created] }));
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
  expect(fetch).toHaveBeenLastCalledWith('/api/user/addresses', expect.objectContaining({ method: 'GET' }));
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

it('keeps existing saved addresses after creating another address and reloads the server list', async () => {
  const created = { ...secondAddress, id: 9, name: 'Ana Reyes' };
  vi.mocked(fetch)
    .mockResolvedValueOnce(response({ addresses: [address] }))
    .mockResolvedValueOnce(response({ address: created }, true, 201))
    .mockResolvedValueOnce(response({ addresses: [address, created] }));
  const onSelect = vi.fn();

  render(<CustomerAddressManager onSelect={onSelect} />);
  await screen.findByText(/126 Ilang-ilang Street/);
  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Ana Reyes' } });
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '09AB-987' } });
  fireEvent.change(screen.getByLabelText('House no., street, subdivision or building'), { target: { value: '5 Test Street' } });
  fireEvent.click(screen.getByRole('button', { name: 'Choose map pin' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save address' }));

  expect(await screen.findByText(/Ana Reyes/)).toBeInTheDocument();
  expect(screen.getByText(/126 Ilang-ilang Street/)).toBeInTheDocument();
  expect(fetch).toHaveBeenLastCalledWith('/api/user/addresses', expect.objectContaining({ method: 'GET' }));
  expect(JSON.parse(String(vi.mocked(fetch).mock.calls[1][1]?.body))).toMatchObject({ phone: '09987' });
});

it('sanitizes the phone field to digits while preserving leading zeroes', async () => {
  render(<CustomerAddressManager onSelect={vi.fn()} />);
  await screen.findByText(/126 Ilang-ilang Street/);

  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  const phone = screen.getByLabelText('Phone');
  fireEvent.change(phone, { target: { value: '09AB-987' } });

  expect(phone).toHaveValue('09987');
});

it('sets another saved address as default and refreshes the list', async () => {
  const defaultAddress = { ...secondAddress, is_default: true };
  vi.mocked(fetch)
    .mockResolvedValueOnce(response({ addresses: [address, secondAddress] }))
    .mockResolvedValueOnce(response({ address: defaultAddress }))
    .mockResolvedValueOnce(response({ addresses: [defaultAddress, { ...address, is_default: false }] }));

  render(<CustomerAddressManager onSelect={vi.fn()} />);
  await screen.findByText(/25 Sampaguita Avenue/);
  fireEvent.click(screen.getByRole('button', { name: /set as default.*25 sampaguita avenue/i }));

  await waitFor(() => expect(screen.getByText(/25 Sampaguita Avenue/).closest('div')).toHaveTextContent('Default'));
  expect(fetch).toHaveBeenCalledWith('/api/user/addresses/8/set-default', expect.objectContaining({ method: 'POST' }));
  expect(fetch).toHaveBeenLastCalledWith('/api/user/addresses', expect.objectContaining({ method: 'GET' }));
});

it('deletes a saved address and selects the next available address', async () => {
  vi.stubGlobal('confirm', vi.fn(() => true));
  const onSelect = vi.fn();
  vi.mocked(fetch)
    .mockResolvedValueOnce(response({ addresses: [address, secondAddress] }))
    .mockResolvedValueOnce(response({ success: true }))
    .mockResolvedValueOnce(response({ addresses: [secondAddress] }));

  render(<CustomerAddressManager onSelect={onSelect} />);
  await screen.findByText(/25 Sampaguita Avenue/);
  fireEvent.click(screen.getByRole('button', { name: /delete.*126 ilang-ilang street/i }));

  await waitFor(() => expect(screen.queryByText(/126 Ilang-ilang Street/)).not.toBeInTheDocument());
  expect(screen.getByText(/25 Sampaguita Avenue/)).toBeInTheDocument();
  expect(fetch).toHaveBeenCalledWith('/api/user/addresses/4', expect.objectContaining({ method: 'DELETE' }));
  expect(onSelect).toHaveBeenLastCalledWith(secondAddress);
});
