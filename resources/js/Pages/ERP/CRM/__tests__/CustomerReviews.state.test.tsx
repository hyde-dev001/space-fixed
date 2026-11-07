import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CustomerReviews from '../CustomerReviews';

const mocks = vi.hoisted(() => ({
  post: vi.fn(), fire: vi.fn(), reported: false,
}));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { initialReviews: [{
    id: 1, reviewId: 'shop_1', customerName: 'Customer A', rating: 1,
    comment: 'Review A', feedbackImages: [], serviceType: 'Shop', orderType: 'repair',
    createdAt: '2026-10-06', is_reported: mocks.reported,
  }], auth: {} } }),
}));
vi.mock('../../../../layout/AppLayout_ERP', () => ({ default: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('sweetalert2', () => ({ default: { fire: mocks.fire } }));

afterEach(cleanup);
beforeEach(() => {
  vi.clearAllMocks();
  mocks.reported = false;
  mocks.fire.mockResolvedValue({ isConfirmed: true });
  mocks.post.mockResolvedValue({ data: { is_reported: true, report: { id: 1, status: 'pending_review' } } });
});

describe('review report state', () => {
  it('shows persisted backend report history as a disabled Reported button', () => {
    mocks.reported = true;
    render(<CustomerReviews />);
    fireEvent.click(screen.getByTitle('View feedback from Customer A'));
    expect(screen.getByRole('button', { name: 'Reported' })).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Report Review' })).not.toBeInTheDocument();
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it('updates the open detail and reopened detail after successful reporting', async () => {
    render(<CustomerReviews />);
    fireEvent.click(screen.getByTitle('View feedback from Customer A'));
    fireEvent.click(screen.getByRole('button', { name: 'Report Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Submit Report' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Reported' })).toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    fireEvent.click(screen.getByTitle('View feedback from Customer A'));
    expect(screen.getByRole('button', { name: 'Reported' })).toBeDisabled();
    expect(mocks.post).toHaveBeenCalledTimes(1);
  });
});
