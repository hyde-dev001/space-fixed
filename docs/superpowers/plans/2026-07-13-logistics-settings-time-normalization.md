# Logistics Settings Time Normalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent untouched Logistics Settings time values from causing a 422 response.

**Architecture:** Normalize Laravel `TIME` strings once when initializing React form state. Keep the API's strict `H:i` validation unchanged.

**Tech Stack:** React, TypeScript, Inertia, Axios, Vitest, Testing Library, Laravel

---

### Task 1: Normalize settings form time values

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Settings.tsx:13-23`

- [ ] **Step 1: Write the failing regression test**

Mock `usePage` with all five time fields in `HH:mm:ss`, mock `Head` and the ERP layout, mock resolved `axios.put`, render the form, click Save without editing, and assert the submitted payload contains `HH:mm` values.

```tsx
const put = vi.hoisted(() => vi.fn().mockResolvedValue({ data: {} }));
vi.mock('axios', () => ({ default: { put } }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { settings } }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

await waitFor(() => expect(put).toHaveBeenCalledWith('/api/logistics/settings', expect.objectContaining({
    cutoff_time: '15:00',
    morning_start: '08:00',
    morning_end: '12:00',
    afternoon_start: '13:00',
    afternoon_end: '18:00',
  })));
```

- [ ] **Step 2: Run the test and verify RED**

Run: `npx.cmd vitest run resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`

Expected: FAIL because the submitted payload still contains seconds.

- [ ] **Step 3: Implement the minimal normalization**

Initialize state with the existing settings plus sliced time fields:

```tsx
const [form, setForm] = useState<Settings>(() => ({
  ...initial,
  cutoff_time: initial.cutoff_time.slice(0, 5),
  morning_start: initial.morning_start.slice(0, 5),
  morning_end: initial.morning_end.slice(0, 5),
  afternoon_start: initial.afternoon_start.slice(0, 5),
  afternoon_end: initial.afternoon_end.slice(0, 5),
}));
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `npx.cmd vitest run resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`

Expected: PASS.

- [ ] **Step 5: Run regression verification**

Run: `php artisan test tests/Feature/Logistics`

Expected: PASS.

Run: `npm.cmd run build`

Expected: Vite exits 0.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/ERP/Logistics/Settings.tsx resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx
git commit -m "fix: normalize logistics settings times"
```
