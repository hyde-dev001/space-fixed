# P4 Rollout Checklist: Applying Pattern to Other Manager Pages

## Quick Reference: 5-Minute Implementation

For each manager page, follow these steps:

### Step 1: Import Hook (30 seconds)
```tsx
// Add to top of file
import { useFilteredPagination } from '../../../hooks/useFilteredPagination';
```

### Step 2: Initialize Hook in Component (1 minute)
Replace manual state setup with:
```tsx
const { page, perPage, filters, setFilter, resetFilters, loading, error, setLoading, setError } = 
  useFilteredPagination({
    perPage: 10,
    defaultFilters: { 
      /* your filter keys here */ 
    },
    onFilterChange: (filters, page) => fetchYourData(filters, page),
  });
```

### Step 3: Update Fetch Function (2 minutes)
Change signature from `fetchData(page)` to `fetchData(filters, page)`:
```tsx
const fetchYourData = async (currentFilters, currentPage) => {
  setLoading(true);
  setError(null);
  try {
    const params = new URLSearchParams();
    params.append('page', currentPage.toString());
    params.append('per_page', perPage.toString());
    
    // Add all active filters
    Object.entries(currentFilters).forEach(([key, val]) => {
      if (val) params.append(key, String(val));
    });
    
    const response = await fetch(`/api/your-endpoint?${params.toString()}`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    
    const data = await response.json();
    setYourItems(data.data);
    setPagination(data.pagination);
  } catch (error) {
    setError(error.message);
  } finally {
    setLoading(false);
  }
};
```

### Step 4: Wire Input Fields (1 minute 30 seconds)
Replace state updates with `setFilter()`:
```tsx
// Search input
<input 
  value={filters.search || ""} 
  onChange={(e) => setFilter('search', e.target.value || null)}
/>

// Select dropdown
<select 
  value={filters.status || ""} 
  onChange={(e) => setFilter('status', e.target.value || null)}
>
  {/* options */}
</select>

// Clear button
<button onClick={() => resetFilters()}>Clear Filters</button>
```

### Step 5: Add State Display UI (1 minute)
Copy error/loading/empty state blocks:
```tsx
{error ? (
  <div className="bg-red-50 border-l-4 border-red-500 p-6 rounded">
    <h3 className="text-red-800 font-semibold">Failed to Load</h3>
    <button onClick={() => fetchYourData(filters, page)}>Retry</button>
  </div>
) : loading ? (
  <div className="flex justify-center h-64">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
  </div>
) : items.length === 0 ? (
  <div className="text-center py-12 text-gray-500">No items found</div>
) : (
  /* Your table/list here */
)}
```

### Step 6: Remove Old State (1 minute)
Delete:
- ❌ All individual filter `useState` calls
- ❌ All individual filter setter functions
- ❌ Old `useEffect` with filter dependencies
- ❌ Manual `clearFilters()` function

---

## Manager Pages: Implementation Status

| Page | Status | Notes | Owner |
|------|--------|-------|-------|
| **AuditLogs.tsx** | ✅ DONE | P4 fully implemented, tested, compiled | Complete |
| **repairRejectReview.tsx** | 🔄 TODO | Currently client-side pagination; convert to server-side | High Priority |
| **Reports.tsx** | 📋 AUDIT | Check current filter/pagination logic | TBD |
| **InventoryOverview.tsx** | 📋 AUDIT | Check if filtering needed | TBD |
| **productUpload.tsx** | 📋 AUDIT | Check if pagination needed | TBD |
| **Dashboard.tsx** | 🔄 PHASE2 | KPI period selector (P3 phase 2) | Post-P4 |

---

## Page-Specific Implementation Guides

### [repairRejectReview.tsx] - RECOMMENDED NEXT TARGET

**Current State:**
```tsx
// Client-side: fetch ALL, then filter/paginate in memory
const [rejections, setRejections] = useState<RepairRejection[]>([]);
const [currentPage, setCurrentPage] = useState(1);

// Manual filtering with useMemo
const filteredData = useMemo(() => {
  return rejections.filter((item) => {
    const matchesSearch = item.requestNumber.toLowerCase().includes(searchQuery...);
    const matchesStatus = statusFilter === "All" || item.status === statusFilter;
    return matchesSearch && matchesStatus;
  });
}, [rejections, searchQuery, statusFilter]);

// Manual client-side pagination
const itemsPerPage = 5;
const totalPages = Math.ceil(filteredData.length / itemsPerPage) || 1;
const paginatedRejections = filteredData.slice(startIndex, startIndex + itemsPerPage);
```

**Issues:**
- ❌ No URL persistence (can't bookmark filtered view)
- ❌ Page doesn't reset on filter change (can land on empty page)
- ❌ No error state (fetch errors just silently fail)
- ❌ Loads ALL repairs at once (scales poorly with large datasets)

**Implementation Plan:**
1. Import hook + initialize with `{ search: '', status: 'Pending' }`
2. Convert `fetchRejections()` to server API with pagination params
3. Remove `useMemo` filtering logic
4. Use hook's `page` state for pagination instead of `currentPage`
5. Add error/loading states
6. Wire search + status selects to `setFilter()`
7. Remove old `filteredData` + `paginatedRejections` calculations

**Estimated Time:** 15-20 minutes

**Backend Consideration:** Ensure `/api/manager/repairs/rejected` endpoint supports:
- `?page=1&per_page=10`
- `?search=RR-2026`
- `?status=Pending` (or boolean status filter)

---

### [Reports.tsx] - AUDIT NEEDED

**Pre-Implementation Checklist:**
- [ ] Does it have filters? (e.g., report type, date range, status)
- [ ] Is pagination used? (if >5-10 items)
- [ ] Are filters currently persistent? (check URL)
- [ ] Does it have error handling?

**If "Yes" to filters + pagination:**
Follow 5-minute implementation guide above

**If "No" to pagination:**
Just apply URL persistence hook without page state

---

### [InventoryOverview.tsx] - AUDIT NEEDED

**Pre-Implementation Checklist:**
- [ ] Is pagination implemented?
- [ ] Are category/status filters used?
- [ ] Backend supports server-side filtering?
- [ ] Currently client-side or server-side?

---

## Testing P4 Implementation on Each Page

### Smoke Test (5 minutes)

After implementing, verify:

1. **Initial Load:**
   - ✅ Page loads without errors
   - ✅ Default filters applied
   - ✅ Data renders

2. **Filter Change → Page Reset:**
   - ✅ Go to page 2
   - ✅ Change a filter
   - ✅ Verify URL shows `page=1` (not page 2)
   - ✅ Verify page resets to 1

3. **URL Persistence:**
   - ✅ Set filters + go to page 2
   - ✅ Copy URL
   - ✅ Open in new tab
   - ✅ Verify all filters + page 2 restored

4. **Error Handling:**
   - ✅ Temporarily block API (DevTools Network tab)
   - ✅ Verify error state displays
   - ✅ Click Retry
   - ✅ Verify refetch happens (unblock API first)

5. **Loading State:**
   - ✅ Filters change
   - ✅ Spinner appears briefly
   - ✅ Data updates

### Browser DevTools Verification

**Network Tab:**
- Inspect API call to `/api/your-endpoint?page=1&filter=value`
- Verify all filters in query string
- Verify per_page param present

**URL Bar:**
- Before filter: `/manager/page`
- After filter: `/manager/page?search=foo&status=pending`
- No page reload (just history.replaceState)

---

## Common Rollout Mistakes

### ❌ Mistake 1: Forgetting to Remove Old State
```tsx
// WRONG - still has both old AND new
const [search, setSearch] = useState("");           // ❌ Delete this
const { filters, setFilter } = useFilteredPagination({...});
// Now search input wired to TWO states = confusing behavior
```

### ❌ Mistake 2: Not Handling null/empty Filters
```tsx
// WRONG - filter goes to URL as empty string
params.append('search', currentFilters.search);  // ❌ Adds ?search=

// RIGHT - skip empty filters
if (currentFilters.search) params.append('search', currentFilters.search);  // ✅
```

### ❌ Mistake 3: Forgetting Page Reset Behavior
```tsx
// WRONG - tries to navigate to old page after filter change
const [items, setItems] = useState([]);
useEffect(() => {
  setCurrentPage(1);  // ❌ Manual reset - hook already does this!
}, [search, status]);
```

### ❌ Mistake 4: Manual onFilterChange Logic
```tsx
// WRONG - duplicating hook logic
const fetchData = () => {
  // Manually setting state that should be in hook
};
setFilter('search', search);  // Triggers hook callback
// Then you fetch again = double fetch

// RIGHT - let hook trigger single fetch
onFilterChange: (filters, page) => fetchData(filters, page)  // ✅ Called once per change
```

---

## Rollout Timeline

**Recommended Sequence:**

| Week | Task |
|------|------|
| **Week 1** | ✅ AuditLogs.tsx (DONE) + QA testing |
| **Week 2** | repairRejectReview.tsx (15-20 min impl + QA) |
| **Week 3** | Reports.tsx (if audit shows it needs P4) |
| **Week 4** | InventoryOverview.tsx (if audit shows it needs P4) |

---

## Support Resources

**For Implementation Help:**
1. Reference the `AuditLogs.tsx` as working example
2. See `docs/P4-UNIFIED-FILTER-PAGING-PATTERN.md` for full guide
3. Copy template from pattern doc
4. Use smoke tests above to validate

**For Bug Reports:**
- Share browser DevTools Network tab (API call params)
- Share URL from address bar
- Note which version of hook being used

**For Questions:**
- Check "Common Pitfalls" section above
- Review hook JSDoc comments in `useFilteredPagination.ts`
- Reference working AuditLogs example

---

## Success Criteria

Once all manager pages implement P4:

✅ **Consistency:**
- All manager pages use same filter + pagination pattern
- Similar UX across AuditLogs, Reports, InventoryOverview

✅ **Bookmarkability:**
- Manager can share filtered view links
- Filters restored on page reload

✅ **Discoverability:**
- Page resets to 1 on filter change (intuitive)
- Pagination controls clearly visible

✅ **Resilience:**
- Error states show with retry option
- Loading states prevent confusion during fetch
- Empty states distinguish "no data" vs "filtered empty"

✅ **Accessibility:**
- All filters have labels (visible or aria-label)
- Pagination buttons have aria-label
- Fieldset groups related filters
- Keyboard navigation works

✅ **Performance:**
- URL changes don't force full page reload
- Filter changes debounced (no API spam)
- Large datasets paginated server-side

---

**Status:** Pattern proven on AuditLogs.tsx. Ready for rollout to other manager pages.
