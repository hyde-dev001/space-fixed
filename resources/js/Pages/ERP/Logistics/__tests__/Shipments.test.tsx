import React from 'react';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Shipments from '../Shipments';

vi.mock('@inertiajs/react', () => ({
  Head: () => null, Link: ({ children }: React.PropsWithChildren) => <a>{children}</a>, router: { get: vi.fn(), reload: vi.fn() },
  usePage: () => ({ props: {
    shipments: { data: [{ id: 1, purpose: 'retail_delivery', status: 'active', source_type: 'order', source_id: 10, legs: [{
      id: 2, leg_type: 'outbound', status: 'assigned', assignments: [], proofs: [], attempts: [],
      destination_snapshot: { name: 'Miguel Dela Rosa', address: 'Dasmariñas, Cavite', phone: '09053338826' },
    }] }], links: [], from: 1, to: 1, total: 1, current_page: 1, last_page: 1 },
    filters: { status: 'all', purpose: 'all', window: 'all' }, assignableRiders: [],
    canAssign: false, canUpdateStatus: false, canRecordProof: false, canApproveProof: false, riderMode: true, batches: [],
  } }),
}));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('sweetalert2', () => ({ default: { fire: vi.fn() } }));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));

it('shows receiver and address in the delivery table', () => {
  render(<Shipments><p>Batch panel</p></Shipments>);
  expect(screen.getByText('Batch panel')).toBeInTheDocument();
  expect(screen.getByText('Miguel Dela Rosa')).toBeInTheDocument();
  expect(screen.getByText('Dasmariñas, Cavite')).toBeInTheDocument();
});
