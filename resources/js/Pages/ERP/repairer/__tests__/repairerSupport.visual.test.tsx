import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RepairerSupport from '../repairerSupport';

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
}));

const conversation = {
  id: 1,
  customer: {
    id: 2,
    name: 'John Daniel Paragas',
    email: 'john@example.com',
    profile_photo_url: null,
  },
  shop_owner: {
    business_name: 'Kicks Store',
    profile_photo: null,
  },
  last_message_at: '2026-09-05T14:09:00Z',
  status: 'open',
  messages: [
    {
      id: 11,
      sender_type: 'system',
      content: 'Repair update available',
      created_at: '2026-09-05T14:09:00Z',
    },
  ],
};

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { visit: vi.fn() },
}));

vi.mock('axios', () => ({
  default: { get: mocks.get, post: vi.fn() },
}));

vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
    configurable: true,
    value: vi.fn(),
  });
  mocks.get.mockImplementation(async (url: string) => {
    if (url === '/api/repairer/conversations/1') {
      return { data: conversation };
    }

    return { data: [conversation] };
  });
});

afterEach(() => {
  cleanup();
});

describe('Repairer support visual controls', () => {
  it('uses a black selection indicator for the active conversation', async () => {
    render(<RepairerSupport />);

    const customerHeading = await waitFor(() => {
      const heading = screen.getAllByText('John Daniel Paragas').find((element) =>
        element.closest('.cursor-pointer'),
      );

      if (!heading) {
        throw new Error('Active conversation was not rendered');
      }

      return heading;
    });
    const conversationRow = customerHeading.closest('.cursor-pointer');

    expect(conversationRow).toHaveClass('border-l-black');
    expect(conversationRow).not.toHaveClass('border-l-blue-500');
  });
});
