import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Batches from '../Batches';

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  scheduleLegs: vi.fn(),
  createBatch: vi.fn(),
  offerBatch: vi.fn(),
  reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { reload: mocks.reload },
  usePage: () => ({ props: mocks.props }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: {
  scheduleLegs: mocks.scheduleLegs,
  createBatch: mocks.createBatch,
  offerBatch: mocks.offerBatch,
  suggestions: vi.fn(), updateBatch: vi.fn(), removeBatchStop: vi.fn(), markUrgent: vi.fn(),
} }));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.props = {
    batches: [],
    pool: [],
    riders: [{ id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 }],
    unscheduled: [{ id: 7, status: 'pending', shipment: { source_type: 'order', source_id: 55 } }],
  };
  mocks.scheduleLegs.mockResolvedValue({});
  mocks.createBatch.mockResolvedValue({ data: { batch: { id: 41 } } });
  mocks.offerBatch.mockResolvedValue({});
});

function selectOrderAndSchedule() {
  fireEvent.click(screen.getByRole('checkbox', { name: /order #55/i }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-15' } });
}

it('schedules, creates, then offers a batch to the selected rider', async () => {
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.change(screen.getByLabelText('Rider'), { target: { value: '3' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create & offer batch' }));

  await waitFor(() => expect(mocks.offerBatch).toHaveBeenCalledWith(41, 3));
  expect(mocks.scheduleLegs).toHaveBeenCalledWith([7], '2026-07-15', 'morning');
  expect(mocks.createBatch).toHaveBeenCalledWith({ delivery_date: '2026-07-15', delivery_window: 'morning', leg_ids: [7] });
  expect(mocks.scheduleLegs.mock.invocationCallOrder[0]).toBeLessThan(mocks.createBatch.mock.invocationCallOrder[0]);
  expect(mocks.createBatch.mock.invocationCallOrder[0]).toBeLessThan(mocks.offerBatch.mock.invocationCallOrder[0]);
});

it('creates a draft when no rider is selected', async () => {
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));

  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());
  expect(mocks.offerBatch).not.toHaveBeenCalled();
});

it('schedules only the unscheduled subset of a mixed selection', async () => {
  mocks.props.pool = [{ id: 8, status: 'pending', scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }];
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('checkbox', { name: /leg #8/i }));
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));

  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());
  expect(mocks.scheduleLegs).toHaveBeenCalledWith([7], '2026-07-15', 'morning');
  expect(mocks.createBatch).toHaveBeenCalledWith({ delivery_date: '2026-07-15', delivery_window: 'morning', leg_ids: [7, 8] });
});
