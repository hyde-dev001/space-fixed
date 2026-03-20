import { useEffect, useState, useCallback, useRef } from 'react';

/**
 * Unified filter + pagination hook with URL persistence
 * 
 * Usage:
 * const { page, perPage, filters, setFilter, setFilters, setPersistentPage, loading, error, setLoading, setError } = useFilteredPagination({
 *   perPage: 10,
 *   defaultFilters: { search: '', status: 'all' }
 * });
 * 
 * Features:
 * - Persists filters & page to URL query params
 * - Auto-resets to page 1 when any filter changes
 * - Provides unified state management for pagination + filters
 * - Handles loading and error states
 */

interface UseFilteredPaginationOptions {
  perPage?: number;
  defaultFilters?: Record<string, string | number | boolean>;
  pageParamName?: string;
  onFilterChange?: (filters: Record<string, any>, page: number) => void;
}

export interface FilteredPaginationState {
  page: number;
  perPage: number;
  filters: Record<string, string | number | boolean>;
  setFilter: (key: string, value: string | number | boolean | null) => void;
  setFilters: (filters: Record<string, string | number | boolean>) => void;
  setPersistentPage: (page: number) => void;
  resetFilters: () => void;
  loading: boolean;
  error: string | null;
  setLoading: (loading: boolean) => void;
  setError: (error: string | null) => void;
}

/**
 * Parse URL search params into filter object
 */
function getFiltersFromUrl(pageParamName: string): Record<string, string | number | boolean> {
  if (typeof window === 'undefined') return {};

  const params = new URLSearchParams(window.location.search);
  const filters: Record<string, string | number | boolean> = {};

  for (const [key, value] of params.entries()) {
    if (key !== pageParamName && key !== 'per_page') {
      // Parse boolean values
      if (value === 'true') filters[key] = true;
      else if (value === 'false') filters[key] = false;
      // Parse numeric values (but not dates or IDs that look numeric)
      else if (!isNaN(Number(value)) && !value.includes('-')) filters[key] = Number(value);
      else filters[key] = value;
    }
  }

  return filters;
}

/**
 * Get current page from URL or default to 1
 */
function getPageFromUrl(pageParamName: string): number {
  if (typeof window === 'undefined') return 1;
  const params = new URLSearchParams(window.location.search);
  const urlPage = params.get(pageParamName);
  return urlPage ? Math.max(1, parseInt(urlPage)) : 1;
}

/**
 * Update browser URL without reload
 */
function updateUrl(filters: Record<string, any>, page: number, pageParamName: string): void {
  if (typeof window === 'undefined') return;

  const params = new URLSearchParams();

  // Add page param
  if (page > 1) {
    params.set(pageParamName, page.toString());
  }

  // Add filter params
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      params.set(key, String(value));
    }
  });

  const newUrl = params.toString() ? `?${params.toString()}` : window.location.pathname;
  window.history.replaceState({}, '', newUrl);
}

export function useFilteredPagination(options: UseFilteredPaginationOptions = {}): FilteredPaginationState {
  const {
    perPage = 10,
    defaultFilters = {},
    pageParamName = 'page',
    onFilterChange,
  } = options;

  const [filters, setFilters] = useState<Record<string, string | number | boolean>>(() => {
    const urlFilters = getFiltersFromUrl(pageParamName);
    return Object.keys(urlFilters).length > 0 ? urlFilters : defaultFilters;
  });

  const [page, setPage] = useState(() => getPageFromUrl(pageParamName));
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const previousFiltersRef = useRef<Record<string, any>>({});
  const previousPageRef = useRef<number>(page);

  // Sync state to URL and trigger callback
  useEffect(() => {
    updateUrl(filters, page, pageParamName);

    // Call callback when filters OR page changes
    const filtersChanged = JSON.stringify(previousFiltersRef.current) !== JSON.stringify(filters);
    const pageChanged = previousPageRef.current !== page;

    if (onFilterChange && (filtersChanged || pageChanged)) {
      previousFiltersRef.current = { ...filters };
      previousPageRef.current = page;
      onFilterChange(filters, page);
    }
  }, [filters, page, pageParamName, onFilterChange]);

  // Reset to page 1 when filters change (prevent stale filters)
  const setFilter = useCallback((key: string, value: string | number | boolean | null) => {
    setFilters((prev) => {
      const updated = { ...prev };
      if (value === null || value === '') {
        delete updated[key];
      } else {
        updated[key] = value;
      }
      return updated;
    });
    // Reset to page 1 on filter change
    setPage(1);
  }, []);

  const setFiltersCallback = useCallback((newFilters: Record<string, string | number | boolean>) => {
    setFilters(newFilters);
    // Reset to page 1 on filters change
    setPage(1);
  }, []);

  const resetFilters = useCallback(() => {
    setFilters(defaultFilters);
    setPage(1);
  }, [defaultFilters]);

  const setPersistentPage = useCallback((newPage: number) => {
    setPage(Math.max(1, newPage));
  }, []);

  return {
    page,
    perPage,
    filters,
    setFilter,
    setFilters: setFiltersCallback,
    setPersistentPage,
    resetFilters,
    loading,
    error,
    setLoading,
    setError,
  };
}
