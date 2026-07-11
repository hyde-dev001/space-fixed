import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import MyDeliveries from '../MyDeliveries';

const { acceptBatch } = vi.hoisted(() => ({ acceptBatch: vi.fn(() => Promise.resolve()) }));
const shipment = { id: 1, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'offered', legs: [{ id: 2, stop_sequence: 1 }] };
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { batches: [shipment] } }), router: { reload: vi.fn() } }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: { acceptBatch, rejectBatch: vi.fn(), startBatch: vi.fn() } }));
vi.mock('../Shipments', () => ({ default: () => <div>Individual deliveries</div> }));

describe('MyDeliveries', () => {
  it('shows ordered offered batch controls', () => {
    render(<MyDeliveries />);
    expect(screen.getByText(/Stop 1: Leg #2/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
    expect(acceptBatch).toHaveBeenCalledWith(1);
    expect(screen.getByRole('button', { name: 'Reject' })).toBeDisabled();
  });
});
