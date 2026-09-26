import type { ReactNode } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  page: {
    props: {
      subscriptions: { data: [{
        id: 1,
        shop: {
          id: 10,
          business_name: 'Containment Shoes',
          owner_name: 'Ada Admin',
          email: 'ada@example.test',
        },
        premium_plan: {
          id: 1,
          name: 'Containment Plan',
          price: 249,
          duration_days: 30,
        },
        plan_code: 'containment-plan',
        showroom_slot_limit: 48,
        status: 'cancelled',
        amount_paid: 249,
        starts_at: '2026-08-01T00:00:00Z',
        ends_at: '2026-08-31T00:00:00Z',
        next_billing_at: null,
        cancellation_reason: 'owner_requested',
        cancellation_notes: 'Historical cancellation record',
        created_at: '2026-08-01T00:00:00Z',
      }], current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
      stats: { active: 0, expired: 1, total_revenue: 249, expiring_soon: 0 },
      plans: [{
        id: 1,
        plan_code: 'containment-plan',
        name: 'Containment Plan',
        description: 'Test plan',
        price: 249,
        duration_days: 30,
        showroom_slot_limit: 48,
        benefits: [],
        status: 'active',
        active_subscriptions_count: 0,
      }],
    },
  },
  routerPost: vi.fn(),
  routerGet: vi.fn(),
  axiosGet: vi.fn(),
  swalFire: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { get: mocks.routerGet, post: mocks.routerPost },
  usePage: () => mocks.page,
  useForm: () => ({
    data: {
      plan_code: '',
      name: '',
      description: '',
      price: '',
      duration_days: 30,
      showroom_slot_limit: 48,
      benefits: [],
    },
    errors: {},
    processing: false,
    clearErrors: vi.fn(),
    setData: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  }),
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('../../../../components/ui/button/Button', () => ({
  default: ({ children, onClick }: { children?: ReactNode; onClick?: () => void }) => (
    <button type="button" onClick={onClick}>{children}</button>
  ),
}));

vi.mock('sweetalert2', () => ({
  default: { fire: mocks.swalFire },
}));
vi.mock('axios', () => ({ default: { get: mocks.axiosGet } }));

import SubscriptionManagement from '../SubscriptionManagement';

beforeEach(() => {
  mocks.routerPost.mockReset();
  mocks.routerGet.mockReset();
  mocks.axiosGet.mockReset();
  mocks.axiosGet.mockResolvedValue({ data: {
    subscription_id: 1,
    payments: { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 },
    refunds: { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 },
  } });
  mocks.swalFire.mockReset();
});

describe('SubscriptionManagement containment', () => {
  it('keeps plan management and historical inspection without subscription intervention controls', async () => {
    render(<SubscriptionManagement />);

    expect(screen.getByRole('button', { name: /create plan/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Archive' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^cancel$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /deactivate|refund|upgrade subscription|downgrade subscription/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'View subscription 1' }));
    await waitFor(() => expect(mocks.axiosGet).toHaveBeenCalled());

    expect(screen.getByText('Cancellation Feedback')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /deactivate|refund|upgrade subscription|downgrade subscription|cancel subscription/i })).not.toBeInTheDocument();
    expect(mocks.routerPost).not.toHaveBeenCalled();
  });
});
