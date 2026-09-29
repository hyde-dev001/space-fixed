type CsrfTokenPayload = {
  csrf_token?: unknown;
};

export async function getFreshCsrfToken(): Promise<string> {
  const response = await fetch('/api/csrf-token', {
    method: 'GET',
    headers: {
      Accept: 'application/json',
    },
    credentials: 'same-origin',
    cache: 'no-store',
  });

  if (!response.ok) {
    throw new Error('Unable to refresh CSRF token.');
  }

  const payload = (await response.json()) as CsrfTokenPayload;

  if (typeof payload.csrf_token !== 'string' || payload.csrf_token === '') {
    throw new Error('CSRF token response was invalid.');
  }

  document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', payload.csrf_token);

  return payload.csrf_token;
}
