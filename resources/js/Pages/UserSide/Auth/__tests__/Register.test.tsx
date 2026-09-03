import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  routerPost: vi.fn(),
  readRegistrationId: vi.fn(),
  fingerprintRegistrationImage: vi.fn(),
  compareRegistrationImageFingerprints: vi.fn(),
  getFreshCsrfToken: vi.fn(),
  swalFire: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { post: mocks.routerPost },
}));

vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/icons/index', () => ({
  MailIcon: () => <span aria-hidden="true" />,
  LockIcon: () => <span aria-hidden="true" />,
  UserIcon: () => <span aria-hidden="true" />,
}));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({
  default: {
    fire: mocks.swalFire,
    getConfirmButton: vi.fn(),
  },
}));
vi.mock('@/components/form/form-elements/DropZone', () => ({
  default: ({
    onDrop,
    inputAriaLabel = 'ID file',
    isUploaded,
    fileName,
  }: {
    onDrop?: (files: File[]) => void;
    inputAriaLabel?: string;
    isUploaded?: boolean;
    fileName?: string;
  }) => (
    <div>
      <input
        aria-label={inputAriaLabel}
        type="file"
        onChange={(event) => onDrop?.(Array.from(event.target.files ?? []))}
      />
      {isUploaded && fileName && <span>{fileName}</span>}
    </div>
  ),
}));
vi.mock('leaflet', () => {
  const map = {
    setView: vi.fn().mockReturnThis(),
    on: vi.fn(),
    remove: vi.fn(),
    invalidateSize: vi.fn(),
  };
  const marker = {
    addTo: vi.fn().mockReturnThis(),
    setLatLng: vi.fn(),
    on: vi.fn(),
  };

  return {
    Icon: {
      Default: {
        prototype: {},
        mergeOptions: vi.fn(),
      },
    },
    map: vi.fn(() => map),
    tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
    marker: vi.fn(() => marker),
  };
});
vi.mock('../registrationOcr', () => ({
  readRegistrationId: mocks.readRegistrationId,
  fingerprintRegistrationImage: mocks.fingerprintRegistrationImage,
  compareRegistrationImageFingerprints: mocks.compareRegistrationImageFingerprints,
}));
vi.mock('@/utils/csrf', () => ({
  getFreshCsrfToken: mocks.getFreshCsrfToken,
}));

import Register from '../Register';

const philippinesAddress = {
  lat: '14.5832',
  lon: '120.9822',
  address: {
    country_code: 'ph',
    region: 'National Capital Region',
    state: 'Metro Manila',
    city: 'Manila',
    suburb: 'Ermita',
    postcode: '1000',
  },
};

const driverFrontOcr = {
  text: 'LAND TRANSPORTATION OFFICE LTO REPUBLIC OF THE PHILIPPINES DRIVER LICENSE NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 LICENSE NO A123 EXPIRY 2030 ADDRESS MANILA',
  confidence: 0.94,
  qrDetected: false,
};

const driverBackOcr = {
  text: 'DL CODE A B DRIVING CONDITIONS RESTRICTIONS',
  confidence: 0.42,
  qrDetected: false,
};

const goToIdStep = async () => {
  fireEvent.change(screen.getByLabelText('First Name'), { target: { value: 'Juan' } });
  fireEvent.change(screen.getByLabelText('Last Name'), { target: { value: 'Dela Cruz' } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'juan@example.com' } });
  fireEvent.change(screen.getByLabelText('Phone Number'), { target: { value: '09171234567' } });
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await Promise.resolve();
  });

  await waitFor(() => expect(screen.getByLabelText('Age')).toBeInTheDocument());
  fireEvent.change(screen.getByLabelText('Age'), { target: { value: '25' } });
  fireEvent.change(screen.getByLabelText('Address'), { target: { value: '123 Rizal Street' } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Password1' } });
  fireEvent.change(screen.getByLabelText('Confirm Password'), { target: { value: 'Password1' } });
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Use My GPS' }));
    await Promise.resolve();
  });

  await waitFor(() => expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledWith(
    expect.stringContaining('/api/address/geocode?latitude='),
    expect.any(Object),
  ));
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await Promise.resolve();
  });
  await waitFor(() => expect(screen.getByLabelText('ID Type')).toBeInTheDocument());
};

const selectFile = async (label: string, name: string, contents = name) => {
  await act(async () => {
    fireEvent.change(screen.getByLabelText(label), {
      target: { files: [new File([contents], name, { type: 'image/png' })] },
    });
    await Promise.resolve();
  });
};

const acceptTerms = async () => {
  await act(async () => {
    fireEvent.click(screen.getByLabelText('Accept to the terms and conditions'));
    await Promise.resolve();
  });
  await waitFor(() => expect(screen.getByRole('checkbox')).toBeChecked());
};

beforeEach(() => {
  mocks.routerPost.mockReset();
  mocks.readRegistrationId.mockReset();
  mocks.fingerprintRegistrationImage.mockReset();
  mocks.compareRegistrationImageFingerprints.mockReset();
  mocks.getFreshCsrfToken.mockReset();
  mocks.swalFire.mockReset();

  mocks.getFreshCsrfToken.mockResolvedValue('fresh-csrf-token');
  mocks.swalFire.mockResolvedValue({ isConfirmed: true });
  mocks.fingerprintRegistrationImage.mockImplementation(async (file: File) => ({
    exact: file.name,
    perceptual: null,
  }));
  mocks.compareRegistrationImageFingerprints.mockImplementation((front: { exact: string }, back: { exact: string }) => (
    front.exact === back.exact ? 'exact' : 'none'
  ));
  mocks.readRegistrationId.mockImplementation(async (file: File, onStage?: (stage: string) => void) => {
    onStage?.('recognizing');
    return file.name.includes('back') ? driverBackOcr : driverFrontOcr;
  });

  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input);
    if (url.includes('check-email-availability')) {
      return new Response(JSON.stringify({ available: true }), { status: 200 });
    }
    if (url.includes('check-phone-availability')) {
      return new Response(JSON.stringify({ available: true }), { status: 200 });
    }

    return new Response(JSON.stringify(philippinesAddress), { status: 200 });
  }));
  Object.defineProperty(navigator, 'geolocation', {
    configurable: true,
    value: {
      getCurrentPosition: (success: PositionCallback) => success({
        coords: { latitude: 14.5832, longitude: 120.9822 } as GeolocationCoordinates,
      } as GeolocationPosition),
    },
  });
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: vi.fn(() => 'blob:test-id'),
  });
  Object.defineProperty(URL, 'revokeObjectURL', {
    configurable: true,
    value: vi.fn(),
  });
  document.head.innerHTML = '<meta name="csrf-token" content="csrf-test-token" />';
  (globalThis as { route?: (name: string) => string }).route = (name: string) => `/${name}`;
});

describe('customer registration document screening UI', () => {
  it('checks email and phone together and keeps a duplicate phone on Step 1', async () => {
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('check-phone-availability')) {
        return new Response(JSON.stringify({
          available: false,
          message: 'This phone number is already registered. Try another number or sign in instead.',
        }), { status: 200 });
      }

      return new Response(JSON.stringify({ available: true }), { status: 200 });
    }));

    render(<Register />);
    fireEvent.change(screen.getByLabelText('First Name'), { target: { value: 'Juan' } });
    fireEvent.change(screen.getByLabelText('Last Name'), { target: { value: 'Dela Cruz' } });
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'juan@example.com' } });
    fireEvent.change(screen.getByLabelText('Phone Number'), { target: { value: '09171234567' } });

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Next' }));
      await Promise.resolve();
    });

    await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Registration details not available',
      html: expect.stringContaining('This phone number is already registered'),
    })));
    expect(screen.queryByLabelText('Age')).not.toBeInTheDocument();
    expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledWith(
      expect.stringContaining('check-email-availability'),
      expect.any(Object),
    );
    expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledWith(
      expect.stringContaining('check-phone-availability'),
      expect.any(Object),
    );
  });

  it('groups physical ID sides into one responsive upload section', async () => {
    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });

    expect(screen.getByTestId('registration-id-photo-grid')).toHaveClass('md:grid-cols-2');
    expect(screen.getByText('Front of ID')).toBeInTheDocument();
    expect(screen.getByText('Back of ID')).toBeInTheDocument();
    expect(screen.getByText(/We'll check your ID image before submission/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled();
  });

  it('uses one biodata-page card for passports instead of front and back cards', async () => {
    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'passport' } });

    expect(screen.getByText('Passport biodata page')).toBeInTheDocument();
    expect(screen.queryByText('Back of ID')).not.toBeInTheDocument();
  });

  it('matches the registration name from passport biodata OCR and submits no raw OCR text', async () => {
    mocks.readRegistrationId.mockResolvedValue({
      text: 'REPUBLIC OF THE PHILIPPINES PASSPORT JUAN DELA CRUZ\nP<PHLDELA<CRUZ<<JUAN<<<<<<<<<<<<<<<<<<<<\nP1234567<1PHL9001022M3001020<<<<<<<<<<<<<<08',
      confidence: 0.91,
      qrDetected: false,
    });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'passport' } });
    await selectFile('ID file', 'passport-biodata.png');
    await waitFor(() => expect(screen.getByLabelText('Passport biodata page ready')).toBeInTheDocument());
    expect(screen.queryByText('Passport biodata page ready', { exact: true })).not.toBeInTheDocument();
    await acceptTerms();

    fireEvent.click(screen.getByRole('button', { name: 'Create account' }));

    await waitFor(() => expect(mocks.routerPost).toHaveBeenCalledTimes(1));
    const formData = mocks.routerPost.mock.calls[0][1] as FormData;
    const metadata = JSON.parse(String(formData.get('screening_metadata')));

    expect(metadata).toMatchObject({
      document_type: 'passport',
      name_match: true,
      sides: { biodata: { detected_document_family: 'passport' } },
    });
    expect(metadata.sides.biodata).not.toHaveProperty('ocr_text');
    expect(metadata).not.toHaveProperty('extracted_name');
  });

  it('uses one front-side upload for UMID and submits without a back image', async () => {
    mocks.readRegistrationId.mockResolvedValue({
      text: 'UMID SSS NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990',
      confidence: 0.92,
      qrDetected: false,
    });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'umid' } });

    expect(screen.getByText('Front of ID')).toBeInTheDocument();
    expect(screen.queryByText('Back of ID')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('ID back file')).not.toBeInTheDocument();

    await selectFile('ID file', 'umid-front.png');
    await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
    expect(screen.queryByText('Front image ready', { exact: true })).not.toBeInTheDocument();
    await acceptTerms();

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
      await Promise.resolve();
    });

    await waitFor(() => expect(mocks.routerPost).toHaveBeenCalledTimes(1));
    const formData = mocks.routerPost.mock.calls[0][1] as FormData;
    const metadata = JSON.parse(String(formData.get('screening_metadata')));

    expect(formData.get('valid_id_back')).toBeNull();
    expect(metadata).toMatchObject({
      document_type: 'umid',
      outcome: 'screening_passed',
      name_match: true,
      sides: {
        front: {
          side: 'front',
          outcome: 'plausible',
          detected_document_family: 'umid',
        },
      },
    });
    expect(metadata.sides).not.toHaveProperty('back');
  });

  it('uses one front-and-back flow for a digital National ID and submits both screens', async () => {
    mocks.readRegistrationId.mockImplementation(async (file: File) => file.name.includes('back')
      ? {
        text: 'SEX FEMALE BLOOD TYPE O+ MARITAL STATUS SINGLE PLACE OF BIRTH TONDO MANILA',
        confidence: 0.22,
        qrDetected: true,
      }
      : {
        text: 'SCREENSHOT EPHIL ID PHILIPPINE IDENTIFICATION SYSTEM REPUBLICA NG PILIPINAS FULL NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456 DIGITAL ID NUMBER ABC123 SHOW BACK ID',
        confidence: 0.94,
        qrDetected: false,
      });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'national_id' } });

    expect(screen.queryByLabelText('National ID format')).not.toBeInTheDocument();
    expect(screen.getByText('Front of ID')).toBeInTheDocument();
    expect(screen.getByText('Back of ID')).toBeInTheDocument();
    expect(screen.getByTestId('registration-id-photo-grid')).toHaveClass('md:grid-cols-2');

    await selectFile('ID file', 'digital-national-id-front.png');
    await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
    expect(screen.queryByText('Front image ready', { exact: true })).not.toBeInTheDocument();
    await selectFile('ID back file', 'digital-national-id-back.png');
    await waitFor(() => expect(screen.getByLabelText('Back image ready')).toBeInTheDocument());
    expect(screen.queryByText('Back image ready', { exact: true })).not.toBeInTheDocument();
    await acceptTerms();

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
      await Promise.resolve();
    });

    await waitFor(() => expect(mocks.routerPost).toHaveBeenCalledTimes(1));
    const formData = mocks.routerPost.mock.calls[0][1] as FormData;
    const metadata = JSON.parse(String(formData.get('screening_metadata')));

    expect(formData.get('valid_id_back')).toBeInstanceOf(File);
    expect(metadata).toMatchObject({
      document_type: 'national_id',
      national_id_format: 'digital_image',
      outcome: 'manual_review_required',
      name_match: true,
      sides: {
        front: {
          side: 'front',
          outcome: 'plausible',
          detected_document_family: 'national_id',
        },
        back: {
          side: 'back',
          outcome: 'plausible',
          detected_document_family: 'national_id',
        },
      },
    });
  });

  it('does not offer a Paper/ePhilID or National ID format selection', async () => {
    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'national_id' } });

    expect(screen.queryByLabelText('National ID format')).not.toBeInTheDocument();
    expect(screen.queryByText(/Paper\/ePhilID/i)).not.toBeInTheDocument();
    expect(screen.getAllByText(/landscape orientation is required/i)).toHaveLength(2);
    expect(screen.getAllByText(/rotate portrait screenshots before uploading/i)).toHaveLength(2);
    expect(screen.getByTestId('registration-id-photo-grid')).toHaveClass('md:grid-cols-2');
  });

  it('accepts a National ID back with readable PSA evidence and a detected QR code', async () => {
    mocks.readRegistrationId.mockImplementation(async (file: File, onStage?: (stage: string) => void) => {
      onStage?.('recognizing');

      return file.name.includes('back')
        ? { text: 'PSA.GOV.PH REPUBLIC OF THE PHILIPPINES', confidence: 0.18, qrDetected: true }
        : { text: 'PHILIPPINE IDENTIFICATION CARD PHILSYS REPUBLIC OF THE PHILIPPINES NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456', confidence: 0.94, qrDetected: false };
    });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'national_id' } });

    await selectFile('ID file', 'national-front.png');
    await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
    expect(screen.queryByText('Front image ready', { exact: true })).not.toBeInTheDocument();
    await selectFile('ID back file', 'national-back.png');

    await waitFor(() => expect(screen.getByLabelText('Back image ready')).toBeInTheDocument());
    expect(screen.queryByText('Back image ready', { exact: true })).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent(/review your identity/i);
  });

  it('screens each card side and submits only the bounded screening envelope', async () => {
    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });

    await selectFile('ID file', 'license-front.png');
    await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
    expect(screen.queryByText('Front image ready', { exact: true })).not.toBeInTheDocument();
    await selectFile('ID back file', 'license-back.png');
    await waitFor(() => expect(screen.getByLabelText('Back image ready')).toBeInTheDocument());
    expect(screen.queryByText('Back image ready', { exact: true })).not.toBeInTheDocument();

    expect(mocks.readRegistrationId).toHaveBeenCalledTimes(2);
    await acceptTerms();
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
      await Promise.resolve();
    });

    await waitFor(() => expect(mocks.routerPost).toHaveBeenCalledTimes(1));
    const formData = mocks.routerPost.mock.calls[0][1] as FormData;
    const metadata = JSON.parse(String(formData.get('screening_metadata')));

    expect(formData.get('document_type')).toBe('drivers_license');
    expect(formData.get('valid_id_back')).toBeInstanceOf(File);
    expect(metadata).toMatchObject({
      document_type: 'drivers_license',
      outcome: 'manual_review_required',
      duplicate_kind: 'none',
      name_match: true,
      sides: {
        front: {
          side: 'front',
          outcome: 'plausible',
          detected_document_family: 'drivers_license',
        },
        back: {
          side: 'back',
          outcome: 'plausible',
          detected_document_family: 'drivers_license',
        },
      },
    });
    expect(metadata.sides.front).not.toHaveProperty('ocr_text');
    expect(metadata.sides.front).not.toHaveProperty('ocr_confidence');
    expect(metadata).not.toHaveProperty('classification');
    expect(metadata).not.toHaveProperty('review_status');
    expect(metadata).not.toHaveProperty('extracted_name');
    expect(formData.get('screening_status')).toBeNull();
    expect(formData.get('review_status')).toBeNull();
    expect(formData.get('_token')).toBe('fresh-csrf-token');
  });

  it('submits a readable name mismatch for human review instead of blocking registration', async () => {
    mocks.readRegistrationId.mockImplementation(async (file: File) => file.name.includes('back')
      ? driverBackOcr
      : {
        ...driverFrontOcr,
        text: 'LAND TRANSPORTATION OFFICE LTO REPUBLIC OF THE PHILIPPINES DRIVER LICENSE NAME JUAN SANTOS DATE OF BIRTH 01/02/1990 LICENSE NO A123 EXPIRY 2030 ADDRESS MANILA',
      });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });
    await selectFile('ID file', 'license-front.png');
    await selectFile('ID back file', 'license-back.png');

    expect(screen.queryByText('The name on the uploaded ID does not match the registration name.')).not.toBeInTheDocument();
    await acceptTerms();
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
      await Promise.resolve();
    });

    await waitFor(() => expect(mocks.routerPost).toHaveBeenCalledTimes(1));
    const formData = mocks.routerPost.mock.calls[0][1] as FormData;
    const metadata = JSON.parse(String(formData.get('screening_metadata')));
    expect(metadata).toMatchObject({ outcome: 'manual_review_required', name_match: false });
  });

  it('submits an unreadable ID name for human review instead of blocking registration', async () => {
    mocks.readRegistrationId.mockImplementation(async (file: File) => file.name.includes('back')
      ? driverBackOcr
      : {
        ...driverFrontOcr,
        text: 'LAND TRANSPORTATION OFFICE LTO REPUBLIC OF THE PHILIPPINES DRIVER LICENSE NAME DATE OF BIRTH 01/02/1990 LICENSE NO A123 EXPIRY 2030 ADDRESS MANILA',
      });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });
    await selectFile('ID file', 'license-front.png');
    await selectFile('ID back file', 'license-back.png');

    await acceptTerms();
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
      await Promise.resolve();
    });

    await waitFor(() => expect(mocks.routerPost).toHaveBeenCalledTimes(1));
    const formData = mocks.routerPost.mock.calls[0][1] as FormData;
    const metadata = JSON.parse(String(formData.get('screening_metadata')));
    expect(metadata).toMatchObject({ outcome: 'manual_review_required', name_match: false });
  });

  it('blocks a screening error and gives the customer a retry-safe message', async () => {
    mocks.readRegistrationId.mockRejectedValue(new Error('OCR worker failed'));

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'national_id' } });
    await selectFile('ID file', 'national-front.png');

    await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'error',
      title: 'Unable to check ID',
      text: expect.stringMatching(/We couldn't check this image right now/i),
    })));
    expect(screen.queryByText(/We couldn't check this image right now/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled();
    expect(mocks.routerPost).not.toHaveBeenCalled();
  });

  it('rejects obvious non-ID evidence instead of enabling submission', async () => {
    mocks.readRegistrationId.mockResolvedValue({
      text: 'BIKINI BOTTOM DRIVER LICENSE SPONGEBOB SQUAREPANTS',
      confidence: 0.9,
      qrDetected: false,
    });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'national_id' } });
    await selectFile('ID file', 'meme.png');

    await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'error',
      title: 'Invalid ID image',
      text: expect.stringMatching(/does not appear to match the selected ID type/i),
    })));
    expect(screen.queryByText(/does not appear to match the selected ID type/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled();
    expect(mocks.routerPost).not.toHaveBeenCalled();
  });

  it('shows a side-specific modal for an invalid back image without inline error text', async () => {
    mocks.readRegistrationId.mockImplementation(async (file: File) => file.name.includes('back')
      ? { text: 'ROOM PHOTO', confidence: 0.9, qrDetected: false }
      : driverFrontOcr);

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });
    await selectFile('ID file', 'license-front.png');
    await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
    expect(screen.queryByText('Front image ready', { exact: true })).not.toBeInTheDocument();
    await selectFile('ID back file', 'license-back.png');

    await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'error',
      title: 'Invalid ID image',
      text: expect.stringMatching(/back image does not appear to match/i),
    })));
    expect(screen.queryByText(/back image does not appear to match/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled();
  });

  it('rejects exact front/back duplicates even when the filenames differ', async () => {
    mocks.fingerprintRegistrationImage.mockResolvedValue({
      exact: 'same-normalized-image',
      perceptual: null,
    });

    render(<Register />);
    await goToIdStep();
    fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });
    await selectFile('ID file', 'front.png', 'same bytes');
    await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
    expect(screen.queryByText('Front image ready', { exact: true })).not.toBeInTheDocument();
    await selectFile('ID back file', 'renamed-back.png', 'same bytes');

    await waitFor(() => expect(mocks.swalFire).toHaveBeenCalledWith(expect.objectContaining({
      icon: 'error',
      title: 'Duplicate ID images',
      text: expect.stringMatching(/front and back images appear to be the same/i),
    })));
    expect(screen.queryByText(/front and back images appear to be the same/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled();
    expect(mocks.routerPost).not.toHaveBeenCalled();
  });
});
