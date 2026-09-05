import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  GPS_POSITION_OPTIONS,
  getCurrentPositionWithFallback,
  getCurrentPositionWithTimeout,
} from '../geolocation';

describe('getCurrentPositionWithTimeout', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('passes the shared options and resolves when the browser returns a position', async () => {
    const position = {
      coords: { latitude: 14.5995, longitude: 120.9842 },
    } as GeolocationPosition;
    let resolvePosition!: PositionCallback;
    const getCurrentPosition = vi.fn((resolve: PositionCallback) => {
      resolvePosition = resolve;
    });

    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition },
    });

    const request = getCurrentPositionWithTimeout();
    resolvePosition(position);

    await expect(request).resolves.toBe(position);
    expect(getCurrentPosition).toHaveBeenCalledWith(
      expect.any(Function),
      expect.any(Function),
      GPS_POSITION_OPTIONS,
    );
  });

  it('rejects when the browser never invokes either callback', async () => {
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition: vi.fn() },
    });

    const request = getCurrentPositionWithTimeout();
    const rejection = expect(request).rejects.toThrow('timed out');

    await vi.advanceTimersByTimeAsync(GPS_POSITION_OPTIONS.timeout ?? 0);
    await rejection;
  });

  it('retries without high accuracy when the initial location request fails', async () => {
    const position = {
      coords: { latitude: 14.5995, longitude: 120.9842 },
    } as GeolocationPosition;
    const getCurrentPosition = vi.fn((
      resolve: PositionCallback,
      reject: PositionErrorCallback,
    ) => {
      if (getCurrentPosition.mock.calls.length === 1) {
        reject({ code: 2, message: 'Position unavailable' } as GeolocationPositionError);
        return;
      }

      resolve(position);
    });

    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition },
    });

    await expect(getCurrentPositionWithFallback()).resolves.toBe(position);
    expect(getCurrentPosition).toHaveBeenNthCalledWith(
      1,
      expect.any(Function),
      expect.any(Function),
      GPS_POSITION_OPTIONS,
    );
    expect(getCurrentPosition).toHaveBeenNthCalledWith(
      2,
      expect.any(Function),
      expect.any(Function),
      { ...GPS_POSITION_OPTIONS, enableHighAccuracy: false },
    );
  });
});
