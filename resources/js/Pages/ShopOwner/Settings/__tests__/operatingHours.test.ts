import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: {},
  usePage: () => ({ props: {} }),
}));

vi.mock('../../../../layout/AppLayout_shopOwner', () => ({
  default: () => null,
}));

import { validateOperatingHours } from '../shopProfile';

describe('operating hours validation', () => {
  it('accepts a closing time after midnight for an overnight shop schedule', () => {
    expect(validateOperatingHours({
      monday_open: '11:59',
      monday_close: '00:00',
    })).toBeNull();
  });
});
