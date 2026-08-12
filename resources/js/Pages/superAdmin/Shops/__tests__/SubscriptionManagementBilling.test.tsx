import type { ReactNode } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  page: {
    props: {
      subscriptions: [
        {
          id: 1,
          shop: { id: 10, business_name: 'Eligible Shoes', owner_name: 'Owner One', email: 'owner@example.test' },
          premium_plan: { id: 1, name: 'Basic', price: 249, duration_days: 30 },
          plan_code: 'basic',
          showroom_slot_limit: 48,
          status: 'active',
          amount_paid: 249,
          refunded_amount: 0,
          net_collected: 249,
          starts_at: '2026-08-01T00:00:00Z',
          ends_at: '2099-08-31T00:00:00Z',
          next_billing_at: '2099-08-31T00:00:00Z',
          cancellation_reason: null,
          cancellation_notes: null,
          can_cancel: true,
          legacy_correction_available: false,
          eligible_for_refund: true,
          refund_payment_id: 7,
          refund_attempts: [],
          payments: [{ id: 7, payment_type: 'new_subscription', amount_paid: 249, status: 'paid', currency: 'PHP', paid_at: '2026-08-01T00:00:00Z', refunds: [] }],
          created_at: '2026-08-01T00:00:00Z',
        },
        {
          id: 2,
          shop: { id: 11, business_name: 'Legacy Shoes', owner_name: 'Owner Two', email: 'legacy@example.test' },
          premium_plan: { id: 1, name: 'Basic', price: 249, duration_days: 30 },
          plan_code: 'basic',
          showroom_slot_limit: 48,
          status: 'deactivated',
          amount_paid: 0,
          refunded_amount: 0,
          net_collected: 0,
          starts_at: null,
          ends_at: null,
          next_billing_at: null,
          cancellation_reason: null,
          cancellation_notes: null,
          can_cancel: false,
          legacy_correction_available: true,
          eligible_for_refund: false,
          refund_payment_id: null,
          refund_attempts: [],
          payments: [],
          created_at: '2026-08-02T00:00:00Z',
        },
      ],
      stats: { active: 1, expired: 1, total_revenue: 249, gross_collected: 249, refunded_amount: 0, net_collected: 249, expiring_soon: 0 },
      plans: [],
    },
  },
  routerPost: vi.fn(),
  routerReload: vi.fn(),
  swalFire: vi.fn(),
  axiosPost: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { post: mocks.routerPost, reload: mocks.routerReload },
  usePage: () => mocks.page,
  useForm: () => ({
    data: { plan_code: '', name: '', description: '', price: '', duration_days: 30, showroom_slot_limit: 48, benefits: [] },
    errors: {},
    processing: false,
    clearErrors: vi.fn(),
    setData: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  }),
}));

vi.mock('axios', () => ({ default: { post: mocks.axiosPost } }));
vi.mock('../../../../layout/AppLayout', () => ({ default: ({ children }: { children?: ReactNode }) => <div>{children}</div> }));
vi.mock('../../../../components/ui/button/Button', () => ({ default: ({ children, onClick }: { children?: ReactNode; onClick?: () => void }) => <button type="button" onClick={onClick}>{children}</button> }));
vi.mock('sweetalert2', () => ({ default: { fire: mocks.swalFire } }));

import SubscriptionManagement from '../SubscriptionManagement';

beforeEach(() => {
  mocks.routerPost.mockReset();
  mocks.routerReload.mockReset();
  mocks.axiosPost.mockReset();
  mocks.axiosPost.mockResolvedValue({ data: { success: true } });
  mocks.swalFire.mockReset();
  mocks.swalFire.mockResolvedValue({ isConfirmed: true, value: 'reduce_costs' });
});

describe('SubscriptionManagement billing controls', () => {
  it('shows only server-declared cancellation, correction, and full-refund controls', () => {
    render(<SubscriptionManagement />);

    fireEvent.click(screen.getByRole('button', { name: 'View subscription 1' }));
    expect(screen.getByRole('button', { name: /cancel at period end/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /issue full refund/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /upgrade|downgrade|partial refund|adjust paid/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /close modal/i }));
    fireEvent.click(screen.getByRole('button', { name: 'View subscription 2' }));
    expect(screen.getByRole('button', { name: /correct legacy state/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /issue full refund|cancel at period end/i })).not.toBeInTheDocument();
  });

  it('waits for the authoritative cancellation response before reloading', async () => {
    render(<SubscriptionManagement />);
    fireEvent.click(screen.getByRole('button', { name: 'View subscription 1' }));
    fireEvent.click(screen.getByRole('button', { name: /cancel at period end/i }));

    await waitFor(() => expect(mocks.axiosPost).toHaveBeenCalledWith(
      '/admin/subscriptions/1/cancel',
      { cancellation_reason: 'reduce_costs' },
    ));
    expect(mocks.routerReload).toHaveBeenCalled();
  });
});
