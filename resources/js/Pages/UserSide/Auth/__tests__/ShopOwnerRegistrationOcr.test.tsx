import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  submissionBlockReason: vi.fn(),
  routerPost: vi.fn(),
  modalFire: vi.fn(),
  modalClose: vi.fn(),
  modalGetPopup: vi.fn(),
  modalShowLoading: vi.fn(),
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
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { post: doubles.routerPost } }));
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
vi.mock('@/components/form/RegistrationDocumentMetadataFields', () => ({
  default: ({ idPrefix, onChange }: {
    idPrefix: string;
    onChange: (updates: { expirationMode: 'dated'; expiresOn: string }) => void;
  }) => (
    <button
      type="button"
      aria-label={`Complete ${idPrefix} metadata`}
      onClick={() => onChange({ expirationMode: 'dated', expiresOn: '2027-12-31' })}
    >Complete metadata</button>
  ),
}));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({ default: {
  fire: doubles.modalFire,
  close: doubles.modalClose,
  getPopup: doubles.modalGetPopup,
  showLoading: doubles.modalShowLoading,
} }));
vi.mock('@/utils/csrf', () => ({ getFreshCsrfToken: vi.fn() }));
vi.mock('../../../../components/common/CustomerFooter', () => ({ CustomerFooterReveal: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('leaflet', () => doubles.leaflet.leaflet);

beforeEach(() => {
  doubles.useShopOwnerOcr.mockClear();
  doubles.selectFile.mockClear();
  doubles.removeFile.mockClear();
  doubles.submissionSnapshot.mockClear();
  doubles.finish.mockClear();
  doubles.routerPost.mockReset();
  doubles.modalFire.mockReset();
  doubles.modalClose.mockReset();
  doubles.modalGetPopup.mockReset();
  doubles.modalShowLoading.mockReset();
  doubles.submissionBlockReason.mockReset();
  doubles.submissionBlockReason.mockReturnValue(null);
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:ocr-test') });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
  doubles.useShopOwnerOcr.mockReturnValue({
    states: {},
    selectFile: doubles.selectFile,
    removeFile: doubles.removeFile,
    submissionSnapshot: doubles.submissionSnapshot,
    finish: doubles.finish,
    submissionBlockReason: doubles.submissionBlockReason,
  });
});

describe('shop owner OCR applicant status', () => {
  it('keeps one loading Swal across queued/active scans and closes it after all scans settle', () => {
    const state = { status: 'queued' } as ShopOwnerOcrSlotState;
    const hook = doubles.useShopOwnerOcr();
    hook.states = { business_registration: state };
    const props = { resubmission: { isResubmission: true } };
    const { rerender, unmount } = render(<ShopOwnerRegistration {...props} />);
    const options = doubles.modalFire.mock.calls.find(([options]) => options.showConfirmButton === false)?.[0];
    expect(options).toMatchObject({
      title: 'Checking your document',
      showConfirmButton: false,
      allowOutsideClick: false,
      allowEscapeKey: false,
      customClass: { popup: 'shop-owner-ocr-loading' },
    });
    options.didOpen();
    expect(doubles.modalShowLoading).toHaveBeenCalledOnce();
    const popup = document.createElement('div');
    popup.className = 'shop-owner-ocr-loading';
    doubles.modalGetPopup.mockReturnValue(popup);
    doubles.modalFire.mockClear();

    hook.states = { business_registration: { status: 'recognizing' }, valid_id: { status: 'queued' } };
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalFire).not.toHaveBeenCalled();
    expect(doubles.modalClose).not.toHaveBeenCalled();

    hook.states = { business_registration: { status: 'review' }, valid_id: { status: 'unable' } };
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalClose).toHaveBeenCalledOnce();
    unmount();
  });

  it('cleans up its loading dialog on removal/unmount without closing a replacement alert', () => {
    const hook = doubles.useShopOwnerOcr();
    hook.states = { business_registration: { status: 'loading' } };
    const props = { resubmission: { isResubmission: true } };
    const { rerender, unmount } = render(<ShopOwnerRegistration {...props} />);
    const ownPopup = document.createElement('div');
    ownPopup.className = 'shop-owner-ocr-loading';
    doubles.modalGetPopup.mockReturnValue(ownPopup);
    hook.states = {};
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalClose).toHaveBeenCalledOnce();

    hook.states = { valid_id: { status: 'recognizing' } };
    rerender(<ShopOwnerRegistration {...props} />);
    doubles.modalGetPopup.mockReturnValue(document.createElement('div'));
    unmount();
    expect(doubles.modalClose).toHaveBeenCalledOnce();
  });

  it.each([
    [{ status: 'replacement_required', relevance: 'clearly_irrelevant' }, 'error', 'Invalid document image', 'Please upload a clear photo of the correct document.'],
    [{ status: 'replacement_required', relevance: 'incompatible_document_type' }, 'error', 'Invalid document image', 'Please upload a clear photo of the correct document.'],
    [{ status: 'review', relevance: 'plausible_document', score: 0 }, 'warning', 'Document needs admin review', 'Some details differ from your registration'],
    [{ status: 'review', relevance: 'uncertain_relevance', score: 50 }, 'warning', 'Document needs admin review', 'Document recognition is uncertain.'],
    [{ status: 'unable', relevance: 'technical_failure' }, 'info', 'Unable to check document', 'You may continue your registration'],
    [{ status: 'complete', relevance: 'plausible_document', score: 100 }, 'success', 'Document check complete', 'An admin still reviews your registration.'],
  ] as const)('shows one result Swal after a scan ends as %s', (state, icon, title, message) => {
    const hook = doubles.useShopOwnerOcr();
    hook.states = { business_registration: { status: 'recognizing' } };
    const props = { resubmission: { isResubmission: true } };
    const { rerender } = render(<ShopOwnerRegistration {...props} />);
    doubles.modalFire.mockClear();
    hook.states = { business_registration: state };
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalFire).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({
      icon, title, text: expect.stringContaining(message), confirmButtonText: 'OK',
    }));
    hook.states = { business_registration: { ...state } };
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalFire).toHaveBeenCalledTimes(1);
  });

  it('prioritizes an invalid new scan without re-alerting on older invalid files', () => {
    const hook = doubles.useShopOwnerOcr();
    hook.states = {
      business_registration: { status: 'replacement_required' },
      valid_id: { status: 'recognizing' },
    };
    const props = { resubmission: { isResubmission: true } };
    const { rerender } = render(<ShopOwnerRegistration {...props} />);
    doubles.modalFire.mockClear();
    hook.states = {
      business_registration: { status: 'replacement_required' },
      valid_id: { status: 'complete' },
    };
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalFire).toHaveBeenLastCalledWith(expect.objectContaining({ title: 'Document check complete' }));

    hook.states = { business_registration: { status: 'recognizing' }, valid_id: { status: 'queued' } };
    rerender(<ShopOwnerRegistration {...props} />);
    doubles.modalFire.mockClear();
    hook.states = { business_registration: { status: 'complete' }, valid_id: { status: 'replacement_required' } };
    rerender(<ShopOwnerRegistration {...props} />);
    expect(doubles.modalFire).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ title: 'Invalid document image' }));
  });

  it.each([
    ['queued', 'Checking your document. Please wait for the document scan to finish before submitting.'],
    ['loading', 'Checking your document. Please wait for the document scan to finish before submitting.'],
    ['recognizing', 'Checking your document. Please wait for the document scan to finish before submitting.'],
    ['complete', 'Check complete. An admin still reviews your registration.'],
    ['review', 'Document recognition is uncertain. A clearer photo may help; an admin will review this document.'],
    ['unable', 'We couldn\'t complete the automatic document check. You may continue your registration, but your documents will require manual verification.'],
    ['replacement_required', 'Invalid document image. We couldn\'t identify the required government document in your upload. Please upload a clear photo of the correct document.'],
  ] as const)('explains the %s state accessibly without exposing the score', (status, message) => {
    const state = { status, relevance: status === 'unable' ? 'technical_failure' : undefined } as ShopOwnerOcrSlotState;
    render(<ShopOwnerOcrStatus state={state} />);

    const statusRegion = screen.getByRole('status');
    expect(statusRegion).toHaveAttribute('aria-live', 'polite');
    expect(statusRegion).toHaveTextContent(message);
    expect(statusRegion.textContent).not.toMatch(/100%|50%|0%/);
  });

  it('explains plausible field conflicts separately while keeping them eligible for manual submission', () => {
    render(<ShopOwnerOcrStatus state={{
      status: 'review',
      score: 0,
      relevance: 'plausible_document',
    }} />);

    expect(screen.getByRole('status')).toHaveTextContent(
      'Document type recognized. Some details differ from your registration and need admin review.',
    );
  });

  it('distinguishes a recognized document family from uncertain information matching', () => {
    render(<ShopOwnerOcrStatus state={{status: 'review', score: 50, relevance: 'plausible_document'}} />);
    expect(screen.getByRole('status')).toHaveTextContent('Document type recognized. Some details need admin review.');
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

    expect(screen.getByText('Resubmission')).toBeInTheDocument();
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
    const pageProps = {
      ocrBootstrap: { available: false, assets: null, referenceDay: '2026-10-21', timezone: 'Asia/Manila' },
      resubmission: {
        isResubmission: true,
        submitUrl: '/signed-resubmission',
        form: {
          firstName: 'Mira',
          lastName: 'Sample',
          age: 30,
          email: 'mira@example.test',
          phone: '09171234567',
          address: '123 Example Street, Imus, Cavite',
          addressRegion: 'CALABARZON',
          addressProvince: 'Cavite',
          addressCity: 'Imus',
          addressBarangay: 'Bayan Luma II',
          addressLatitude: 14.3,
          addressLongitude: 120.9,
          businessName: 'Sample Shoe House',
          businessAddress: '123 Example Street, Imus, Cavite',
          postalCode: '4103',
          businessType: 'retail',
          registrationType: 'company',
          shopLatitude: 14.2814,
          shopLongitude: 120.8685,
        },
        documents: {
          dti_registration: { id: 1, type: 'dti_registration', url: '/dti.png', fileName: 'dti.png' },
          mayors_permit: { id: 2, type: 'mayors_permit', url: '/permit.png', fileName: 'permit.png' },
          bir_certificate: { id: 3, type: 'bir_certificate', url: '/bir.png', fileName: 'bir.png' },
          valid_id: { id: 4, type: 'valid_id', url: '/id.png', fileName: 'id.png' },
        },
      },
    };
    doubles.submissionBlockReason.mockReturnValue('scan_pending');
    const { rerender } = render(<ShopOwnerRegistration {...pageProps} />);

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByRole('heading', { name: 'Shop Information' });
    await waitFor(() => expect(doubles.leaflet.leaflet.map).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    const file = new File(['original image'], '<em>registration</em>.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText('Shop Registration (DTI) preview'), { target: { files: [file] } });
    expect(doubles.selectFile).toHaveBeenCalledWith('business_registration', file);
    expect(doubles.modalFire).not.toHaveBeenCalledWith(expect.objectContaining({ title: 'File Attached' }));
    fireEvent.change(screen.getByLabelText('Shop Registration (DTI) preview'), { target: { files: [new File(['image'], 'data:image/png;base64,AAA.png', {type: 'image/png'})] } });
    expect(doubles.selectFile).toHaveBeenLastCalledWith('business_registration', expect.objectContaining({ name: 'data:image/png;base64,AAA.png' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove selected Shop Registration file' }));
    expect(doubles.removeFile).toHaveBeenCalledWith('business_registration');

    fireEvent.click(screen.getByRole('button', { name: 'Complete mayors_permit metadata' }));
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByRole('heading', { name: 'Ready to Submit?' });
    const finalSubmit = screen.getByRole('button', { name: 'Resubmit Application' });
    expect(finalSubmit).toBeDisabled();
    expect(screen.getByRole('status')).toHaveTextContent(
      'Checking your document. Please wait for the document scan to finish before submitting.',
    );

    doubles.submissionBlockReason.mockReturnValue(null);
    rerender(<ShopOwnerRegistration {...pageProps} />);
    expect(finalSubmit).toBeEnabled();
    doubles.submissionBlockReason.mockReturnValue('replacement_required');
    fireEvent.click(finalSubmit);
    expect(doubles.routerPost).not.toHaveBeenCalled();
    expect(screen.getByRole('heading', { name: 'Document Upload' })).toBeInTheDocument();

    doubles.submissionBlockReason.mockReturnValue(null);
    doubles.modalFire.mockResolvedValue({ isConfirmed: true });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.click(screen.getByRole('checkbox'));
    await waitFor(() => expect(screen.getByRole('checkbox')).toBeChecked());
    let confirm!: (value: { isConfirmed: boolean }) => void;
    doubles.modalFire.mockReturnValueOnce(new Promise((resolve) => { confirm = resolve; }));
    fireEvent.click(screen.getByRole('button', { name: 'Resubmit Application' }));
    doubles.submissionBlockReason.mockReturnValue('scan_pending');
    await act(async () => confirm({ isConfirmed: true }));
    expect(doubles.routerPost).not.toHaveBeenCalled();
    expect(screen.getByRole('heading', { name: 'Document Upload' })).toBeInTheDocument();
  });
});
