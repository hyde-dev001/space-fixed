import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BusinessScalingSettings from '../BusinessScalingSettings';

const { routerPostMock, axiosPatchMock, swalFireMock } = vi.hoisted(() => ({
  routerPostMock: vi.fn(),
  axiosPatchMock: vi.fn(),
  swalFireMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { post: routerPostMock },
}));

vi.mock('axios', () => ({
  default: { patch: axiosPatchMock },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

const state = {
  current: { registration_type: 'individual', business_type: 'retail' },
  available_account_transitions: [
    { key: 'individual_to_company', label: 'Business account', requested_registration_type: 'company', requested_business_type: 'retail' },
  ],
  available_capability_transitions: [
    { key: 'retail_to_both', label: 'Retail + Repair', requested_registration_type: 'individual', requested_business_type: 'both' },
  ],
  available_combined_transitions: [
    { key: 'individual_retail_to_company_both', label: 'Business account + both capabilities', requested_registration_type: 'company', requested_business_type: 'both' },
  ],
  pending_request: null,
  latest_terminal_request: null,
  required_evidence: [
    { key: 'dti_registration', title: 'Business Registration (DTI)', description: 'Registration certificate', required: true },
    { key: 'mayors_permit', title: "Mayor's Permit / Business Permit", description: 'Current permit', required: true },
    { key: 'bir_certificate', title: 'BIR Certificate of Registration (COR)', description: 'Tax registration', required: true },
    { key: 'valid_id', title: 'Valid ID of Owner', description: 'Government ID', required: true },
  ],
  modules: {
    retail_operations: { eligible: true, enabled: true, accessible: true, code: null, reason: null },
    repair_operations: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Upgrade to repair capability first.' },
    hr_employees: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Business accounts only.' },
    finance: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Business accounts only.' },
    crm: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Business accounts only.' },
    inventory: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Business accounts only.' },
    procurement: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Business accounts only.' },
    logistics: { eligible: false, enabled: false, accessible: false, code: 'MODULE_INELIGIBLE', reason: 'Business accounts only.' },
  },
};

beforeEach(() => {
  routerPostMock.mockReset();
  axiosPatchMock.mockReset();
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true });
});

describe('BusinessScalingSettings', () => {
  it('shows the current state, available upgrade choices, and required evidence', () => {
    render(<BusinessScalingSettings businessScaling={state} />);

    expect(screen.getByRole('heading', { name: /business scaling/i })).toBeInTheDocument();
    expect(screen.getByText(/individual retail/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /upgrade choice/i }));
    expect(screen.getByRole('option', { name: /^business account$/i })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: /retail \+ repair/i })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: /business account \+ both capabilities/i })).toBeInTheDocument();
    expect(screen.getByText('Business Registration (DTI)')).toBeInTheDocument();
    expect(screen.getByText('Valid ID of Owner')).toBeInTheDocument();
  });

  it('reports pending and terminal review feedback without exposing storage paths', () => {
    render(
      <BusinessScalingSettings
        businessScaling={{
          ...state,
          pending_request: {
            id: 9,
            status: 'pending',
            current_registration_type: 'individual',
            current_business_type: 'retail',
            requested_registration_type: 'company',
            requested_business_type: 'retail',
            decision_reason: null,
            submitted_at: '2026-08-10T00:00:00Z',
            reviewed_at: null,
            documents: [],
          },
          latest_terminal_request: {
            id: 8,
            status: 'rejected',
            current_registration_type: 'individual',
            current_business_type: 'retail',
            requested_registration_type: 'company',
            requested_business_type: 'retail',
            decision_reason: 'Please provide a clearer permit.',
            submitted_at: '2026-08-01T00:00:00Z',
            reviewed_at: '2026-08-02T00:00:00Z',
            documents: [],
          },
        }}
      />,
    );

    expect(screen.getByText(/pending review/i)).toBeInTheDocument();
    expect(screen.getByText('Please provide a clearer permit.')).toBeInTheDocument();
    expect(screen.queryByText(/shop-owner-upgrade-evidence/i)).not.toBeInTheDocument();
  });

  it('submits the selected transition and disables duplicate submission while processing', async () => {
    let callbacks: { onSuccess?: () => void } = {};
    routerPostMock.mockImplementation((_url: string, _data: FormData, options: { onSuccess?: () => void }) => {
      callbacks = options;
    });
    render(<BusinessScalingSettings businessScaling={state} />);

    fireEvent.click(screen.getByRole('button', { name: /upgrade choice/i }));
    fireEvent.click(screen.getByRole('option', { name: /^business account$/i }));
    const documentInputs = Array.from(document.querySelectorAll('input[type="file"]'));
    documentInputs.forEach((input) => {
      fireEvent.change(input, { target: { files: [new File(['evidence'], 'evidence.pdf', { type: 'application/pdf' })] } });
    });
    fireEvent.click(screen.getByRole('button', { name: /submit upgrade request/i }));

    expect(routerPostMock).toHaveBeenCalledWith(
      '/shop-owner/settings/business-upgrade',
      expect.any(FormData),
      expect.objectContaining({ forceFormData: true }),
    );
    expect(screen.getByRole('button', { name: /submitting/i })).toBeDisabled();

    act(() => callbacks.onSuccess?.());
    await waitFor(() => expect(screen.getByRole('button', { name: /submit upgrade request/i })).toBeEnabled());
  });

  it('keeps the authoritative module state on denied or failed toggles', async () => {
    axiosPatchMock.mockRejectedValueOnce({ response: { data: { message: 'This module cannot be changed.' } } });
    render(<BusinessScalingSettings businessScaling={state} />);

    fireEvent.click(screen.getByRole('button', { name: /toggle retail operations/i }));

    await waitFor(() => expect(screen.getByText('This module cannot be changed.')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /toggle retail operations/i })).toHaveAttribute('aria-pressed', 'true');
  });

  it('uses a SweetAlert confirmation before disabling a module', async () => {
    swalFireMock.mockResolvedValueOnce({ isConfirmed: true });
    axiosPatchMock.mockResolvedValueOnce({ data: { states: state.modules } });
    render(<BusinessScalingSettings businessScaling={state} />);

    fireEvent.click(screen.getByRole('button', { name: /toggle retail operations/i }));

    await waitFor(() => expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Disable Retail operations?',
      showCancelButton: true,
      confirmButtonText: 'Disable module',
    })));
    await waitFor(() => expect(axiosPatchMock).toHaveBeenCalledWith(
      '/shop-owner/settings/modules/retail_operations',
      { enabled: false },
    ));
  });

  it('shows upload validation and explains disabled or ineligible modules', () => {
    render(<BusinessScalingSettings businessScaling={state} />);

    fireEvent.change(document.querySelector('input[type="file"]'), {
      target: { files: [new File(['text'], 'evidence.txt', { type: 'text/plain' })] },
    });

    expect(screen.getByText('Use a PDF, JPG, JPEG, or PNG file.')).toBeInTheDocument();
    expect(screen.getByText('Upgrade to repair capability first.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /toggle repair operations/i })).toBeDisabled();
  });

  it('replaces local module state with the authoritative toggle response', async () => {
    axiosPatchMock.mockResolvedValueOnce({
      data: {
        states: {
          ...state.modules,
          retail_operations: { ...state.modules.retail_operations, enabled: false, accessible: false, code: 'MODULE_DISABLED', reason: 'This module is disabled for the shop.' },
        },
      },
    });
    render(<BusinessScalingSettings businessScaling={state} />);

    fireEvent.click(screen.getByRole('button', { name: /toggle retail operations/i }));

    await waitFor(() => expect(screen.getByRole('button', { name: /toggle retail operations/i })).toHaveAttribute('aria-pressed', 'false'));
  });

  it('retains the last authoritative state when the toggle response is stale', async () => {
    axiosPatchMock.mockResolvedValueOnce({ data: { states: { retail_operations: { enabled: false } } } });
    render(<BusinessScalingSettings businessScaling={state} />);

    fireEvent.click(screen.getByRole('button', { name: /toggle retail operations/i }));

    await waitFor(() => expect(screen.getByText(/last confirmed state was kept/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /toggle retail operations/i })).toHaveAttribute('aria-pressed', 'true');
  });

  it.each([
    ['approved', 'Approved request'],
    ['superseded', 'Superseded request'],
  ] as const)('shows %s terminal feedback', (status, label) => {
    render(
      <BusinessScalingSettings
        businessScaling={{
          ...state,
          latest_terminal_request: {
            id: 11,
            status,
            current_registration_type: 'individual',
            current_business_type: 'retail',
            requested_registration_type: 'company',
            requested_business_type: 'both',
            decision_reason: status === 'approved' ? null : 'A newer request was reviewed first.',
            submitted_at: '2026-08-01T00:00:00Z',
            reviewed_at: '2026-08-02T00:00:00Z',
            documents: [],
          },
        }}
      />,
    );

    expect(screen.getByText(label)).toBeInTheDocument();
  });
});
