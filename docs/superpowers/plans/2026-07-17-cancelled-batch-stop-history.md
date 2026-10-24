# Cancelled Batch Stop History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep saved deliveries visible inside a cancelled batch's History card after its live legs are detached.

**Architecture:** Reuse the existing `cancelled_stops` snapshot and the exact fallback rule already used by `BatchWorkspace`. A single selected stop list in `BatchCard` will drive both the urgent count and expanded rows.

**Tech Stack:** React, TypeScript, Vitest, Testing Library

---

### Task 1: Render the cancelled stop snapshot

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/BatchCard.tsx:25-69`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Write the failing regression test**

Add a cancelled batch with empty `legs` and one urgent stop in `cancelled_stops`. Expand History and the batch card, then assert that the saved order row is visible and the card shows `1 urgent`.

Add a parameterized fallback regression for `cancelled_stops: null` and `cancelled_stops: []`, each with one urgent live leg. Assert that the live order row and `1 urgent` remain visible.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: FAIL because `BatchCard` reads empty `batch.legs` for both the urgent count and expanded rows.

- [ ] **Step 3: Implement the minimal fix**

In `BatchCard`, select stops once:

```tsx
const legs = batch.status === 'cancelled' && batch.cancelled_stops?.length
  ? batch.cancelled_stops
  : batch.legs;
```

Use `legs` for `urgentCount` and the expanded `BatchStopRow` mapping.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

Expected: all tests pass.

- [ ] **Step 5: Run broader verification**

Run: `npm run build`

Expected: Vite production build succeeds.

- [ ] **Step 6: Commit the fix**

```bash
git add resources/js/Pages/ERP/Logistics/components/BatchCard.tsx resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx docs/superpowers/plans/2026-07-17-cancelled-batch-stop-history.md
git commit -m "fix: preserve cancelled stops in batch history"
```
