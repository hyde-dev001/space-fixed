import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShopOwnerRegistration from '../ShopOwnerRegistration';
import ShopOwnerOcrStatus from '../ShopOwnerOcrStatus';
import type { ShopOwnerOcrSlotState } from '../useShopOwnerOcr';

const doubles = vi.hoisted(() => ({
  useShopOwnerOcr: vi.fn(),
  selectFile: vi.fn(),
  removeFile: vi.fn(),
  submissionSnapshot: vi.fn(),
  finish: vi.fn(),
  leaflet: (() => {
    const map = { setView: vi.fn().mockReturnThis(), remove: vi.fn(), invalidateSize: vi.fn(), on: vi.fn() };
    const marker = { addTo: vi.fn().mockReturnValue(map), on: vi.fn(), getLatLng: vi.fn(() => ({ lat: 14.3, lng: 120.9 })), setLatLng: vi.fn() };
    const circle = { addTo: vi.fn().mockReturnValue(map), setLatLng: vi.fn(), setRadius: vi.fn() };
    return {
      map,
      leaflet: {
        Icon: { Default: { prototype: {}, mergeOptions: vi.fn() } },
        map: vi.fn(() => map),
        tileLayer: vi.fn(() => ({ addTo: vi.fn().mockReturnValue(map) })),
        marker: vi.fn(() => marker),
        circle: vi.fn(() => circle),
      },
    };
  })(),
}));

vi.mock('../useShopOwnerOcr', () => ({ useShopOwnerOcr: doubles.useShopOwnerOcr }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { post: vi.fn() } }));
vi.mock('ziggy-js', () => ({ route: () => '/shop-owner/register' }));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('../../../../components/common/ComponentCard', () => ({
  default: ({ title, children }: { title?: string; children: React.ReactNode }) => <section><h2>{title}</h2>{children}</section>,
}));
vi.mock('../../../../components/form/Label', () => ({ default: ({ children }: { children: React.ReactNode }) => <label>{children}</label> }));
vi.mock('../../../../components/form/input/InputField', () => ({ default: (props: React.InputHTMLAttributes<HTMLInputElement>) => <input {...props} /> }));
vi.mock('../../../../components/form/Select', () => ({ default: () => <div /> }));
vi.mock('../../../../components/form/input/Radio', () => ({ default: () => <input type="radio" /> }));
vi.mock('../../../../components/form/form-elements/DropZone', () => ({
  default: ({ onDrop, previewAlt }: { onDrop?: (files: File[]) => void; previewAlt?: string }) => (
    <input type="file" aria-label={previewAlt} onChange={(event) => {
      const file = event.currentTarget.files?.[0];
      if (file) onDrop?.([file]);
    }} />
  ),
}));
vi.mock('@/components/form/RegistrationDocumentMetadataFields', () => ({ default: () => null }));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({ default: { fire: vi.fn() } }));
vi.mock('@/utils/csrf', () => ({ getFreshCsrfToken: vi.fn() }));
vi.mock('../../../../components/common/CustomerFooter', () => ({ CustomerFooterReveal: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('leaflet', () => doubles.leaflet.leaflet);

beforeEach(() => {
  doubles.useShopOwnerOcr.mockClear();
  doubles.selectFile.mockClear();
  doubles.removeFile.mockClear();
  doubles.submissionSnapshot.mockClear();
  doubles.finish.mockClear();
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:ocr-test') });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
  doubles.useShopOwnerOcr.mockReturnValue({
    states: {},
    selectFile: doubles.selectFile,
    removeFile: doubles.removeFile,
    submissionSnapshot: doubles.submissionSnapshot,
    finish: doubles.finish,
  });
});

describe('shop owner OCR applicant status', () => {
  it.each([
    ['queued', 'OCR check queued. You can submit without waiting.'],
    ['loading', 'Loading local OCR. You can submit without waiting.'],
    ['recognizing', 'Reading this image in your browser. You can submit without waiting.'],
    ['complete', 'Check complete. An admin still reviews your registration.'],
    ['review', 'Needs admin review. You can still submit this registration.'],
    ['unable', 'Unable to assess. You can still submit for admin review.'],
  ] as const)('explains the %s state accessibly without exposing the score', (status, message) => {
    const state: ShopOwnerOcrSlotState = { status };
    render(<ShopOwnerOcrStatus state={state} />);

    const statusRegion = screen.getByRole('status');
    expect(statusRegion).toHaveAttribute('aria-live', 'polite');
    expect(statusRegion).toHaveTextContent(message);
    expect(statusRegion.textContent).not.toMatch(/100%|50%|0%/);
  });

  it('renders nothing before a required document is selected', () => {
    const { container } = render(<ShopOwnerOcrStatus />);
    expect(container).toBeEmptyDOMElement();
  });

  it('passes the optional server bootstrap and signed resubmission context into the page hook', () => {
    const bootstrap = {
      available: true,
      assets: {
        workerPath: '/portal/ocr/worker.min.js',
        corePath: '/portal/ocr/core/',
        langPath: '/portal/ocr/lang/',
      },
      referenceDay: '2026-10-21',
      timezone: 'Asia/Manila',
    };

    render(<ShopOwnerRegistration
      ocrBootstrap={bootstrap}
      resubmission={{
        isResubmission: true,
        submitUrl: '/signed-resubmission',
        form: {
          firstName: 'Mira',
          lastName: 'Sample',
          email: 'mira@example.test',
          phone: '09171234567',
          businessName: 'Sample Shoe House',
          businessAddress: '123 Example Street, Imus, Cavite',
          postalCode: '4103',
          businessType: 'retail',
          registrationType: 'company',
          shopLatitude: 14.3,
          shopLongitude: 120.9,
        },
        documents: {},
      }}
    />);

    expect(screen.getByRole('heading', { name: 'Shop Owner Application Resubmission' })).toBeInTheDocument();
    expect(doubles.useShopOwnerOcr).toHaveBeenCalledWith(expect.objectContaining({
      assets: bootstrap.assets,
      context: expect.objectContaining({
        firstName: 'Mira',
        businessName: 'Sample Shoe House',
        referenceDay: '2026-10-21',
      }),
      selectedDocumentTypes: expect.objectContaining({
        business_registration: 'dti_registration',
        mayors_permit: 'mayors_permit',
      }),
    }));
  });

  it('clears OCR evidence when a selected replacement file is removed', async () => {
    render(<ShopOwnerRegistration
      ocrBootstrap={{ available: false, assets: null, referenceDay: '2026-10-21', timezone: 'Asia/Manila' }}
      resubmission={{
        isResubmission: true,
        submitUrl: '/signed-resubmission',
        form: {
          firstName: 'Mira',
          lastName: 'Sample',
          email: 'mira@example.test',
          phone: '09171234567',
          businessName: 'Sample Shoe House',
          businessAddress: '123 Example Street, Imus, Cavite',
          postalCode: '4103',
          businessType: 'retail',
          registrationType: 'company',
          shopLatitude: 14.3,
          shopLongitude: 120.9,
        },
        documents: {},
      }}
    />);

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByRole('heading', { name: 'Shop Information' });
    await waitFor(() => expect(doubles.leaflet.leaflet.map).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    const file = new File(['original image'], 'registration.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('Shop Registration (DTI) preview'), { target: { files: [file] } });
    expect(doubles.selectFile).toHaveBeenCalledWith('business_registration', file);

    fireEvent.click(screen.getByRole('button', { name: 'Remove selected Shop Registration file' }));
    expect(doubles.removeFile).toHaveBeenCalledWith('business_registration');
  });
});
