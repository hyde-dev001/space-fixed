import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Batches from '../Batches';

const mocks = vi.hoisted(() => ({ scheduleLegs: vi.fn().mockResolvedValue({}), reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { reload: mocks.reload },
  usePage: () => ({ props: {
    batches: [], pool: [], riders: [],
    unscheduled: [{ id: 7, status: 'pending', shipment: { source_type: 'order', source_id: 55 } }],
  } }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: {
  scheduleLegs: mocks.scheduleLegs,
  suggestions: vi.fn(), createBatch: vi.fn(), updateBatch: vi.fn(), removeBatchStop: vi.fn(), offerBatch: vi.fn(), markUrgent: vi.fn(),
} }));

it('lets a dispatcher schedule unscheduled orders', async () => {
  render(<Batches />);
  fireEvent.click(screen.getByRole('checkbox', { name: /order #55/i }));
  fireEvent.change(screen.getByLabelText('Schedule date'), { target: { value: '2026-07-14' } });
  fireEvent.click(screen.getByRole('button', { name: 'Schedule deliveries' }));

  await waitFor(() => expect(mocks.scheduleLegs).toHaveBeenCalledWith([7], '2026-07-14', 'morning'));
});
