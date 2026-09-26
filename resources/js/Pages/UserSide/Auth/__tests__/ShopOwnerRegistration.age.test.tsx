import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { swalFireMock, swalCloseMock } = vi.hoisted(() => ({
  swalFireMock: vi.fn(),
  swalCloseMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: {
    visit: vi.fn(),
    post: vi.fn(),
  },
}));

vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({
  default: {
    fire: swalFireMock,
    close: swalCloseMock,
  },
}));

vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/components/address/CustomerAddressMapPicker', () => ({ default: () => null }));
vi.mock('@/components/common/ComponentCard', () => ({
  default: ({ children }: { children: React.ReactNode }) => <section>{children}</section>,
}));
vi.mock('@/components/form/form-elements/DropZone', () => ({ default: () => null }));
vi.mock('@/components/form/RegistrationDocumentMetadataFields', () => ({ default: () => null }));
vi.mock('@/components/common/CustomerFooter', () => ({
  CustomerFooterReveal: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

import ShopOwnerRegistration from '../ShopOwnerRegistration';

beforeEach(() => {
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true });
  swalCloseMock.mockReset();
});

describe('shop owner registration age input', () => {
  it('reminds applicants that only Cavite-located shops can register', async () => {
    render(<ShopOwnerRegistration />);

    await waitFor(() => expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Before You Proceed',
      text: expect.stringContaining('Only shops located in Cavite are eligible to register here.'),
    })));
  });

  it.each(['-18', '+18', '0', '00018'])('rejects signed or non-positive age input %s with an invalid-age alert', async (value) => {
    render(<ShopOwnerRegistration />);

    const ageInput = screen.getByLabelText('Age');
    fireEvent.change(ageInput, { target: { value } });

    await waitFor(() => expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'error',
      title: 'Invalid age',
      text: 'Age must be a positive whole number.',
    })));

    expect(ageInput).toHaveValue('');
  });

  it('uses a text input with numeric keyboard guidance instead of a signed number input', () => {
    render(<ShopOwnerRegistration />);

    const ageInput = screen.getByLabelText('Age');
    expect(ageInput).toHaveAttribute('type', 'text');
    expect(ageInput).toHaveAttribute('inputmode', 'numeric');
  });

  it('keeps a positive whole-number age in the field', () => {
    render(<ShopOwnerRegistration />);

    const ageInput = screen.getByLabelText('Age');
    fireEvent.change(ageInput, { target: { value: '18' } });

    expect(ageInput).toHaveValue('18');
  });

  it('blocks the next step with an age requirement alert for applicants below 18', async () => {
    render(<ShopOwnerRegistration />);

    await waitFor(() => expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Before You Proceed',
    })));
    swalFireMock.mockClear();

    fireEvent.change(screen.getByLabelText('Age'), { target: { value: '17' } });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    await waitFor(() => expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'error',
      title: 'Age requirement not met',
      text: 'Applicants below 18 cannot register as a shop owner. You must be at least 18 years old to continue.',
    })));
    expect(screen.getByLabelText('Age')).toHaveValue('17');
  });

  it('shows the document reminder once when entering Step 3', async () => {
    render(
      <ShopOwnerRegistration
        resubmission={{
          isResubmission: true,
          submitUrl: '/shop-owner/resubmit',
          form: {
            firstName: 'Juan',
            lastName: 'Dela Cruz',
            email: 'juan@example.com',
            phone: '09171234567',
            age: 25,
            address: 'Imus, Cavite',
            addressRegion: 'CALABARZON',
            addressProvince: 'Cavite',
            addressCity: 'Imus',
            addressBarangay: 'Bayan Luma',
            addressPostalCode: '4103',
            addressLatitude: '14.2814',
            addressLongitude: '120.8685',
            businessName: 'Juan Shoes',
            businessAddress: 'Imus, Cavite',
            postalCode: '4103',
            businessType: 'retail',
            registrationType: 'individual',
            shopLatitude: '14.2814',
            shopLongitude: '120.8685',
            shopAddress: 'Imus, Cavite',
            shopGeofenceRadius: 90,
          },
          documents: {},
        }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByLabelText('Shop Name')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('Shop Permits & Credentials')).toBeInTheDocument());
    await waitFor(() => expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'info',
      title: 'Document Submission Reminder',
      text: expect.stringContaining('accurate, authentic, and up-to-date documents'),
      confirmButtonText: 'I Understand',
    })));

    const reminderCallCount = swalFireMock.mock.calls.length;
    fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
    await waitFor(() => expect(screen.getByLabelText('Shop Name')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(screen.getByText('Shop Permits & Credentials')).toBeInTheDocument());
    expect(swalFireMock).toHaveBeenCalledTimes(reminderCallCount);
  });
});
