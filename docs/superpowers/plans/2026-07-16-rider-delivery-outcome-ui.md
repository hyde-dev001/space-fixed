# Rider Delivery Outcome UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace two simultaneous photo inputs with one explicit delivery-outcome choice and one visible workflow.

**Architecture:** Add per-leg outcome state to the existing `Shipments` component. Render accessible outcome buttons and conditionally mount only the selected proof or issue panel; switching clears the other panel's state.

**Tech Stack:** React, TypeScript, Tailwind CSS, Vitest, Testing Library

---

### Task 1: Single-outcome rider workflow

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/Shipments.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Shipments.test.tsx`

- [x] Add a test asserting no photo input appears before choosing an outcome.
- [x] Assert **Delivered successfully** shows only the delivery-proof panel.
- [x] Assert **Couldn't deliver** shows only its distinct, required attempt-photo input and issue panel.
- [x] Assert switching to issue clears the delivery photo, and switching back clears the issue reason, photo, and note.
- [x] Run the focused test and verify it fails against the two-input UI.
- [x] Implement minimal per-leg outcome state, selector buttons, conditional panels, and clear-on-switch behavior.
- [x] Re-run focused tests and `npm run build`.
