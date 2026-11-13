import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import RetryResolutionDialog from '../components/RetryResolutionDialog';
import type { TrackingShipmentLeg } from '@/types/logistics';

const leg: TrackingShipmentLeg = { id: 7, sequence: 1, leg_type: 'outbound', status: 'needs_resolution' };
const props = { leg, riders: [{ id: 8, name: 'Eligible Rider' }], today: '2026-10-27', error: null, onClose: vi.fn() };

it('keeps customer-held inbound collection direct and explains the unavailable outbound route', () => {
  render(<RetryResolutionDialog {...props} leg={{ ...leg, leg_type: 'inbound', custody_state: 'customer', destination_snapshot: { type: 'shop' } }} onSubmit={vi.fn()} />);
  expect(screen.getByRole('option', { name: 'Return to shop, then release to assigned rider' })).toBeDisabled();
  expect(screen.getByText(/Current parcel holder: Customer/)).toBeVisible();
  expect(screen.getByText(/already shop-bound/)).toBeVisible();
});

it('requires bounded immediate authority and sends it with a distinct retry identity', async () => {
  const submit = vi.fn().mockResolvedValue(true);
  render(<RetryResolutionDialog {...props} onSubmit={submit} />);
  fireEvent.change(screen.getByLabelText('Retry rider'), { target: { value: '8' } });
  fireEvent.change(screen.getByLabelText('Resolution reason'), { target: { value: 'Vehicle restored; customer arrangement checked' } });
  fireEvent.click(screen.getByLabelText('Authorize a bounded immediate recovery'));
  expect(screen.getByRole('button', { name: 'Authorize retry' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Effective start'), { target: { value: '2026-10-27T10:00' } });
  fireEvent.change(screen.getByLabelText('Authorization expiry'), { target: { value: '2026-10-27T10:30' } });
  fireEvent.click(screen.getByRole('button', { name: 'Authorize retry' }));
  await waitFor(() => expect(submit).toHaveBeenCalledOnce());
  expect(submit.mock.calls[0][0]).toMatchObject({ immediate_recovery: true, effective_start: '2026-10-27T10:00',
    effective_end: '2026-10-27T10:30', rider_profile_id: 8, request_key: expect.stringMatching(/^[0-9a-f-]{36}$/) });
});
