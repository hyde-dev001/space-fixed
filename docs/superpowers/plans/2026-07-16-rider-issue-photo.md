# Rider Issue Photo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Submit mandatory failed-attempt photo evidence with a rider issue without creating delivery proof.

**Architecture:** Add per-leg issue-photo state to the existing rider shipment form. Submit reason, note, and issue photo as `FormData` to the existing report-issue endpoint, then refresh shipments and batches.

**Tech Stack:** React, TypeScript, Axios, Vitest, Testing Library

---

### Task 1: Required failed-attempt evidence

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [x] Add a regression test proving **Report issue** requires both a reason and issue photo.
- [x] Assert the request sends `reason_code` and `proof_file` to `/report-issue` as `FormData`.
- [x] Assert reporting an issue never calls the delivery-proof endpoint.
- [x] Assert a successful issue report reloads both `shipments` and `batches`.
- [x] Run the focused test and verify it fails for the missing issue-photo flow.
- [x] Implement the minimum separate issue-photo state, input, request, and reload behavior.
- [x] Re-run the focused test and production build.
