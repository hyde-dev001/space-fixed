# Rider Proof and Issue Mutual Exclusion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent a rider from submitting delivery proof and reporting an issue for the same leg by mistake.

**Architecture:** Reuse the existing per-leg proof-file and issue-form state in `Shipments.tsx`. Each action button is disabled while the opposite workflow has a selection, and clearing that selection restores it.

**Tech Stack:** React, TypeScript, Vitest, Testing Library

---

### Task 1: Mutually exclusive rider actions

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [x] Change the test fixture to an `in_transit` leg with `canRecordProof: true` so both rider actions render.
- [x] Add a test that selecting an issue disables **Submit proof**, then selecting the blank option re-enables it.
- [x] Add a test that selecting a proof file disables **Report issue**, then clicking **Clear proof** re-enables it.
- [x] Run `npx vitest run resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx` and verify the new assertions fail.
- [x] Add native `disabled` states and disabled styling to both action buttons using existing state, plus a **Clear proof** button shown only when a file is selected.
- [x] Re-run the focused test and `npm run build`.
