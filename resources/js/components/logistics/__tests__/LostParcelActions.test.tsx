import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { LogisticsIncident } from '@/types/logistics';
import LostParcelActions from '../LostParcelActions';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: { incidentWorkflow: mocks.post } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
const incident = (actions: string[]): LogisticsIncident => ({ id: 12, type: 'lost', status: 'under_review', revision: 1,
  loss: { stage: 'acknowledged', actions, parent_id: null, after_final: false, reporting_rider_profile_id: 3 } });

describe('Lost parcel actions in existing history and incident details', () => {
  it('requires an explicit warning acknowledgment before requesting final loss approval', async () => {
    mocks.post.mockResolvedValue({ data: { incident: incident([]) } });
    render(<LostParcelActions incident={incident(['confirm'])} onChanged={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm Lost' }));
    fireEvent.change(screen.getByLabelText('Reason or recovery details'), { target: { value: 'Parcel remains missing after search' } });
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox', { name: /understand/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(mocks.post).toHaveBeenCalledOnce());
    expect(mocks.post.mock.calls[0][1]).toBe('loss');
  });

  it('offers only server-authorized actions and requires photos for recovery', () => {
    render(<LostParcelActions incident={incident(['report_recovery'])} onChanged={vi.fn()} />);
    expect(screen.queryByRole('button', { name: 'Approve final loss' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Report Recovery' }));
    fireEvent.change(screen.getByLabelText('Reason or recovery details'), { target: { value: 'Found under the route bag' } });
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
    expect(screen.queryByLabelText('Actual custody')).not.toBeInTheDocument();
  });

  it('keeps failed submissions actionable with an error message', async () => {
    mocks.post.mockRejectedValue(new Error('Request failed'));
    render(<LostParcelActions incident={incident(['acknowledge'])} onChanged={vi.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Acknowledge report' }));
    fireEvent.change(screen.getByLabelText('Reason or recovery details'), { target: { value: 'Reviewed the report' } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Request failed'));
    expect(screen.getByRole('button', { name: 'Submit' })).toBeEnabled();
  });
});
