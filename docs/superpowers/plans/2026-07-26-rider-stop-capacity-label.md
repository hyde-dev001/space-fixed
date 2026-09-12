# Rider Stop Capacity Label Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the logistics UI explicitly describe rider daily capacity as delivery stops rather than item quantity.

**Architecture:** Keep all existing stop-based calculations, API fields, and backend enforcement unchanged. Update only the two user-facing logistics screens and their existing component tests so one destination is clearly presented as one stop regardless of item quantity.

**Tech Stack:** React, TypeScript, Inertia.js, Vitest, Testing Library

---

### Task 1: Clarify the logistics setting

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Settings.tsx:70`

- [ ] **Step 1: Write the failing test**

Add this focused rendering test:

```tsx
it('explains that rider capacity is measured in delivery stops', () => {
  render(<LogisticsSettings />);

  expect(screen.getByLabelText('Daily delivery stops per rider')).toHaveValue(20);
  expect(screen.getByText('One delivery address counts as one stop, regardless of item quantity.')).toBeInTheDocument();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx
```

Expected: FAIL because the setting still renders as `Daily capacity per rider` and has no explanatory text.

- [ ] **Step 3: Write the minimal implementation**

Replace the existing capacity label with:

```tsx
<div>
  <label>Daily delivery stops per rider
    <input aria-describedby="daily-rider-capacity-help" className="block w-full rounded border p-2" type="number" min="1" value={form.daily_rider_capacity} onChange={(e) => set('daily_rider_capacity', Number(e.target.value))} />
  </label>
  <p id="daily-rider-capacity-help" className="text-sm text-gray-500">One delivery address counts as one stop, regardless of item quantity.</p>
</div>
```

Do not rename `daily_rider_capacity`; this is a copy clarification, not an API or schema change.

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx
```

Expected: PASS for the full Settings test file.

- [ ] **Step 5: Commit**

```bash
git add -- resources/js/Pages/ERP/Logistics/Settings.tsx resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx
git commit -m "fix: clarify rider capacity uses delivery stops"
```

### Task 2: Clarify the rider workload display

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx:407`
- Modify: `resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx:77`

- [ ] **Step 1: Update the existing test expectations**

Change the two assertions in the capacity-override test to:

```tsx
expect(screen.getByRole('option', { name: 'Rider One · 5/6 stops used today' })).toBeInTheDocument();
fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
expect(screen.getByText('5 stops used + 2 stops = 7/6')).toBeInTheDocument();
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because the option still says `used today` and the equation still says `used`.

- [ ] **Step 3: Write the minimal implementation**

In `OfferBatchModal`, change only the two rendered strings:

```tsx
{riders.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name} · {usedBy(candidate)}/{candidate.daily_capacity ?? dailyRiderCapacity} stops used today</option>)}
```

```tsx
{rider && <p className="mt-3 text-sm font-semibold text-gray-700">{used} stops used + {batch.assigned_stop_count} stops = {projected}/{riderCapacity}</p>}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: PASS for the full Batches test file.

- [ ] **Step 5: Commit**

```bash
git add -- resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "fix: label rider workload as delivery stops"
```

### Task 3: Verify the complete minimal change

**Files:**
- Verify: `resources/js/Pages/ERP/Logistics/Settings.tsx`
- Verify: `resources/js/Pages/ERP/Logistics/components/OfferBatchModal.tsx`
- Verify: `resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx`
- Verify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Run both focused frontend test files together**

Run:

```bash
npm run test:frontend -- resources/js/Pages/ERP/Logistics/__tests__/Settings.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: both test files PASS.

- [ ] **Step 2: Run the production build**

Run:

```bash
npm run build
```

Expected: the Vite production build exits successfully.

- [ ] **Step 3: Inspect the exact branch diff**

Run:

```bash
git status --short
git diff --check origin/solespace-b...HEAD
git diff --stat origin/solespace-b...HEAD
```

Expected: no whitespace errors; only the approved spec, plan, two components, and two component tests are part of this branch. Remove generated build artifacts from the working tree if the build changed them.
