import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MyDeliveries from '../MyDeliveries';

const mocks = vi.hoisted(() => ({
  reload: vi.fn(),
  get: vi.fn(),
  post: vi.fn(() => Promise.resolve()),
  confirm: vi.fn(() => Promise.resolve({ isConfirmed: true })),
  getPosition: vi.fn(),
  props: {
    deliveryData: {
      offers: [],
      current: {
        item_type: 'work',
        key: 'single:42',
        kind: 'single',
        id: 42,
        status: 'in_transit',
        group: 'current',
        business_types: ['retail'],
        business_label: 'Retail delivery',
        delivery_date: '2026-09-04',
        delivery_window: 'morning',
        deliveries: [{
          id: 42,
          sequence: 1,
          stop_sequence: null,
          status: 'in_transit',
          leg_type: 'outbound',
          destination_snapshot: {
            type: 'customer',
            name: 'Customer 42',
            phone: '09001234567',
            address: 'Delivery address',
          },
          shipment: { id: 142, source_type: 'order', source_id: 242, purpose: 'retail_delivery' },
          proofs: [],
          assignments: [],
          attempts: [],
        }],
      },
      active_conflicts: [],
      has_active_conflict: false,
      up_next: null,
      list: {
        data: [],
        links: [],
        from: null,
        to: null,
        total: 0,
        current_page: 1,
        last_page: 1,
      },
      filters: { tab: 'upcoming', business: 'all', window: 'all', search: '' },
    },
    canRecordProof: true,
    canUpdateStatus: true,
    canReportIssue: true,
    liveTrackingEnabled: true,
    maxDeliveryAttempts: 2,
    today: '2026-09-04',
  } as any,
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ title }: { title: string }) => <div data-testid="head-title">{title}</div>,
  usePage: () => ({ props: mocks.props }),
  router: { reload: mocks.reload, get: mocks.get },
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <main>{children}</main>,
}));
vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('@/utils/workflowFeedback', () => ({
  workflowFeedback: { confirm: mocks.confirm },
}));
vi.mock('@/utils/geolocation', () => ({
  GPS_POSITION_OPTIONS: { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
  getCurrentPositionWithTimeout: mocks.getPosition,
}));
vi.mock('@/services/logisticsApi', () => ({
  logisticsApi: new Proxy({}, {
    get: () => vi.fn(() => Promise.resolve()),
  }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
});

describe('MyDeliveries GPS tracking integration', () => {
  it('automatically starts the tracker for an eligible current delivery', () => {
    render(<MyDeliveries />);

    expect(screen.getByRole('button', { name: 'Stop GPS tracking' })).toBeVisible();
  });
});
