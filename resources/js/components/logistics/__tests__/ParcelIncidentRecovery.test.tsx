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
