import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { swalFireMock } = vi.hoisted(() => ({ swalFireMock: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { visit: vi.fn(), post: vi.fn() } }));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({ default: { fire: swalFireMock, close: vi.fn() } }));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/components/address/CustomerAddressMapPicker', () => ({ default: () => null }));
vi.mock('@/components/common/ComponentCard', () => ({ default: ({ children }: { children: React.ReactNode }) => <section>{children}</section> }));
vi.mock('@/components/form/form-elements/DropZone', () => ({ default: ({ onDrop, inputAriaLabel, isExistingFile }: { onDrop?: (files: File[]) => void; inputAriaLabel?: string; isExistingFile?: boolean }) => (
  <button type="button" aria-label={inputAriaLabel} onClick={() => onDrop?.([new File(['replacement'], 'updated-lease.png', { type: 'image/png' })])}>
    {isExistingFile ? 'Drag & Drop Documents Here' : 'File Uploaded Successfully!'}
  </button>
) }));
vi.mock('@/components/common/CustomerFooter', () => ({ CustomerFooterReveal: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('leaflet', () => ({
  Icon: { Default: class { static mergeOptions() {} } },
  map: () => ({ setView() { return this; }, on() {}, invalidateSize() {}, remove() {} }),
  tileLayer: () => ({ addTo() {} }),
  marker: () => ({ addTo() { return this; }, on() {}, setLatLng() {} }),
  circle: () => ({ addTo() { return this; }, setLatLng() {}, setRadius() {} }),
}));

import ShopOwnerRegistration from '../ShopOwnerRegistration';

beforeEach(() => {
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true });
  URL.createObjectURL = vi.fn(() => 'blob:replacement');
  URL.revokeObjectURL = vi.fn();
});

it('lets the owner remove and restore an existing optional document before resubmitting', async () => {
  render(<ShopOwnerRegistration resubmission={{
    isResubmission: true,
    submitUrl: '/shop-owner/resubmit',
    form: {
      firstName: 'Juan', lastName: 'Dela Cruz', email: 'juan@example.com', phone: '09171234567', age: 25,
      address: 'Imus, Cavite', addressRegion: 'CALABARZON', addressProvince: 'Cavite', addressCity: 'Imus',
      addressBarangay: 'Bayan Luma', addressPostalCode: '4103', addressLatitude: '14.2814', addressLongitude: '120.8685',
      businessName: 'Juan Shoes', businessAddress: 'Imus, Cavite', postalCode: '4103', businessType: 'retail',
      registrationType: 'individual', shopLatitude: '14.2814', shopLongitude: '120.8685', shopAddress: 'Imus, Cavite',
    },
    documents: {
      dti_registration: { id: 1, type: 'dti_registration', fileName: 'dti.png', url: '/dti' },
      mayors_permit: { id: 2, type: 'mayors_permit', fileName: 'permit.png', url: '/permit' },
      bir_certificate: { id: 3, type: 'bir_certificate', fileName: 'bir.png', url: '/bir' },
      valid_id: { id: 4, type: 'valid_id', fileName: 'id.png', url: '/id' },
      other_documents: [{ id: 5, type: 'supporting_document', logical_slot: 'supporting_document:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', fileName: 'lease.png', url: '/lease' }],
    },
  }} />);

  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await waitFor(() => expect(screen.getByLabelText('Shop Name')).toBeInTheDocument());
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await waitFor(() => expect(screen.getByText('Previously Uploaded Optional Documents')).toBeInTheDocument());

  expect(screen.getByRole('button', { name: 'Replace supporting document 1' })).toHaveTextContent('Drag & Drop Documents Here');
  expect(screen.getByLabelText('Supporting document 1 issued date')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Replace supporting document 1' }));
  expect(screen.getByText('Replacement attached')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Replace supporting document 1' })).toHaveTextContent('File Uploaded Successfully!');

  fireEvent.click(screen.getByRole('button', { name: 'Remove existing supporting document 1' }));
  expect(screen.getByText('Removed when you resubmit')).toBeInTheDocument();
  expect(screen.queryByText('Previously Uploaded Optional Documents')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
  expect(screen.getByText('Previously Uploaded Optional Documents')).toBeInTheDocument();
  expect(screen.queryByText('Removed when you resubmit')).not.toBeInTheDocument();
});
