import React from 'react';
import { act, cleanup, render } from '@testing-library/react';
import { useQueryClient, type QueryClient } from '@tanstack/react-query';
import { afterEach, expect, it, vi } from 'vitest';
import { QueryProvider } from '../QueryProvider';

const navigation = vi.hoisted(() => ({ listener: undefined as undefined | ((event: { detail: { page: { props: { auth: Record<string, unknown> } } } }) => void) }));
vi.mock('@inertiajs/react', () => ({ router: { on: (_event: string, listener: typeof navigation.listener) => {
  navigation.listener = listener;
  return () => { navigation.listener = undefined; };
} } }));
let client: QueryClient;
function CacheProbe() { client = useQueryClient(); return null; }
afterEach(() => { cleanup(); client.clear(); });

it('retires private notification data on logout even when no notification consumers are mounted', () => {
  render(<QueryProvider><CacheProbe /></QueryProvider>);
  client.setQueryData(['notifications', '["user",1,10]', '/api/staff/notifications'], { secret: true });
  client.setQueryData(['notification-preferences', '["user",1,10]', '/api/staff/notifications'], { secret: true });
  client.setQueryData(['public-catalog'], { product: true });
  act(() => navigation.listener?.({ detail: { page: { props: { auth: {} } } } }));
  expect(client.getQueryCache().getAll().map(query => query.queryKey[0])).toEqual(['public-catalog']);
});
