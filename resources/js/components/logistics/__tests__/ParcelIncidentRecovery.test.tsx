import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ParcelRecovery } from '@/types/logistics';
import ParcelIncidentRecovery from '../ParcelIncidentRecovery';

const api = vi.hoisted(() => ({ parcelRecoveries: vi.fn(), parcelRecoveryAction: vi.fn(), resolveIncident: vi.fn() }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: api }));
vi.mock('@/Pages/ERP/Logistics/components/CustodyHandoffs', () => ({ default: () => null }));

const recovery = (): ParcelRecovery => ({ incident_id: 7, revision: 3, type: 'vehicle_problem', resolution: 'return_required',
  operational_status: 'under_review', source_type: 'order', source_id: 8, shipment_id: 9, delivery_id: 10,
  custody: 'rider', received: false, inspected: false, quarantined: false, next_action: 'receipt', complete: false,
  return_leg_id: 11, return_proof_id: 12, retry_context: { id: 10, sequence: 1, leg_type: 'outbound', status: 'needs_resolution' },
  expected_items: [{ reference: 'order-item:13', label: 'Original shoe', expected_quantity: 2 }],
  actions: [], can_close: false, source_outcome: {}, obligations: [], transfers: [], evidence_urls: ['/private-evidence/0'] });

beforeEach(() => { vi.clearAllMocks(); api.parcelRecoveries.mockResolvedValue({ data: { recoveries: [recovery()] } }); api.parcelRecoveryAction.mockResolvedValue({ data: {} }); });

it('scopes embedded return actions to the opened source without another recovery panel', async () => {
  api.parcelRecoveries.mockResolvedValue({ data: { recoveries: [recovery(), { ...recovery(), incident_id: 99, source_id: 99 }] } });
  render(<ParcelIncidentRecovery source="retail" sourceId={8} />);
  expect(await screen.findByText(/Incident #7/)).toBeVisible();
  expect(screen.queryByText(/Incident #99/)).not.toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Returned parcels requiring action' })).not.toBeInTheDocument();
});

it('keeps operational closure and outstanding refund work visible with a private evidence link', async () => {
  api.parcelRecoveries.mockResolvedValue({ data: { recoveries: [{ ...recovery(), operational_status: 'resolved',
    obligations: [{ type: 'refund', id: 20, status: 'requested', complete: false, next_actor: 'existing_refund_workflow' }] }] } });
  render(<ParcelIncidentRecovery source="retail" />);
  expect(await screen.findByText('Operationally closed — financial/source work pending')).toBeVisible();
  expect(screen.getByText(/refund #20: requested/)).toBeVisible();
  expect(screen.getByRole('link', { name: 'Private parcel evidence 1' })).toHaveAttribute('href', '/private-evidence/0');
  expect(screen.queryByRole('button', { name: 'Confirm actual shop receipt' })).not.toBeInTheDocument();
});

it('binds a real receipt upload to the incident revision and refreshes committed work', async () => {
  render(<ParcelIncidentRecovery source="retail" />);
  fireEvent.click(await screen.findByRole('button', { name: 'Confirm actual shop receipt' }));
  fireEvent.change(screen.getByLabelText('Recovery notes'), { target: { value: 'Two actual items received by Staff' } });
  fireEvent.change(screen.getByLabelText('Parcel recovery evidence'), { target: { files: [new File(['photo'], 'receipt.jpg', { type: 'image/jpeg' })] } });
  fireEvent.click(screen.getByRole('button', { name: 'Save verified recovery step' }));
  await waitFor(() => expect(api.parcelRecoveryAction).toHaveBeenCalledOnce());
  const [id, stage, payload] = api.parcelRecoveryAction.mock.calls[0];
  expect([id, stage]).toEqual([7, 'receipt']);
  expect(payload.get('expected_revision')).toBe('3');
  expect(payload.get('proof_id')).toBe('12');
  expect(payload.get('photo')).toBeInstanceOf(File);
  await waitFor(() => expect(api.parcelRecoveries).toHaveBeenCalledTimes(2));
});

it('shows a read failure as an error with a retry action', async () => {
  api.parcelRecoveries.mockRejectedValue(new Error('offline'));
  render(<ParcelIncidentRecovery source="repair" />);
  expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded');
  expect(screen.queryByText(/No parcels requiring action/)).not.toBeInTheDocument();
  api.parcelRecoveries.mockResolvedValue({ data: { recoveries: [] } });
  fireEvent.click(screen.getByRole('button', { name: 'Refresh parcel recovery' }));
  expect(await screen.findByText(/No parcels requiring action/)).toBeVisible();
});

it('keeps keyboard focus in recovery details and returns it to the action on Escape', async () => {
  render(<ParcelIncidentRecovery source="retail" />);
  const trigger = await screen.findByRole('button', { name: 'Confirm actual shop receipt' });
  trigger.focus();
  fireEvent.click(trigger);
  const dialog = screen.getByRole('dialog', { name: 'Parcel recovery incident 7' });
  expect(dialog).toContainElement(document.activeElement as HTMLElement);
  const close = screen.getByRole('button', { name: 'Close dialog' });
  close.focus();
  fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
  expect(close).not.toHaveFocus();
  expect(dialog).toContainElement(document.activeElement as HTMLElement);
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(trigger).toHaveFocus();
});


it('shows only incident-specific dispatcher decisions without a return handling panel', async () => {
  api.parcelRecoveries.mockResolvedValue({ data: { recoveries: [{ ...recovery(), can_inspect: true, can_close: true,
    actions: [
      { action: 'verify_readiness', label: 'Verify resolved condition and customer arrangement', allowed: true },
      { action: 'refund_review', label: 'Submit refund eligibility review', allowed: true },
      { action: 'inventory_disposition', label: 'Complete inspected item handling', allowed: true },
    ] }] } });
  render(<ParcelIncidentRecovery source="all" shipmentId={9} dispatcher />);
  const menu = await screen.findByText('Dispatcher follow-up - Incident #7');
  expect(menu.closest('details')).not.toHaveAttribute('open');
  expect(screen.queryByText('Return receipt and disposition')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Confirm actual shop receipt' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Record item inspection' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Submit refund eligibility review' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Complete inspected item handling' })).not.toBeInTheDocument();
  fireEvent.click(menu);
  expect(screen.getByText('Verify resolved condition and customer arrangement')).toBeInTheDocument();
  expect(screen.getByText('Verify operational closure')).toBeInTheDocument();
  fireEvent.click(screen.getByText('Verify resolved condition and customer arrangement'));
  fireEvent.change(screen.getByLabelText('Recovery notes'), { target: { value: 'Transport issue resolved after inspection' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save verified recovery step' }));
  await waitFor(() => expect(api.parcelRecoveryAction).toHaveBeenCalledOnce());
  const [id, stage, payload] = api.parcelRecoveryAction.mock.calls[0];
  expect([id, stage, payload.get('action'), payload.get('expected_revision')]).toEqual([7, 'disposition', 'verify_readiness', '3']);
});

it('hides dispatcher follow-up when only Staff return work is pending', async () => {
  render(<ParcelIncidentRecovery source="all" shipmentId={9} dispatcher />);
  await waitFor(() => expect(api.parcelRecoveries).toHaveBeenCalledOnce());
  await waitFor(() => expect(screen.queryByText(/Loading/)).not.toBeInTheDocument());
  expect(screen.queryByText(/Dispatcher follow-up/)).not.toBeInTheDocument();
  expect(screen.queryByText('Return receipt and disposition')).not.toBeInTheDocument();
});
