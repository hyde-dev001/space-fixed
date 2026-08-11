import { useRef, useCallback } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Unified Finance API Hook
 * 
 * Provides consistent API calling patterns across all Finance components.
 * Handles CSRF tokens, authentication, and error responses automatically.
 * 
 * Usage:
 *   const api = useFinanceApi();
 *   const data = await api.get('/api/finance/expenses');
 *   const result = await api.post('/api/finance/expenses', { amount: 1000 });
 */

interface ApiOptions extends Omit<RequestInit, 'method' | 'body'> {
  skipAuth?: boolean; // For public endpoints
}

interface ApiResponse<T = any> {
  ok: boolean;
  status: number;
  data?: T;
  error?: string;
  message?: string;
}

export function useFinanceApi() {
  const { auth } = usePage().props as any;
  const isAuthenticated = Boolean(auth && auth.user);
  const ownerMode = auth?.erpActor?.type === 'shop_owner' && auth.erpActor.ownerMode === true;
  const csrfReady = useRef(false);

  const resolveUrl = useCallback((url: string): string => {
    if (!ownerMode) return url;

    return url.replace(/^\/api\/finance(?:\/session)?(?=\/|$)/, '/api/shop-owner/finance');
  }, [ownerMode]);

  /**
   * Ensure CSRF cookie is set
   */
  const ensureCsrf = useCallback(async (): Promise<boolean> => {
    if (csrfReady.current) return true;
    
    try {
      const response = await fetch('/sanctum/csrf-cookie', { 
        credentials: 'include' 
      });
      
      if (response.ok) {
        csrfReady.current = true;
        return true;
      }
      
      console.warn('Failed to fetch CSRF cookie:', response.status);
      return false;
    } catch (error) {
      console.warn('CSRF cookie request failed:', error);
      return false;
    }
  }, []);

  /**
   * Build standard auth headers
   */
  const getAuthHeaders = useCallback((): Record<string, string> => {
    const headers: Record<string, string> = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };

    // Finance mutations use the authenticated browser session and CSRF token.
    const xsrfCookie = document.cookie
      .split('; ')
      .find((entry) => entry.startsWith('XSRF-TOKEN='))
      ?.split('=')[1];

    if (xsrfCookie) {
      headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfCookie);
    }

    // Add CSRF token from meta tag as fallback.
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
      headers['X-CSRF-TOKEN'] = csrfToken;
    }

    return headers;
  }, []);

  /**
   * Get base URL for session-based vs public endpoints
   */
  const getBaseUrl = useCallback((_skipAuth: boolean = false): string => {
    if (ownerMode) return '/api/shop-owner/finance';
    return '/api/finance';
  }, [ownerMode]);

  /**
   * Core fetch wrapper with error handling
   */
  const apiFetch = useCallback(async <T = any>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH',
    body?: any,
    options: ApiOptions = {}
  ): Promise<ApiResponse<T>> => {
    const { skipAuth = false, headers: customHeaders, ...restOptions } = options;

    try {
      // Ensure CSRF for mutations
      if (method !== 'GET' && !skipAuth) {
        await ensureCsrf();
      }

      const headers: Record<string, string> = {
        ...getAuthHeaders(),
        ...(customHeaders as Record<string, string>),
      };

      const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;

      // Add Content-Type for body requests
      if (body && !isFormData && !headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
      }

      const response = await fetch(resolveUrl(url), {
        method,
        headers,
        credentials: skipAuth ? 'omit' : 'include',
        body: body ? (isFormData ? body : JSON.stringify(body)) : undefined,
        ...restOptions,
      });

      // Try to parse JSON response
      let data: any;
      const contentType = response.headers.get('content-type');
      
      if (contentType?.includes('application/json')) {
        try {
          data = await response.json();
        } catch {
          // Response not JSON, use text
          data = await response.text();
        }
      } else {
        data = await response.text();
      }

      if (!response.ok) {
        return {
          ok: false,
          status: response.status,
          error: data?.message || data?.error || `HTTP ${response.status}`,
          data,
        };
      }

      return {
        ok: true,
        status: response.status,
        data: data?.data || data, // Unwrap Laravel API response format
      };
    } catch (error) {
      console.error('API request failed:', error);
      return {
        ok: false,
        status: 0,
        error: error instanceof Error ? error.message : 'Network error',
      };
    }
  }, [ensureCsrf, getAuthHeaders, resolveUrl]);

  /**
   * GET request
   */
  const get = useCallback(<T = any>(
    url: string,
    options: ApiOptions = {}
  ): Promise<ApiResponse<T>> => {
    return apiFetch<T>(url, 'GET', undefined, options);
  }, [apiFetch]);

  /**
   * POST request
   */
  const post = useCallback(<T = any>(
    url: string,
    body?: any,
    options: ApiOptions = {}
  ): Promise<ApiResponse<T>> => {
    return apiFetch<T>(url, 'POST', body, options);
  }, [apiFetch]);

  /**
   * PUT request
   */
  const put = useCallback(<T = any>(
    url: string,
    body?: any,
    options: ApiOptions = {}
  ): Promise<ApiResponse<T>> => {
    return apiFetch<T>(url, 'PUT', body, options);
  }, [apiFetch]);

  /**
   * PATCH request
   */
  const patch = useCallback(<T = any>(
    url: string,
    body?: any,
    options: ApiOptions = {}
  ): Promise<ApiResponse<T>> => {
    return apiFetch<T>(url, 'PATCH', body, options);
  }, [apiFetch]);

  /**
   * DELETE request
   */
  const del = useCallback(<T = any>(
    url: string,
    options: ApiOptions = {}
  ): Promise<ApiResponse<T>> => {
    return apiFetch<T>(url, 'DELETE', undefined, options);
  }, [apiFetch]);

  return {
    get,
    post,
    put,
    patch,
    delete: del,
    ensureCsrf,
    getAuthHeaders,
    getBaseUrl,
    resolveUrl,
    isAuthenticated,
    ownerMode,
  };
}
