import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShopOwnerRegistrationView, { buildRegistrationApprovalPayload } from '../ShopOwnerRegistrationView';

const { getMock, postMock, swalFireMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
  postMock: vi.fn(),
  swalFireMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => React.createElement(React.Fragment, null, children),
  Link: ({ children }: { children?: React.ReactNode }) => React.createElement(React.Fragment, null, children),
  router: { get: getMock, post: postMock },
}));

vi.mock('sweetalert2', () => ({ default: { fire: swalFireMock } }));
vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => React.createElement('div', null, children),
}));

const disclaimer = 'Browser-based document screening. Results are preliminary and document authenticity has not been independently verified.';

const registration = (overrides: Record<string, unknown> = {}) => ({
  id: 17,
  firstName: 'Synthetic',
  lastName: 'Owner',
  email: 'owner@example.test',
  phone: '09170000000',
  businessName: 'Example Shoes',
  businessAddress: 'Cavite',
  businessType: 'Retail',
  registrationType: 'individual',
  serviceType: 'Retail',
  operatingHours: [],
  documents: [{
    id: 31,
    url: '/admin/shop-owners/17/documents/31',
    type: 'dti_registration',
    documentType: 'dti_registration',
    logicalSlot: 'business_registration',
    versionNumber: 1,
    issuedOn: '2026-01-01',
    expirationMode: 'none',
    expiresOn: null,
    validity: 'metadata_unverified',
    status: 'pending',
    ocrScreening: {
      state: 'assessed',
      score: 0,
      referenceDay: '2026-10-21',
      recognitionConfidence: 0.94,
      checks: [
        { key: 'document_type', label: 'Document type', result: 'match' },
        { key: 'business_identity', label: 'Business identity', result: 'conflict' },
        { key: 'proprietor_identity', label: 'Proprietor identity', result: 'match' },
        { key: 'dates', label: 'Dates', result: 'uncertain' },
      ],
      reasons: ['Business name differs', 'Expiry unclear'],
      historical: false,
      includedInSummary: true,
    },
  }],
  documentUrls: ['/admin/shop-owners/17/documents/31'],
  status: 'pending',
  createdAt: '2026-10-21 09:00:00',
  ocrScreeningSummary: { state: 'assessed', score: 0, incomplete: false, historical: false },
  ...overrides,
});

const openDetails = () => fireEvent.click(screen.getByTitle('View Details'));

beforeEach(() => {
  getMock.mockReset();
  postMock.mockReset();
  swalFireMock.mockReset();
});

describe('shop-owner preliminary OCR review', () => {
  it('shows safe per-document evidence while keeping the original viewed-document guard independent', () => {
    render(<ShopOwnerRegistrationView registrations={[registration() as never]} />);
    openDetails();

    expect(screen.getByText('OCR Match Score — Preliminary')).toBeTruthy();
    expect(screen.getByText(disclaimer)).toBeTruthy();
    expect(screen.getAllByText(/0% — Conflicting Evidence Detected/)).toHaveLength(2);
    expect(screen.getByText('Reference day: 2026-10-21')).toBeTruthy();
    expect(screen.getByText('Business name differs')).toBeTruthy();
    expect(screen.getByText(/Business identity:\s*Conflict/)).toBeTruthy();
    expect(screen.getByText(/Dates:\s*Uncertain/)).toBeTruthy();
    expect(screen.getByText('Document recognition confidence: 94%')).toBeTruthy();
    expect(screen.getByText('Current server validity: Metadata unverified')).toBeTruthy();
    expect(screen.queryByText('Viewed')).toBeNull();
  });

  it('marks draft reviewer corrections stale without persisting or presenting the old score as current', () => {
    render(<ShopOwnerRegistrationView registrations={[registration() as never]} />);
    openDetails();

    expect(screen.getAllByText(/0% — Conflicting Evidence Detected/)).toHaveLength(2);
    fireEvent.change(screen.getByLabelText('Verified authority'), { target: { value: 'sec_registration' } });

    expect(screen.getByText(/Needs rescan/)).toBeTruthy();
    expect(screen.queryByText(/0% — Conflicting Evidence Detected/)).toBeNull();
    expect(postMock).not.toHaveBeenCalled();
  });

  it('marks draft date corrections stale while keeping current server validity visible', () => {
    render(<ShopOwnerRegistrationView registrations={[registration() as never]} />);
    openDetails();

    fireEvent.change(screen.getByLabelText('Verified issue date'), { target: { value: '2026-01-02' } });

    expect(screen.getByText(/Needs rescan/)).toBeTruthy();
    expect(screen.queryByText(/0% — Conflicting Evidence Detected/)).toBeNull();
    expect(screen.getByText('Current server validity: Metadata unverified')).toBeTruthy();
    expect(postMock).not.toHaveBeenCalled();
  });

  it('labels completed screening as historical and preserves the dated snapshot', () => {
    const baseDocument = registration().documents[0];
    const completedDocuments = [
      { ...baseDocument, status: 'approved', ocrScreening: { ...baseDocument.ocrScreening, score: 100, reasons: [], historical: true } },
      ...[
        ['mayors_permit', 'mayors_permit'],
        ['bir_certificate', 'bir_certificate'],
        ['valid_id', 'valid_id'],
      ].map(([type, slot], index) => ({
        ...baseDocument,
        id: 32 + index,
        url: `/admin/shop-owners/17/documents/${32 + index}`,
        type,
        documentType: type,
        logicalSlot: slot,
        status: 'approved',
        ocrScreening: { ...baseDocument.ocrScreening, score: 100, reasons: [], historical: true },
      })),
    ];
    const completed = registration({
      status: 'approved',
      ocrScreeningSummary: { state: 'assessed', score: 100, incomplete: false, historical: true },
      documents: completedDocuments,
    });
    render(<ShopOwnerRegistrationView registrations={[completed as never]} filters={{ status: 'all' }} />);
    openDetails();

    expect(screen.getByText('Historical screening for the latest reviewed documents.')).toBeTruthy();
    expect(screen.getAllByText(/100% — Automated Checks Passed/)).toHaveLength(5);
    expect(screen.getAllByText('Reference day: 2026-10-21')).toHaveLength(4);
    expect(screen.getAllByText('Historical').length).toBeGreaterThan(0);
  });

  it('keeps screening internals out of the explicit approval payload and preserves nullable reviewer corrections', () => {
    const document = {
      id: 31,
      url: '/admin/shop-owners/17/documents/31',
      documentType: 'dti_registration',
      logicalSlot: 'business_registration',
      versionNumber: 1,
      issuedOn: '2026-01-01',
      expirationMode: 'none' as const,
      expiresOn: null,
      ocrScreening: registration().documents[0].ocrScreening,
    };
    const payload = buildRegistrationApprovalPayload([document], new Set([0]), {
      31: { issuedOn: null, expiresOn: '2027-01-01' },
    });

    expect(payload.documents[0].issued_on).toBeNull();
    expect(payload.documents[0].expires_on).toBe('2027-01-01');
    expect(payload.documents[0]).not.toHaveProperty('ocr_screening');
    expect(payload.documents[0]).not.toHaveProperty('ocrScreening');
  });
});
