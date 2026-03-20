# P4: Unified Filter + Paging UX Pattern for Manager Pages

## Overview

This document describes the unified pattern for implementing **consistent filter + pagination behavior** across all manager screens in the SimpleSolespace ERP system.

**Key Features**:
- ✅ URL persistence: Filters & page state synchronize to browser query params
- ✅ Automatic page reset: When any filter changes, page automatically resets to 1
- ✅ Consistent loading/error/empty states across all pages
- ✅ Accessible form labels and ARIA attributes
- ✅ Smooth page transitions with scroll-to-top

## Custom Hook: `useFilteredPagination`

Located in: `resources/js/hooks/useFilteredPagination.ts`

### Usage Example

```tsx
import { useFilteredPagination } from '../../../hooks/useFilteredPagination';

export default function YourManagerPage() {
  const { page, perPage, filters, setFilter, resetFilters, loading, error, setLoading, setError } = useFilteredPagination({
    perPage: 10,
    defaultFilters: { search: '', status: 'all', date_from: '' },
    pageParamName: 'page',
    onFilterChange: (filters, page) => {
      // Called whenever filters or page changes
      // Fetch data here with new filters and page
      fetchYourData(filters, page);
    }
  });

  const fetchYourData = async (currentFilters, currentPage) => {
    setLoading(true);
    setError(null);
    try {
      // Build query params
      const params = new URLSearchParams();
      params.append('page', currentPage.toString());
      if (currentFilters.search) params.append('search', currentFilters.search);
      if (currentFilters.status) params.append('status', currentFilters.status);
      
      const response = await fetch(`/api/your-endpoint?${params.toString()}`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      
      const data = await response.json();
      setYourData(data);
    } catch (error) {
      setError(error.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      {/* Filters */}
      <div>
        <input 
          value={filters.search || ""}
          onChange={(e) => setFilter('search', e.target.value || null)}
          placeholder="Search..."
        />
        <select 
          value={filters.status || ""}
          onChange={(e) => setFilter('status', e.target.value || null)}
        >
          <option value="">All</option>
          <option value="active">Active</option>
        </select>
        <button onClick={() => resetFilters()}>Clear Filters</button>
      </div>

      {/* Content: Loading / Error / Empty / Data */}
      {error && (
        <div className="error-state">
          <p>{error}</p>
          <button onClick={() => fetchYourData(filters, page)}>Retry</button>
        </div>
      )}
      {loading && <div className="loading-spinner">Loading...</div>}
      {!loading && !error && data.length === 0 && (
        <div className="empty-state">No items found</div>
      )}
      {!loading && !error && data.length > 0 && (
        <div>/* Your table/content */</div>
      )}

      {/* Pagination */}
      <Pagination 
        currentPage={page} 
        lastPage={lastPage}
        onPageChange={(p) => {
          const params = new URLSearchParams(window.location.search);
          params.set('page', p.toString());
          window.history.replaceState({}, '', `?${params.toString()}`);
        }}
      />
    </div>
  );
}
```

## Hook API Reference

### Parameters

```typescript
interface UseFilteredPaginationOptions {
  perPage?: number;              // Items per page (default: 10)
  defaultFilters?: Record<...>;  // Initial filter values
  pageParamName?: string;        // URL param name for page (default: 'page')
  onFilterChange?: (filters, page) => void;  // Callback when filters/page change
}
```

### Return Values

```typescript
interface FilteredPaginationState {
  page: number;                     // Current page (1-indexed)
  perPage: number;                  // Items per page
  filters: Record<string, any>;     // Current filter values
  setFilter: (key, value) => void;  // Set single filter; auto-resets to page 1
  setFilters: (filters) => void;    // Set all filters; auto-resets to page 1
  setPersistentPage: (page) => void;// Set page without resetting filters
  resetFilters: () => void;         // Reset to default filters & page 1
  loading: boolean;                 // API call in progress
  error: string | null;             // Error message if fetch failed
  setLoading: (bool) => void;       // Update loading state
  setError: (msg) => void;          // Update error state
}
```

## Implementation Steps

### 1. Import the Hook

```tsx
import { useFilteredPagination } from '../../../hooks/useFilteredPagination';
```

### 2. Initialize with Default Filters

```tsx
const { page, perPage, filters, setFilter, resetFilters, loading, error, setLoading, setError } = 
  useFilteredPagination({
    perPage: 10,
    defaultFilters: { 
      search: '', 
      status: 'pending',
      date_from: ''
    },
    onFilterChange: (filters, newPage) => {
      fetchData(filters, newPage);
    }
  });
```

### 3. Implement Data Fetching

```tsx
const fetchData = async (currentFilters, currentPage) => {
  setLoading(true);
  setError(null);
  try {
    const params = new URLSearchParams();
    params.append('page', currentPage.toString());
    params.append('per_page', perPage.toString());
    
    // Add active filters
    Object.entries(currentFilters).forEach(([key, value]) => {
      if (value) params.append(key, String(value));
    });

    const response = await fetch(`/api/endpoint?${params.toString()}`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    
    const data = await response.json();
    setResults(data.data);
    setPagination(data.pagination);
  } catch (err) {
    setError(err.message);
  } finally {
    setLoading(false);
  }
};
```

### 4. Wire Input Fields to Filters

**Search Input:**
```tsx
<input
  id="search-input"
  value={filters.search || ""}
  onChange={(e) => setFilter('search', e.target.value || null)}
  placeholder="Search items..."
  aria-label="Search items"
/>
```

**Select/Dropdown:**
```tsx
<select
  id="status-filter"
  value={filters.status || ""}
  onChange={(e) => setFilter('status', e.target.value || null)}
  aria-label="Filter by status"
>
  <option value="">All Statuses</option>
  <option value="pending">Pending</option>
  <option value="approved">Approved</option>
</select>
```

**Date Range:**
```tsx
<input
  id="date-from"
  type="date"
  value={filters.date_from || ""}
  onChange={(e) => setFilter('date_from', e.target.value || null)}
  aria-label="Filter from date"
/>
```

### 5. Render States: Loading / Error / Empty / Data

```tsx
{error ? (
  <div className="bg-red-50 border-l-4 border-red-500 p-6 rounded">
    <h3 className="text-lg font-semibold text-red-800">Failed to Load</h3>
    <p className="text-red-700">{error}</p>
    <button onClick={() => fetchData(filters, page)}>Retry</button>
  </div>
) : loading ? (
  <div className="flex justify-center items-center h-64">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
  </div>
) : results.length === 0 ? (
  <div className="text-center py-12">
    <p className="text-lg text-gray-600">No items found</p>
    <p className="text-sm text-gray-400 mt-2">
      {Object.values(filters).some(f => f) 
        ? "Try adjusting filters" 
        : "Items will appear here when created"}
    </p>
  </div>
) : (
  // Render your table/list
  <div>...</div>
)}
```

### 6. Pagination Controls

```tsx
{pagination && pagination.last_page > 1 && (
  <div className="flex justify-between items-center px-6 py-4 border-t bg-gray-50">
    {/* Results count */}
    <p className="text-sm text-gray-700">
      Showing {startIndex} to {endIndex} of {pagination.total}
    </p>

    {/* Page buttons */}
    <div className="flex gap-2">
      <button
        onClick={() => navigateToPage(page - 1)}
        disabled={page === 1}
        aria-label="Previous page"
      >
        Prev
      </button>

      {/* Page numbers */}
      {[...Array(pagination.last_page)].map((_, idx) => {
        const pageNum = idx + 1;
        return (
          <button
            key={pageNum}
            onClick={() => navigateToPage(pageNum)}
            className={page === pageNum ? 'active' : ''}
            aria-current={page === pageNum ? "page" : undefined}
          >
            {pageNum}
          </button>
        );
      })}

      <button
        onClick={() => navigateToPage(page + 1)}
        disabled={page === pagination.last_page}
        aria-label="Next page"
      >
        Next
      </button>
    </div>
  </div>
)}
```

Helper for page navigation:
```tsx
const navigateToPage = (pageNum: number) => {
  const params = new URLSearchParams(window.location.search);
  params.set('page', pageNum.toString());
  window.history.replaceState({}, '', `?${params.toString()}`);
  window.scrollTo({ top: 0, behavior: 'smooth' });
};
```

## URL Behavior

### Example URLs Generated

**Initial load (no filters):**
```
/manager/audit-logs
```

**With filters (auto-persisted):**
```
/manager/audit-logs?page=2&event=updated&subject_type=Product&date_from=2026-02-01
```

**Bookmarkable:** User can copy/share the URL and all filters + page will be restored on reload.

### Auto-Reset Logic

**Scenario 1:** User is on page 3 with event="created"
- User changes event to "updated"
- **Result:** Page resets to 1, URL becomes `?event=updated`

**Scenario 2:** User is on page 2 with search="foo"
- User clears search and tries to navigate to page 3
- **Result:** Only page changes (no filter reset), URL becomes `?page=3`

## Accessibility Features

### Form Structure
- Use `<label>` or `aria-label` on all inputs
- Use `<fieldset>` + `<legend>` for filter groups
- Include `aria-label` on action buttons

### Pagination
- Use `aria-current="page"` on active page button
- Include `aria-label` on prev/next/jump buttons
- Provide `aria-expanded` on filter toggle

### Example:
```tsx
<fieldset>
  <legend>Filter Activity Logs</legend>
  <label htmlFor="search">Search</label>
  <input id="search" {...} aria-label="Search logs" />
  
  <button aria-label="Retry loading logs">Retry</button>
  <button aria-label="Previous page">Prev</button>
  <button aria-current="page">2</button>
</fieldset>
```

## Applying to Other Manager Pages

### Candidates for P4 Implementation (Priority Order)

1. **repairRejectReview.tsx** (currently client-side pagination)
   - Move from `useMemo` filtering to API-based pagination
   - Add URL persistence for search/status filters
   - Add error state display

2. **Reports.tsx** 
   - Replace manual page state with hook
   - Persist report type / date range filters

3. **InventoryOverview.tsx**
   - Replace category/status filter logic
   - Sync URL with search + category selections

4. **productUpload.tsx** (if it has filtering)
   - Ensure filter consistency

### Copy-Paste Template

Save this as a base for new manager pages:

```tsx
import { useFilteredPagination } from '../../../hooks/useFilteredPagination';

export default function ManagerPage() {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  
  const { page, perPage, filters, setFilter, resetFilters, loading, error, setLoading, setError } = 
    useFilteredPagination({
      perPage: 10,
      defaultFilters: { /* your defaults */ },
      onFilterChange: (f, p) => fetchItems(f, p),
    });

  const fetchItems = async (f, p) => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      params.append('page', p.toString());
      // Add filters...
      const res = await fetch(`/api/endpoint?${params}`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setItems(data.data);
      setPagination(data.pagination);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      {/* Filters with setFilter */}
      {/* Loading / Error / Empty / Data states */}
      {/* Pagination controls */}
    </div>
  );
}
```

## Testing the Pattern

### Manual Tests

1. **URL Persistence:**
   - Load page with filters: `?search=foo&status=pending&page=2`
   - Verify all filters load correctly
   - Change a filter → verify page resets to 1

2. **Page Reset on Filter Change:**
   - Go to page 2
   - Change any filter
   - Verify page resets to 1

3. **Empty/Error States:**
   - Add filter that returns no results → see empty state
   - Simulate API error → see error state + retry button
   - Click Retry → verify refetch attempt

4. **Accessibility:**
   - Tab through all filter inputs
   - Verify labels read correctly with screen reader
   - Test pagination buttons with keyboard

### Automated Tests (Optional)

Could add Cypress/Playwright tests for:
- URL persistence across page reloads
- Page reset on filter change
- Error handling and retry
- Empty state messaging

## Common Pitfalls to Avoid

❌ **Don't:** Fetch on every input keystroke
✅ **Do:** Debounce search input or use onChange on blur

❌ **Don't:** Keep filters in separate useState calls
✅ **Do:** Use the hook's `filters` object for all state

❌ **Don't:** Navigate pages manually with `setCurrentPage()`
✅ **Do:** Let the hook handle page via URL params

❌ **Don't:** Forget to show error state with retry
✅ **Do:** Provide fallback UI for network failures

## Performance Considerations

- Hook uses `useRef` to detect actual filter changes (not re-renders)
- URL updates use `replaceState` (no history spam)
- `onFilterChange` callback debounces via dependency array
- Limit `perPage` to 10-50 items for pagination stability

## Summary

By following this pattern, all manager pages will have:
- **Consistent UX** across audit logs, reports, inventory
- **Bookmarkable URLs** for sharing filtered views
- **Page reset behavior** when filters change
- **Accessible forms** with proper labels + ARIA
- **Professional error handling** with retry capability
- **Performance optimized** with minimal re-renders
