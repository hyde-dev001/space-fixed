import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => {
  const fire = vi.fn();
  return {
    fire,
    swal: { fire },
    fetch: vi.fn(),
    listeners: new Map<string, (event: CustomEvent) => void>(),
    resolveDialogs: [] as Array<(value: unknown) => void>,
  };
});

vi.mock('sweetalert2', () => ({ default: state.swal }));
vi.mock('@inertiajs/react', () => ({
  router: {
    on: vi.fn((name: string, listener: (event: CustomEvent) => void) => {
      state.listeners.set(name, listener);
      return () => state.listeners.delete(name);
    }),
  },
}));
vi.mock('ziggy-js', () => ({ route: vi.fn() }));

const message = 'Please clock in on the Time In page before processing business actions.';
const payload = { success: false, code: 'EMPLOYEE_NOT_CLOCKED_IN', message };

beforeAll(async () => {
  vi.stubGlobal('fetch', state.fetch);
  await boot();
});

beforeEach(() => {
  vi.clearAllMocks();
  state.resolveDialogs = [];
  state.fetch.mockReset();
  state.fire.mockImplementation(() => new Promise(resolve => state.resolveDialogs.push(resolve)));
});

afterEach(async () => {
  state.resolveDialogs.forEach(resolve => resolve({ isConfirmed: false, isDismissed: true }));
  await Promise.resolve();
});

afterAll(() => vi.unstubAllGlobals());

async function boot() {
  await import('../bootstrap');
  return (await import('axios')).default;
}

async function rejectAxios(status = 423, data: unknown = payload) {
  const axios = await boot();
  const { AxiosError } = await import('axios');
  axios.defaults.adapter = async config => {
    throw new AxiosError('Request failed', undefined, config, undefined, {
      status, statusText: 'Locked', headers: {}, config, data,
    });
  };
  return axios.post('/api/erp/procurement/suppliers', {}).catch(error => error);
}

describe('employee clock-in feedback', () => {
  it('shows the clock-in message while preserving the original Axios rejection', async () => {
    const error = await rejectAxios();
    expect(error.response.status).toBe(423);
    expect(error.response.data).toEqual(payload);
    expect(state.fire).toHaveBeenCalledWith(expect.objectContaining({ icon: 'error', text: message }));
  });

  it('keeps a legacy generic failure alert from replacing the clock-in explanation', async () => {
    await rejectAxios();
    const { default: Swal } = await import('sweetalert2');
    const result = Swal.fire('Error', 'Failed to process the request', 'error');
    expect(state.fire).toHaveBeenCalledTimes(1);
    expect(state.fire).toHaveBeenCalledWith(expect.objectContaining({ text: message }));
    expect(result).toBe(state.fire.mock.results[0].value);
  });

  it('preserves confirmations while the clock-in alert is active', async () => {
    await rejectAxios();
    const { default: Swal } = await import('sweetalert2');
    const confirmation = { title: 'Archive supplier?', icon: 'warning' as const, showCancelButton: true };
    void Swal.fire(confirmation);
    expect(state.fire).toHaveBeenLastCalledWith(confirmation);
  });

  it('handles the separate HR Axios instance too', async () => {
    const axios = await boot();
    const { AxiosError } = await import('axios');
    axios.defaults.adapter = async config => {
      throw new AxiosError('Request failed', undefined, config, undefined, {
        status: 423, statusText: 'Locked', headers: {}, config, data: payload,
      });
    };
    const { employeeService } = await import('../services/hrService');
    await expect(employeeService.create({})).rejects.toHaveProperty('response.status', 423);
    expect(state.fire).toHaveBeenCalledWith(expect.objectContaining({ text: message }));
  });

  it('handles fetch without consuming or changing the response body', async () => {
    const response = new Response(JSON.stringify(payload), { status: 423 });
    state.fetch.mockResolvedValue(response);
    await boot();
    const result = await fetch('/api/staff/orders/1/approve', { method: 'POST' });
    expect(result).toBe(response);
    expect(await result.json()).toEqual(payload);
    expect(state.fire).toHaveBeenCalledWith(expect.objectContaining({ text: message }));
  });

  it('uses one alert for concurrent blocked requests', async () => {
    state.fetch.mockImplementation(async () => new Response(JSON.stringify(payload), { status: 423 }));
    await boot();
    await Promise.all([fetch('/api/actions/1', { method: 'POST' }), fetch('/api/actions/2', { method: 'POST' })]);
    expect(state.fire).toHaveBeenCalledTimes(1);
  });

  it('replaces the Inertia invalid-response modal only for a confirmed clock-in block', async () => {
    await boot();
    const listener = state.listeners.get('invalid');
    expect(listener).toBeTypeOf('function');
    const event = new CustomEvent('inertia:invalid', { cancelable: true, detail: { response: { status: 423, data: payload } } });
    listener?.(event);
    expect(event.defaultPrevented).toBe(true);
    expect(state.fire).toHaveBeenCalledWith(expect.objectContaining({ text: message }));
  });

  it.each([401, 403, 422, 500])('leaves other Axios errors unchanged (%s)', async status => {
    const error = await rejectAxios(status, { message: 'Other error' });
    expect(error.response.status).toBe(status);
    expect(state.fire).not.toHaveBeenCalled();
  });

  it('leaves unrelated locks and successful fetch responses unchanged', async () => {
    const lock = new Response(JSON.stringify({ code: 'OTHER_LOCK' }), { status: 423 });
    const success = new Response(JSON.stringify({ success: true }), { status: 200 });
    state.fetch.mockResolvedValueOnce(lock).mockResolvedValueOnce(success);
    await boot();
    expect(await fetch('/api/other-lock')).toBe(lock);
    expect(await fetch('/api/success')).toBe(success);
    expect(state.fire).not.toHaveBeenCalled();
  });

  it('does not carry clock-in feedback into later unrelated errors after dismissal', async () => {
    await rejectAxios();
    state.resolveDialogs[0]({ isConfirmed: true });
    await Promise.resolve();
    const { default: Swal } = await import('sweetalert2');
    const error = { icon: 'error' as const, title: 'Error', text: 'Unrelated failure' };
    void Swal.fire(error);
    expect(state.fire).toHaveBeenLastCalledWith(error);
  });

  it('does not replace a different server failure while the clock-in notice is open', async () => {
    await rejectAxios();
    await rejectAxios(401, { code: 'EMPLOYEE_SESSION_REVOKED', message: 'Please sign in again.' });
    const { default: Swal } = await import('sweetalert2');
    const error = { icon: 'error' as const, title: 'Error', text: 'Please sign in again.' };
    void Swal.fire(error);
    expect(state.fire).toHaveBeenLastCalledWith(error);
  });

  it('preserves malformed locked response bodies and network failures', async () => {
    const response = new Response('Invalid JSON', { status: 423 });
    const networkError = new TypeError('Network unavailable');
    state.fetch.mockResolvedValueOnce(response).mockRejectedValueOnce(networkError);
    await boot();
    expect(await (await fetch('/api/malformed')).text()).toBe('Invalid JSON');
    await expect(fetch('/api/network-failure')).rejects.toBe(networkError);
    expect(state.fire).not.toHaveBeenCalled();
  });
});
