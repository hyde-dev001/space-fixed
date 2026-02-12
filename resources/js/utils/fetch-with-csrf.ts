/**
 * Wrapper around fetch that automatically includes CSRF token
 */
export async function fetchWithCsrf(
  url: string,
  options: RequestInit = {}
): Promise<Response> {
  // Get CSRF token from meta tag or from endpoint
  let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  
  if (!csrfToken) {
    try {
      const response = await fetch('/api/csrf-token');
      const data = await response.json();
      csrfToken = data.csrf_token;
    } catch (e) {
      console.warn('Could not get CSRF token', e);
    }
  }

  const headers = new Headers(options.headers);
  
  // Add CSRF token to headers
  if (csrfToken) {
    headers.set('X-CSRF-TOKEN', csrfToken);
  }
  
  // Ensure credentials are included for session-based auth
  return fetch(url, {
    ...options,
    headers,
    credentials: options.credentials || 'include',
  });
}
