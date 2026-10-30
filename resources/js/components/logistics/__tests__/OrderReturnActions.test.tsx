import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ParcelRecovery } from '@/types/logistics';
import OrderReturnActions from '../OrderReturnActions';

const api = vi.hoisted(() => ({ parcelRecoveryAction: vi.fn() }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: api }));
vi.mock('@/Pages/ERP/Logistics/components/CustodyHandoffs', () => ({ default: () => null }));
const row = (): ParcelRecovery => ({ incident_id: 7, revision: 3, type: 'vehicle_problem', resolution: 'return_required',
  operational_status: 'under_review', source_type: 'order', source_id: 8, shipment_id: 9, delivery_id: 10,
  custody: 'rider', received: false, inspected: false, quarantined: false, next_action: 'receipt', complete: false,
  can_receive: true, receipt_recorded: false, return_leg_id: 11, return_proof_id: 12,
  retry_context: { id: 10, sequence: 1, leg_type: 'outbound', status: 'needs_resolution' },
  expected_items: [{ reference: 'order-item:13', label: 'Original shoe', expected_quantity: 2 }],
  actions: [], can_close: false, source_outcome: {}, obligations: [], transfers: [] });
beforeEach(() => { vi.clearAllMocks(); });

it('exposes actual receipt directly without the obsolete panel and prevents duplicate submission', async () => {
  let finish!: () => void;
  api.parcelRecoveryAction.mockImplementation(() => new Promise<void>(resolve => { finish = resolve; }));
  const changed = vi.fn().mockResolvedValue(undefined);
  render(<OrderReturnActions returns={[row()]} onChanged={changed} />);
  expect(screen.queryByText('Return receipt and disposition')).not.toBeInTheDocument();
  expect(screen.queryByText('Refresh parcel recovery')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Confirm Parcel Received' }));
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Handling notes'), { target: { value: 'Actual parcel accepted at the shop' } });
  fireEvent.change(screen.getByLabelText('Return evidence'), { target: { files: [new File(['photo'], 'receipt.png', { type: 'image/png' })] } });
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  fireEvent.click(screen.getByRole('button', { name: 'Saving...' }));
  expect(api.parcelRecoveryAction).toHaveBeenCalledTimes(1);
  const [id, stage, form] = api.parcelRecoveryAction.mock.calls[0];
  expect(id).toBe(7); expect(stage).toBe('receipt'); expect(form.get('proof_id')).toBe('12');
  expect(form.get('expected_revision')).toBe('3');
  finish(); await waitFor(() => expect(changed).toHaveBeenCalledOnce());
});

it('records actual quantities and keeps a failed inspection actionable', async () => {
  api.parcelRecoveryAction.mockRejectedValue(new Error('Changed revision'));
  render(<OrderReturnActions returns={[{ ...row(), can_receive: false, receipt_recorded: true, received: true, can_inspect: true }]} onChanged={vi.fn()} />);
  fireEvent.click(screen.getByRole('button', { name: 'Inspect Returned Items' }));
  expect(screen.getByLabelText('Actual quantity')).toHaveValue(0);
  fireEvent.change(screen.getByLabelText('Actual quantity'), { target: { value: '1' } });
  fireEvent.change(screen.getByLabelText('Item condition'), { target: { value: 'damaged' } });
  fireEvent.change(screen.getByLabelText('Handling notes'), { target: { value: 'One actual damaged shoe received' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
  const form = api.parcelRecoveryAction.mock.calls[0][2] as FormData;
  expect(form.get('items[0][quantity]')).toBe('1'); expect(form.get('items[0][condition]')).toBe('damaged');
  expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
});
