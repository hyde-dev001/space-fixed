import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Shipments from '../Shipments';

const mocks = vi.hoisted(() => ({ post: vi.fn(() => Promise.resolve()), reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  Head: () => null, Link: ({ children }: React.PropsWithChildren) => <a>{children}</a>, router: { get: vi.fn(), reload: mocks.reload },
  usePage: () => ({ props: {
    shipments: { data: [{ id: 1, purpose: 'retail_delivery', status: 'active', source_type: 'order', source_id: 10, legs: [{
      id: 2, leg_type: 'outbound', status: 'in_transit', assignments: [], proofs: [], attempts: [],
      destination_snapshot: { name: 'Miguel Dela Rosa', address: 'Dasmariñas, Cavite', phone: '09053338826' },
    }] }], links: [], from: 1, to: 1, total: 1, current_page: 1, last_page: 1 },
    filters: { status: 'all', purpose: 'all', window: 'all' }, assignableRiders: [],
    canAssign: false, canUpdateStatus: false, canRecordProof: true, canApproveProof: false, riderMode: true, batches: [],
  } }),
}));
vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('sweetalert2', () => ({ default: { fire: vi.fn() } }));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));

beforeEach(() => vi.clearAllMocks());

it('shows receiver and address in the delivery table', () => {
  render(<Shipments><p>Batch panel</p></Shipments>);
  expect(screen.getByText('Batch panel')).toBeInTheDocument();
  expect(screen.getByText('Miguel Dela Rosa')).toBeInTheDocument();
  expect(screen.getByText('Dasmariñas, Cavite')).toBeInTheDocument();
});

it('shows one delivery outcome workflow at a time and clears hidden state', () => {
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.queryByLabelText('Delivery proof photo')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Issue photo')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Delivered successfully' }));
  const deliveryPhoto = screen.getByLabelText('Delivery proof photo');
  fireEvent.change(deliveryPhoto, { target: { files: [new File(['proof'], 'proof.jpg', { type: 'image/jpeg' })] } });
  expect(screen.getByRole('button', { name: 'Submit proof' })).toBeInTheDocument();
  expect(screen.queryByLabelText('Issue reason')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  expect(screen.queryByLabelText('Delivery proof photo')).not.toBeInTheDocument();
  const issueReason = screen.getByLabelText('Issue reason');
  const issuePhoto = screen.getByLabelText('Issue photo');
  const issueNote = screen.getByPlaceholderText('Optional note');
  const reportIssue = screen.getByRole('button', { name: 'Report issue' });
  expect(reportIssue).toBeDisabled();
  fireEvent.change(issueReason, { target: { value: 'recipient_refused' } });
  fireEvent.change(issuePhoto, { target: { files: [new File(['attempt'], 'attempt.jpg', { type: 'image/jpeg' })] } });
  fireEvent.change(issueNote, { target: { value: 'Customer declined.' } });
  expect(reportIssue).toBeEnabled();

  fireEvent.click(screen.getByRole('button', { name: 'Delivered successfully' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit proof' }));
  expect(mocks.post).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  expect(screen.getByLabelText('Issue reason')).toHaveValue('');
  expect(screen.getByPlaceholderText('Optional note')).toHaveValue('');
  expect(screen.getByRole('button', { name: 'Report issue' })).toBeDisabled();
});

it('submits required issue evidence only to the failed-attempt endpoint', async () => {
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  const reportIssue = screen.getByRole('button', { name: 'Report issue' });
  const attemptPhoto = new File(['attempt'], 'attempt.jpg', { type: 'image/jpeg' });

  expect(reportIssue).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Issue reason'), { target: { value: 'recipient_unavailable' } });
  expect(reportIssue).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Issue photo'), { target: { files: [attemptPhoto] } });
  expect(reportIssue).toBeEnabled();
  fireEvent.click(reportIssue);

  await waitFor(() => expect(mocks.post).toHaveBeenCalled());
  const [url, body] = mocks.post.mock.calls[0];
  expect(url).toBe('/api/logistics/legs/2/report-issue');
  expect(body).toBeInstanceOf(FormData);
  expect((body as FormData).get('reason_code')).toBe('recipient_unavailable');
  expect((body as FormData).get('proof_file')).toBe(attemptPhoto);
  expect(mocks.post.mock.calls.some(([requestUrl]) => requestUrl === '/api/logistics/legs/2/proof')).toBe(false);
  expect(mocks.reload).toHaveBeenCalledWith({ only: ['shipments', 'batches'] });
});
