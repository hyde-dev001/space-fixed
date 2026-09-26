# Logistics Date Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the native delivery date control with an ERP-styled single-date picker that disables past dates.

**Architecture:** Add a focused `DeliveryDatePicker` component that owns calendar display state but emits the existing `YYYY-MM-DD` value through the current callback. Pass the controller's shop-timezone `today` prop through `Batches` and `AvailableDeliveriesPanel`; do not change backend or router contracts.

**Tech Stack:** React 18, TypeScript 5.7, Lucide React, Tailwind CSS 4, Vitest, React Testing Library.

## Global Constraints

- Preserve the existing `date` state, router query parameter, and `logisticsApi.scheduleLegs` payload.
- Use the server-provided shop-timezone `today` value as the minimum selectable date.
- Keep the picker single-date; do not introduce range-selection state or dependencies.
- Preserve unrelated working-tree changes.

---

### Task 1: Add the calendar component

**Files:**
- Create: `resources/js/Pages/ERP/Logistics/components/DeliveryDatePicker.tsx`
- Test: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] Add a controlled picker with `value`, `minDate`, `onChange`, and optional `disabled` props.
- [ ] Render the month header, previous/next controls, weekday headings, current-month grid, selected state, disabled past state, and clear-date footer.
- [ ] Close on Escape and outside pointer interaction without changing the selected value.
- [ ] Use `disabled` date buttons and accessible names for disabled past dates.
- [ ] Add tests for the minimum date, future selection, clear action, and month navigation.

### Task 2: Wire the picker to the existing Batches flow

**Files:**
- Modify: `resources/js/types/logistics.ts`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`

- [ ] Add optional `today?: string` to `DeliveryBatchPageProps` and use a timezone-safe fallback only when the prop is absent.
- [ ] Pass `today` to `AvailableDeliveriesPanel` and replace only the native date input.
- [ ] Keep `changeSlot`, filter matching, selection clearing, save-draft scheduling, and router parameters unchanged.

### Task 3: Verify the focused UX and application gates

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] Run the focused Batches test suite and confirm all tests pass.
- [ ] Run the full frontend suite with the locally installed Vitest binary if the pnpm wrapper is unavailable.
- [ ] Run the Laravel Logistics feature suite.
- [ ] Run the production build and `git diff --check`.
