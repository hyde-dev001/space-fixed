import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { BatchInterruptionDialog } from '../components/BatchInterruptionDialog';
import type { BatchInterruption } from '@/types/logistics';

const mocks = vi.hoisted(() => ({ report: vi.fn(), resolve: vi.fn(), reload: vi.fn() }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: { reportBatchInterruption: mocks.report, resolveIncident: mocks.resolve } }));
vi.mock('@inertiajs/react', () => ({ router: { reload: mocks.reload } }));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.report.mockResolvedValue({});
  mocks.resolve.mockResolvedValue({});
  mocks.reload.mockImplementation((options: { onFinish?: () => void }) => options.onFinish?.());
});

const incident: BatchInterruption = { id: 31, revision: 2, source_batch_id: 7, status: 'under_review', required_parcels: 1, confirmed_parcels: 0,
  stops: [{ shipment_leg_id: 19, delivery_reference: 'Delivery #19', sequence: 1, status: 'in_transit', custody_state: 'rider',
    requires_handoff: true, handoff_complete: false, transfer_status: null, customer_appointment_date: '2026-10-24', customer_appointment_window: 'morning' }] };

it('requires a note and image, sends original private evidence and closes after reload', async () => {
  const close = vi.fn();
  render(<BatchInterruptionDialog batchId={7} today="2026-10-23" canAssign={false} riders={[]} onClose={close} />);
  expect(screen.getByRole('button', { name: 'Hold remaining stops' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Breakdown details'), { target: { value: 'Engine cannot safely continue.' } });
  const photo = new File(['synthetic bytes'], 'vehicle.jpg', { type: 'image/jpeg' });
  fireEvent.change(screen.getByLabelText('Vehicle evidence'), { target: { files: [photo] } });
  expect(screen.getByRole('button', { name: 'Hold remaining stops' })).toBeEnabled();
  fireEvent.submit(screen.getByRole('dialog', { name: 'Report vehicle breakdown' }));
  await waitFor(() => expect(mocks.report).toHaveBeenCalledTimes(1));
  const [id, form] = mocks.report.mock.calls[0];
  expect(id).toBe(7);
  expect(form.get('photo_files[]')).toBe(photo);
  expect(form.get('request_key')).toMatch(/^[0-9a-f-]{36}$/);
  await waitFor(() => expect(close).toHaveBeenCalledTimes(1));
  expect(mocks.reload.mock.calls[0][0].only).toContain('batches');
});

it('blocks duplicate pending submissions and shows a recoverable server error', async () => {
  let fail: (reason: unknown) => void = () => {};
  mocks.resolve.mockReturnValue(new Promise((_, reject) => { fail = reject; }));
  render(<BatchInterruptionDialog batchId={7} incident={incident} today="2026-10-23" canAssign riders={[]} onClose={vi.fn()} />);
  fireEvent.change(screen.getByLabelText('Decision reason'), { target: { value: 'Vehicle repaired.' } });
  const button = screen.getByRole('button', { name: 'Save recovery decision' });
  fireEvent.click(button);
  fireEvent.click(button);
  expect(mocks.resolve).toHaveBeenCalledTimes(1);
  fail(new Error('synthetic unavailable transport'));
  await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('could not be confirmed'));
  expect(screen.getByRole('button', { name: 'Save recovery decision' })).toBeEnabled();
});

it('sends separate customer agreement notes and the expected incident revision for takeover', async () => {
  render(<BatchInterruptionDialog batchId={7} incident={incident} today="2026-10-23" canAssign riders={[{ id: 8, name: 'Replacement Rider' }]} onClose={vi.fn()} />);
  fireEvent.change(screen.getByLabelText('Recovery decision'), { target: { value: 'reassign' } });
  fireEvent.change(screen.getByLabelText('Replacement rider'), { target: { value: '8' } });
  fireEvent.change(screen.getByLabelText('Recovery date'), { target: { value: '2026-10-25' } });
  fireEvent.change(screen.getByLabelText(/Customer agreement for Delivery #19/), { target: { value: 'Customer agreed through normal contact.' } });
  fireEvent.change(screen.getByLabelText('Decision reason'), { target: { value: 'Replacement vehicle and rider confirmed.' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save recovery decision' }));
  await waitFor(() => expect(mocks.resolve).toHaveBeenCalledWith(31, expect.objectContaining({ expected_revision: 2, resolution: 'reassign', rider_profile_id: 8,
    appointment_agreements: { 19: 'Customer agreed through normal contact.' }, delivery_date: '2026-10-25' })));
});

it('keeps assignment controls unavailable without assignment capability', () => {
  render(<BatchInterruptionDialog batchId={7} incident={incident} today="2026-10-23" canAssign={false} riders={[]} onClose={vi.fn()} />);
  expect(screen.queryByRole('option', { name: 'Assign a replacement rider' })).not.toBeInTheDocument();
});

it('explains empty riders and uses the established date picker with configured hours',()=>{
 render(<BatchInterruptionDialog batchId={7} incident={incident} today="2026-10-23" canAssign riders={[]} schedule={{operating_days:[1,2,3,4,5,6,7],blackout_dates:[],morning_start:'08:30',morning_end:'11:30',timezone:'Asia/Manila'}} onClose={vi.fn()}/>);
 fireEvent.change(screen.getByLabelText('Recovery decision'),{target:{value:'reassign'}});
 expect(screen.getByText(/No active available riders/)).toBeInTheDocument();
 expect(screen.getByRole('button',{name:'Open recovery date picker'})).toBeEnabled();
 expect(screen.getByRole('option',{name:'Morning (08:30 - 11:30)'})).toBeInTheDocument();
 fireEvent.click(screen.getByRole('button',{name:'Open recovery date picker'}));
 fireEvent.keyDown(screen.getByRole('dialog',{name:'Recovery date calendar'}),{key:'Escape'});
 expect(screen.queryByRole('dialog',{name:'Recovery date calendar'})).not.toBeInTheDocument();
 expect(screen.getByRole('dialog',{name:'Resolve batch interruption'})).toBeInTheDocument();
});
