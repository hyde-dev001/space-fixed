# Batch Create and Offer Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the separate scheduling and dispatch-pool controls with one dispatcher form that creates a draft batch and optionally offers it to a rider.

**Architecture:** Keep the existing schedule, create-batch, and offer-batch APIs. The React page partitions selected legs and calls those APIs sequentially, retaining enough local state to recover safely from partial failure; the backend only needs to expose the rider capacity already stored on `RiderProfile`.

**Tech Stack:** Laravel 12, Inertia, React 18, TypeScript, Axios, Tailwind CSS, Vitest, Testing Library, PHPUnit.

---

### Task 1: Implement the combined create-and-offer execution flow

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx:9-54`
- Modify: `resources/js/types/logistics.ts:96-103`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Replace the current test fixture with controllable page props and API mocks**

Use hoisted mutable props plus resolved mocks for `scheduleLegs`, `createBatch`, and `offerBatch`. Make `createBatch` return the real response shape:

```ts
const mocks = vi.hoisted(() => ({
  props: { batches: [], pool: [], riders: [], unscheduled: [] } as Record<string, unknown>,
  scheduleLegs: vi.fn(),
  createBatch: vi.fn(),
  offerBatch: vi.fn(),
  reload: vi.fn(),
}));

mocks.createBatch.mockResolvedValue({ data: { batch: { id: 41 } } });
```

- [ ] **Step 2: Write the failing orchestration tests**

Add three tests:

```ts
it('schedules unscheduled stops, creates the batch, then offers it to the selected rider', async () => {
  // select an unscheduled order, date, window, and rider
  // click Create & offer batch
  // assert scheduleLegs([7], date, window)
  // assert createBatch({ delivery_date, delivery_window, leg_ids: [7] })
  // assert offerBatch(41, riderId)
  // assert invocationCallOrder is scheduleLegs < createBatch < offerBatch
});

it('creates a draft when no rider is selected', async () => {
  // assert createBatch is called and offerBatch is not
});

it('schedules only the unscheduled subset of a mixed selection', async () => {
  // select unscheduled leg 7 and matching scheduled leg 8
  // assert scheduleLegs receives [7], while createBatch receives [7, 8]
});
```

- [ ] **Step 3: Run the frontend test and verify RED**

Run:

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL because the combined form and sequential action do not exist.

- [ ] **Step 4: Implement the minimal sequential handler**

In `Batches.tsx`, replace the two creation sections with shared state for selected IDs, date/window, optional rider ID, and submitting status. Partition from the existing props; do not add a helper module:

```ts
const unscheduledIds = selected.filter((id) => unscheduled.some((leg) => leg.id === id) && !scheduledThisAttempt.includes(id));

if (unscheduledIds.length) {
  await logisticsApi.scheduleLegs(unscheduledIds, date, window);
  setScheduledThisAttempt((ids) => [...new Set([...ids, ...unscheduledIds])]);
}

const response = await logisticsApi.createBatch({ delivery_date: date, delivery_window: window, leg_ids: selected });
if (riderId) await logisticsApi.offerBatch(response.data.batch.id, Number(riderId));
router.reload();
```

Use a single primary button whose label is derived from `riderId`. Keep the existing draft-batch controls below the form. Add `daily_capacity?: number | null` to `LogisticsRider`; the backend already serializes that model field, so no controller change is needed.

- [ ] **Step 5: Re-run the frontend test and verify GREEN**

Run the command from Step 3.

Expected: all `Batches.test.tsx` tests PASS.

- [ ] **Step 6: Commit the execution flow**

```powershell
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/types/logistics.ts
git commit -m "feat: combine batch creation and rider offer"
```

### Task 2: Add eligibility, QOL, and partial-failure recovery

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write failing eligibility and QOL tests**

Add focused tests that prove:

- Select all selects unscheduled legs and only pool legs matching the chosen date/window.
- A mismatched scheduled leg is disabled and changing date/window removes it from selection.
- Changing date/window after a partial scheduling success clears the locally scheduled IDs from the old slot and requires a page reload before continuing.
- The page shows `N selected`, the chosen rider's `daily_capacity`, readable dates, and specific empty states.
- The primary button is disabled with no date or no selection, and remains disabled while the promise is pending.

- [ ] **Step 2: Run the frontend test and verify RED**

Run:

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: FAIL on missing eligibility controls and QOL text.

- [ ] **Step 3: Implement eligibility and native UI controls**

Reuse native inputs and the existing Tailwind styles. Compute eligible pool legs inline from chosen date/window, use one Select all checkbox, display selected count/capacity, and format dates with the platform formatter:

```ts
new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
```

Do not add a date-picker, state library, or new component abstraction.

- [ ] **Step 4: Re-run and verify the QOL tests are GREEN**

Run the command from Step 2.

Expected: all `Batches.test.tsx` tests PASS.

- [ ] **Step 5: Write failing partial-failure tests**

Add two tests:

```ts
it('retries batch creation without scheduling the same stops twice', async () => {
  // schedule resolves; first create rejects; retry succeeds
  // expect scheduleLegs to have been called once
});

it('reloads the draft list when offering the created batch fails', async () => {
  // create resolves; offer rejects
  // expect recoverable draft message, cleared form, and router.reload
});
```

Also assert that an Axios-style 422 response displays its first validation error, a response with only `data.message` displays that server message, and an unknown error displays the generic refresh message.

- [ ] **Step 6: Run and verify the recovery tests are RED**

Run the command from Step 2.

Expected: FAIL because retry and server-message handling are incomplete.

- [ ] **Step 7: Implement minimal recovery handling**

Track IDs successfully scheduled during the current form attempt. Do not clear form state on create failure. If date/window changes after such a failure, clear the form and reload props so the now-scheduled deliveries are rediscovered safely under their stored slot. As soon as batch creation succeeds, clear and lock the creation form before offering; if offering fails, set the approved draft-recovery message and reload props so assignment continues through the new draft controls. Assert that another create call cannot be triggered from the stale selection. Extract the first server validation error, then `error.response?.data?.message`, then use the existing generic message. Always reset `submitting` in `finally`.

- [ ] **Step 8: Re-run the focused frontend suite and verify GREEN**

Run the command from Step 2.

Expected: all `Batches.test.tsx` tests PASS with no warnings.

- [ ] **Step 9: Commit the QOL and recovery behavior**

```powershell
git add resources/js/Pages/ERP/Logistics/Batches.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
git commit -m "feat: improve batch dispatch usability"
```

### Task 3: Verify the integrated change

**Files:**
- Verify only; no expected source changes.

- [ ] **Step 1: Run the focused frontend test**

```powershell
npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: PASS.

- [ ] **Step 2: Run the logistics backend tests**

```powershell
php artisan test tests/Feature/Logistics
```

Expected: PASS.

- [ ] **Step 3: Build the frontend**

```powershell
npm run build
```

Expected: Vite build succeeds. Treat generated `public/build` output as local build artifacts and do not include it in source commits.

- [ ] **Step 4: Review the final diff**

```powershell
git diff --stat origin/solespace-b...HEAD
git status --short
```

Expected: only the design/plan and intended source/test files are committed; pre-existing generated build changes remain uncommitted.
