import React from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  useNotificationPreferences, useNotificationStats, useNotifications,
  useRecentNotifications, useUnreadCount, useUpdatePreferences,
} from '../useNotifications';

const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

const employee = (id: number, shop = 10) => ({ auth: { user: { id, shop_owner_id: shop } } });
type PendingRequest = { url: string; method: string; signal?: AbortSignal | null; resolve: (value: Response) => void };
let pending: PendingRequest[];
let client: QueryClient;
let frames: string[];

function Probe() {
  const list = useNotifications();
  const recent = useRecentNotifications();
  const count = useUnreadCount();
  const stats = useNotificationStats();
  const preferences = useNotificationPreferences();
  const update = useUpdatePreferences();
  const state = JSON.stringify({ list: list.data, recent: recent.data, count: count.data,
    stats: stats.data, preferences: preferences.data });
  frames.push(state);
  return <><output data-testid="state">{state}</output>
    <button onClick={() => update.mutate({ sound_enabled: false })}>save preferences</button></>;
}

const tree = () => <QueryClientProvider client={client}><Probe /></QueryClientProvider>;
const response = (payload: unknown) => ({ ok: true, status: 200, json: async () => payload }) as Response;
const payloadFor = (url: string, title: string, count: number) => {
  if (url.endsWith('/unread-count')) return { count };
  if (url.endsWith('/stats')) return { title, total: count };
  if (url.endsWith('/preferences')) return { title, sound_enabled: true };
  const notifications = [{ id: count, title, is_read: false }];
  return url.includes('/recent?') ? notifications : { notifications };
};

async function finishReads(title: string, count: number) {
  const requests = pending.splice(0);
  await act(async () => { requests.forEach(request => request.resolve(response(payloadFor(request.url, title, count)))); });
  await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent(title));
}

describe('notification principal isolation with a persistent QueryClient', () => {
  beforeEach(() => {
    page.props = employee(1);
    pending = [];
    frames = [];
    client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 300000 }, mutations: { retry: false } } });
    vi.stubGlobal('fetch', vi.fn((url: string, options: RequestInit) => new Promise<Response>(resolve => {
      pending.push({ url, method: options?.method ?? 'GET', signal: options?.signal, resolve });
    })));
  });
  afterEach(() => { cleanup(); client.clear(); vi.unstubAllGlobals(); });

  it.each([
    ['Finance → Repair', employee(2)],
    ['HR → CRM', employee(3)],
    ['Rider → Staff', employee(4)],
    ['same user ID, different shop', employee(1, 20)],
    ['same numeric ID, owner guard', { auth: { shop_owner: { id: 1 } } }],
    ['same numeric ID, admin guard', { auth: { super_admin: { id: 1 } } }],
  ])('never renders previous lists/counts/stats/preferences during %s', async (_, nextProps) => {
    const view = render(tree());
    await finishReads('Previous private notification', 7);
    frames = [];
    page.props = nextProps;
    view.rerender(tree());
    expect(screen.getByTestId('state')).not.toHaveTextContent('Previous private notification');
    expect(screen.getByTestId('state')).not.toHaveTextContent('"count":7');
    await finishReads('Current recipient notification', 2);
    expect(frames.every(frame => !frame.includes('Previous private notification') && !frame.includes('"count":7'))).toBe(true);
    expect(client.getQueryCache().getAll().some(query => (JSON.stringify(query.state.data) ?? '').includes('Previous private notification'))).toBe(false);
  });

  it('aborts departing reads and ignores their late completion', async () => {
    const view = render(tree());
    const oldRequests = pending.splice(0);
    page.props = employee(2);
    view.rerender(tree());
    expect(oldRequests.every(request => request.signal?.aborted)).toBe(true);
    await act(async () => { oldRequests.forEach(request => request.resolve(response(payloadFor(request.url, 'Old delayed secret', 8)))); });
    expect(screen.getByTestId('state')).not.toHaveTextContent('Old delayed secret');
    await finishReads('Current private notification', 3);
    expect(frames.every(frame => !frame.includes('Old delayed secret'))).toBe(true);
  });

  it('clears sensitive caches on logout and makes no anonymous reads', async () => {
    const view = render(tree());
    await finishReads('Signed-in private notification', 6);
    page.props = { auth: {} };
    view.rerender(tree());
    expect(screen.getByTestId('state')).not.toHaveTextContent('Signed-in private notification');
    expect(pending).toHaveLength(0);
    expect(client.getQueryCache().getAll().some(query => (JSON.stringify(query.state.data) ?? '').includes('Signed-in private notification'))).toBe(false);
  });

  it('does not write a departing preference mutation into the next principal cache', async () => {
    const view = render(tree());
    await finishReads('Finance preferences', 7);
    fireEvent.click(screen.getByText('save preferences'));
    await waitFor(() => expect(pending).toHaveLength(1));
    const oldSave = pending.pop()!;
    page.props = employee(2);
    view.rerender(tree());
    await finishReads('Repair preferences', 2);
    await act(async () => oldSave.resolve(response({ preferences: { title: 'Departed mutation secret', sound_enabled: false } })));
    expect(screen.getByTestId('state')).toHaveTextContent('Repair preferences');
    expect(screen.getByTestId('state')).not.toHaveTextContent('Departed mutation secret');
    expect(client.getQueryCache().getAll().some(query => (JSON.stringify(query.state.data) ?? '').includes('Departed mutation secret'))).toBe(false);
  });

  it('keeps authenticated preferences writable after StrictMode effect replay', async () => {
    render(<React.StrictMode>{tree()}</React.StrictMode>);
    await finishReads('Own preferences', 1);
    fireEvent.click(screen.getByText('save preferences'));
    await waitFor(() => expect(pending.some(request => request.method === 'PUT')).toBe(true));
  });
});
