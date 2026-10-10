export const GPS_POSITION_OPTIONS: PositionOptions = {
  enableHighAccuracy: true,
  timeout: 15_000,
  maximumAge: 0,
};


export const getCurrentPositionWithTimeout = (
  options: PositionOptions = GPS_POSITION_OPTIONS,
): Promise<GeolocationPosition> => new Promise((resolve, reject) => {
  if (typeof navigator === 'undefined' || !navigator.geolocation) {
    reject(new Error('Geolocation is not supported by this browser.'));
    return;
  }

  let settled = false;
  let timeoutId: number | null = null;
  const finish = <T>(callback: (value: T) => void, value: T) => {
    if (settled) return;
    settled = true;
    if (timeoutId !== null) window.clearTimeout(timeoutId);
    callback(value);
  };

  const timeoutMs = options.timeout ?? 0;
  if (timeoutMs > 0) {
    timeoutId = window.setTimeout(() => {
      finish(reject, new Error('Location request timed out.'));
    }, timeoutMs);
  }

  try {
    navigator.geolocation.getCurrentPosition(
      (position) => finish(resolve, position),
      (error) => finish(reject, error),
      options,
    );
  } catch (error) {
    finish(
      reject,
      error instanceof Error ? error : new Error('Unable to request the current location.'),
    );
  }
});

export const getCurrentPositionWithFallback = async (
  options: PositionOptions = GPS_POSITION_OPTIONS,
): Promise<GeolocationPosition> => {
  try {
    return await getCurrentPositionWithTimeout(options);
  } catch (error) {
    if (options.enableHighAccuracy === false) {
      throw error;
    }

    return getCurrentPositionWithTimeout({
      ...options,
      enableHighAccuracy: false,
    });
  }
};
