# SoleSpace Attendance Responsive Branding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the ERP Attendance / Time In page usable at phone and tablet widths and replace the remaining TailAdmin branding with SoleSpace without changing attendance behavior.

**Architecture:** Keep `TimeIn.tsx` as the route-level component and preserve its existing state, API calls, handlers, and modal flow. Apply mobile-first Tailwind classes to the existing page sections, use the shared logo assets for the ERP wordmark, and verify responsive behavior in a real browser at the agreed viewport widths.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library, Vite 7, Playwright browser verification.

## Global Constraints

- At 375px and 390px wide, the page must have no page-level horizontal overflow, the header actions must not overlap the title, and the current time must remain readable without wrapping.
- At 640px and 768px wide, the page must use a balanced tablet layout with comfortable controls, two-column summary cards, and wrapped overtime/history content.
- At 1024px and wider, the existing desktop information hierarchy must remain intact beside the ERP sidebar.
- Clock in, lunch start/end, clock out, overtime request, leave request, filtering, pagination, loading states, and modal submission behavior must continue using the existing handlers and API contracts.
- Use the existing Tailwind token classes and project palette; add no dependency or page-specific design system.
- Keep interactive targets at least 44px by 44px and provide visible `focus-visible` styles.
- Use existing SVG icon language; do not introduce emoji as icons or structural status markers in rendered UI.
- Do not edit generated `public/build` assets, `.env`, `vendor/`, or `node_modules/`.
- Preserve unrelated working-tree changes in `app/Http/Controllers/Logistics/ErpLogisticsController.php`, `package-lock.json`, `tests/Feature/Logistics/LogisticsPageAccessTest.php`, `.pnpm-store/`, and `DESIGN.md`.

---

### Task 1: Add responsive and branding regression coverage

**Files:**
- Create: `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`
- Modify: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

**Interfaces:**
- Consumes: the current `TimeIn` route component and `AppHeader_ERP` rendered markup.
- Produces: stable regression assertions for mobile-first page classes, live-clock accessibility, and the SoleSpace header wordmark alt text.

- [ ] **Step 1: Write the failing `TimeIn` responsive contract test**

Create `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx` with this exact test harness. The page mock returns successful empty API payloads so the test exercises the route markup without changing production APIs.

```tsx
import type { PropsWithChildren } from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import TimeIn from '../TimeIn';

const fetchMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => ({
        props: {
            auth: { user: { id: 11, role: 'STAFF' } },
        },
    }),
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));

vi.mock('sweetalert2', () => ({
    default: { fire: vi.fn() },
}));

beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ data: [] }),
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('keeps the attendance page mobile-safe and the live clock accessible', () => {
    render(<TimeIn />);

    expect(screen.getByTestId('time-in-page')).toHaveClass('min-h-screen', 'overflow-x-hidden');
    expect(screen.getByRole('heading', { name: 'Attendance Tracking' })).toHaveClass(
        'text-2xl',
        'sm:text-3xl',
        'lg:text-4xl',
    );
    expect(screen.getByRole('button', { name: /clock in/i })).toHaveClass(
        'min-h-12',
        'w-full',
        'sm:w-auto',
    );
    expect(screen.getByText('Current Time').nextElementSibling).toHaveAttribute('aria-live', 'polite');
});
```

- [ ] **Step 2: Run the focused test and verify it fails for the missing responsive contract**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx
```

Expected: the test fails because `TimeIn` does not yet expose `data-testid="time-in-page"`, the responsive heading/button classes, or `aria-live="polite"`.

- [ ] **Step 3: Extend the existing ERP header test for SoleSpace branding**

In `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`, add this assertion to the first test after `render(<AppHeaderERP />);`:

```tsx
expect(screen.getAllByAltText('SoleSpace logo')).toHaveLength(2);
```

The two images are intentional: the ERP header keeps separate light and dark image variants and CSS controls which one is visible.

- [ ] **Step 4: Run the existing header test and verify the new assertion fails before the branding change**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppHeader_ERP.test.tsx
```

Expected: the existing behavior tests pass and the new alt-text assertion fails because both images still use `alt="Logo"`.

- [ ] **Step 5: Commit the regression tests**

```powershell
git add -- resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx
git commit -m "test: cover attendance responsive branding contracts"
```

---

### Task 2: Replace TailAdmin branding with SoleSpace

**Files:**
- Modify: `public/images/logo/logo.svg`
- Modify: `public/images/logo/logo-dark.svg`
- Modify: `resources/js/layout/AppHeader_ERP.tsx:110-118`
- Modify: `resources/js/Pages/OtherPage/NotFound.tsx:39`
- Modify: `resources/js/layout/layout/SidebarWidget.tsx:1-22`
- Test: `resources/js/layout/__tests__/AppHeader_ERP.test.tsx`

**Interfaces:**
- Consumes: the existing `/images/logo/logo.svg` and `/images/logo/logo-dark.svg` paths used by the ERP header and other shared headers.
- Produces: a SoleSpace light/dark wordmark with the same 154×32 image dimensions and no TailAdmin copy or external TailAdmin purchase URL in the scoped utility component.

- [ ] **Step 1: Replace the light logo asset**

Replace the contents of `public/images/logo/logo.svg` with this 154×32 SoleSpace wordmark:

```svg
<svg width="154" height="32" viewBox="0 0 154 32" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="solespace-logo-title">
  <title id="solespace-logo-title">SoleSpace</title>
  <rect width="32" height="32" rx="8" fill="#465FFF"/>
  <path d="M9 8.5V23.5M15.5 13V23.5M22 10.5V23.5" stroke="white" stroke-width="3.25" stroke-linecap="round"/>
  <text x="43" y="21.5" fill="#101828" font-family="Outfit, Arial, sans-serif" font-size="18" font-weight="700" letter-spacing="0.1">SoleSpace</text>
</svg>
```

- [ ] **Step 2: Replace the dark logo asset**

Replace the contents of `public/images/logo/logo-dark.svg` with the same markup, changing only the wordmark fill to white:

```svg
<svg width="154" height="32" viewBox="0 0 154 32" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="solespace-logo-dark-title">
  <title id="solespace-logo-dark-title">SoleSpace</title>
  <rect width="32" height="32" rx="8" fill="#465FFF"/>
  <path d="M9 8.5V23.5M15.5 13V23.5M22 10.5V23.5" stroke="white" stroke-width="3.25" stroke-linecap="round"/>
  <text x="43" y="21.5" fill="white" font-family="Outfit, Arial, sans-serif" font-size="18" font-weight="700" letter-spacing="0.1">SoleSpace</text>
</svg>
```

- [ ] **Step 3: Update shared visible copy and image alternatives**

In `resources/js/layout/AppHeader_ERP.tsx`, change both image alternatives to:

```tsx
alt="SoleSpace logo"
```

In `resources/js/Pages/OtherPage/NotFound.tsx`, change:

```tsx
&copy; {new Date().getFullYear()} - TailAdmin
```

to:

```tsx
&copy; {new Date().getFullYear()} - SoleSpace
```

In `resources/js/layout/layout/SidebarWidget.tsx`, replace the vendor template card with this self-contained SoleSpace card so it has no TailAdmin copy or external TailAdmin link:

```tsx
export default function SidebarWidget() {
  return (
    <div className="mx-auto mb-10 w-full max-w-60 rounded-2xl bg-gray-50 px-4 py-5 text-center dark:bg-white/[0.03]">
      <h3 className="mb-2 font-semibold text-gray-900 dark:text-white">
        SoleSpace workspace
      </h3>
      <p className="mb-4 text-gray-500 text-theme-sm dark:text-gray-400">
        Keep daily operations, attendance, and service work in one place.
      </p>
      <a
        href="/"
        className="flex min-h-11 items-center justify-center rounded-lg bg-brand-500 p-3 font-medium text-white text-theme-sm hover:bg-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
      >
        Open SoleSpace
      </a>
    </div>
  );
}
```

- [ ] **Step 4: Run branding tests**

Run:

```powershell
pnpm exec vitest run resources/js/layout/__tests__/AppHeader_ERP.test.tsx
```

Expected: both header tests pass, including the two `SoleSpace logo` alt-text matches.

- [ ] **Step 5: Confirm scoped source copy is clean and commit the branding change**

Run:

```powershell
rg -n -i --glob '!public/build/**' --glob '!node_modules/**' --glob '!vendor/**' 'TailAdmin|tailadmin' resources/js public/images/logo
```

Expected: no output.

```powershell
git add -- public/images/logo/logo.svg public/images/logo/logo-dark.svg resources/js/layout/AppHeader_ERP.tsx resources/js/Pages/OtherPage/NotFound.tsx resources/js/layout/layout/SidebarWidget.tsx
git commit -m "refactor: replace TailAdmin branding with SoleSpace"
```

---

### Task 3: Implement the responsive Attendance / Time In layout

**Files:**
- Modify: `resources/js/Pages/ERP/STAFF/TimeIn.tsx:1270-2058`
- Test: `resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx`

**Interfaces:**
- Consumes: the existing attendance state values, handlers, records, filters, and modal controls already defined above the render block.
- Produces: the same attendance functionality with mobile-first markup classes and an accessible live clock; no handler signatures or API payloads change.

- [ ] **Step 1: Add the page-level overflow guard and responsive header**

Change the root and header opening markup to:

```tsx
<div data-testid="time-in-page" className="min-h-screen overflow-x-hidden">
  {!showOvertimeModal && !showLeaveModal ? (
    <>
      <div className="mb-8 sm:mb-10 lg:mb-12">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex min-w-0 items-start gap-3">
            <div className="shrink-0 rounded-lg bg-blue-100 p-2 dark:bg-blue-900/30">
              <ClockIcon />
            </div>
            <div className="min-w-0">
              <h1 className="text-2xl font-bold leading-tight text-gray-900 dark:text-white sm:text-3xl lg:text-4xl">
                Attendance Tracking
              </h1>
              <p className="mt-2 text-base text-gray-600 dark:text-gray-400 sm:text-lg">
                Manage your daily work hours efficiently
              </p>
            </div>
          </div>
          <div className="grid w-full grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:flex sm:w-auto sm:flex-wrap sm:justify-end">
```

Keep the existing `Over Time` and `Request Leave` button handlers and disabled conditions, but add these classes to both buttons:

```text
min-h-11 w-full justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 sm:w-auto
```

Close the new wrappers after the two buttons and remove the old duplicate description paragraph.

- [ ] **Step 2: Make the clock card and live time fit phones**

Use these classes for the primary card and its inner container:

```tsx
<div className="mb-8 sm:mb-10 lg:mb-12">
  <div className="relative overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/50 sm:rounded-3xl">
    <div className="relative p-5 sm:p-8 md:p-12 lg:p-16">
```

Add `aria-live="polite"` and `tabular-nums` to the live clock element and use this responsive class prefix before the existing conditional color classes:

```tsx
<div
  aria-live="polite"
  className={`whitespace-nowrap text-4xl font-bold tracking-tight tabular-nums sm:text-5xl md:text-6xl lg:text-7xl ${
```

Change the status/action spacing and action group to:

```tsx
<div className="mb-6 flex justify-center sm:mb-8">
  ...existing status badge...
</div>

<div className="grid w-full grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:flex sm:flex-wrap sm:justify-center">
```

Keep the existing `Clock In`, `Start Lunch`, `End Lunch`, and `Clock Out` handlers, loading branches, disabled conditions, and labels. Add `min-h-12 w-full ... sm:w-auto` and a visible `focus-visible:ring-2` class to each action button. Remove transform-based hover scaling from these primary controls so their layout bounds do not jitter on touch devices.

- [ ] **Step 3: Make summary and overtime cards wrap at tablet widths**

Change the summary grid to:

```tsx
<div className="mb-8 grid grid-cols-1 gap-4 sm:mb-10 sm:grid-cols-2 lg:mb-12 lg:grid-cols-4 lg:gap-6">
```

Keep each card's content and values unchanged, but use `min-w-0`, `overflow-hidden`, `p-4 sm:p-6`, and `break-words` on the card/value containers so lunch ranges and status notes cannot widen the document.

Change the approved overtime card header/details to:

```tsx
<div className="mb-8 overflow-hidden rounded-2xl border-2 border-blue-500 bg-gradient-to-r from-blue-50 to-cyan-50 shadow-sm dark:border-blue-600 dark:from-blue-900/40 dark:to-cyan-900/40">
  <div className="p-4 sm:p-6">
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      ...existing overtime heading and integrated status...
    </div>
    <div className="mt-5 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:mt-6 lg:grid-cols-3 lg:gap-4">
```

Remove the `🎯` prefix from the two overtime heading strings and remove any emoji prefix from late/shop-hours alert copy; the existing text and semantic colors remain.

- [ ] **Step 4: Make attendance history controls and table intentional on mobile**

Use these class structures for the history card, toolbar, filters, table wrapper, and pagination:

```tsx
<div className="min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/50">
  <div className="border-b border-gray-200 p-4 dark:border-gray-800 sm:p-6 lg:p-8">
    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      ...existing heading...
      <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 lg:flex lg:w-auto">
```

Give the search input and status select `min-h-11 w-full lg:w-auto` plus visible focus rings. Change the table wrapper to `overflow-x-auto overscroll-x-contain` and the table to `min-w-[720px] w-full`; this keeps any required table scrolling inside the history card instead of creating page-level overflow. Change pagination buttons to `min-h-11` and allow the footer controls to wrap with `flex-wrap`.

- [ ] **Step 5: Make leave and overtime modals fit the viewport**

Change both modal overlays to use `overflow-y-auto p-3 sm:p-4`, and change the inner panels to use `my-4 max-h-[calc(100dvh-2rem)] w-full overflow-y-auto p-4 sm:p-6 md:p-8`. Keep the existing form controls and handlers.

For the leave modal, use:

```tsx
<div className="grid grid-cols-1 gap-4 lg:grid-cols-5 lg:gap-6">
```

Keep the calendar at seven columns but reduce its phone gap with `gap-1 sm:gap-2`; preserve the existing `h-11` day targets. Change the modal action row to `flex-col gap-3 sm:flex-row` and add `min-h-11` to Cancel and Submit.

For the overtime hour picker, use:

```tsx
<div className="grid grid-cols-2 gap-3 min-[420px]:grid-cols-4">
```

Add `min-h-11` to the hour buttons, select, textarea, Cancel, and Submit controls; keep the existing validation and request handler conditions.

- [ ] **Step 6: Run the focused tests and fix only responsive-contract failures**

Run:

```powershell
pnpm exec vitest run resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx resources/js/layout/__tests__/AppHeader_ERP.test.tsx
```

Expected: both responsive/branding tests pass. If the mount test reports an async warning, await the initial successful fetch flush before assertions and keep the production component unchanged; do not remove the existing fetch effects.

- [ ] **Step 7: Commit the responsive page change**

```powershell
git add -- resources/js/Pages/ERP/STAFF/TimeIn.tsx resources/js/Pages/ERP/STAFF/__tests__/TimeIn.test.tsx
git commit -m "fix: make attendance page responsive on mobile and tablet"
```

---

### Task 4: Run full verification and browser review

**Files:**
- Read-only review: `resources/js/Pages/ERP/STAFF/TimeIn.tsx`, `resources/js/layout/AppHeader_ERP.tsx`, `public/images/logo/logo.svg`, `public/images/logo/logo-dark.svg`
- No generated build files are hand-edited.

**Interfaces:**
- Consumes: the completed responsive page, branding assets, and focused tests from Tasks 1–3.
- Produces: fresh test/build/diff evidence and a browser-visible confirmation at phone and tablet widths.

- [ ] **Step 1: Run the full frontend test suite**

Run:

```powershell
pnpm run test:frontend
```

Expected: Vitest completes with no new failures. Record any pre-existing failures separately rather than calling the suite passing.

- [ ] **Step 2: Build the production frontend bundle**

Run:

```powershell
pnpm run build
```

Expected: Vite completes successfully and emits the route bundle; do not stage generated `public/build` changes unless the repository's existing workflow explicitly tracks them.

- [ ] **Step 3: Run diff hygiene and scoped brand scans**

Run:

```powershell
git diff --check
rg -n -i --glob '!public/build/**' --glob '!node_modules/**' --glob '!vendor/**' 'TailAdmin|tailadmin' resources/js public/images/logo
git status --short
```

Expected: `git diff --check` has no output, the scoped brand scan has no output, and `git status` lists only the intentional implementation changes plus the unrelated pre-existing files named in the global constraints.

- [ ] **Step 4: Verify the page in a real browser**

Use the repository's webapp-testing/Playwright workflow against the local app route `/erp/time-in`. Check these viewport widths: 375×844, 390×844, 640×900, 768×1024, and 1024×900.

At each width verify:

```text
- Header title and Over Time / Request Leave controls do not overlap.
- The live clock stays readable and does not wrap at 375px or 390px.
- Clock actions are at least 44px high, have visible focus/pressed states, and do not shift layout.
- Summary cards stack on phone, use two columns on tablet, and use four columns on desktop.
- Overtime details, history filters, table scroll region, pagination, and both modals stay within the viewport.
- The mobile header displays the SoleSpace wordmark in light and dark mode.
- No page-level horizontal scrollbar appears.
```

- [ ] **Step 5: Complete the sequential standards/spec/risk review**

Review the final diff against the approved spec and record:

```text
- Simplify: no new abstraction or dependency; responsive behavior is expressed in existing Tailwind classes.
- Standards: existing Inertia/React/Tailwind conventions and test setup are preserved.
- Spec: all seven acceptance criteria are covered by tests, build evidence, or browser evidence.
- TypeScript/React: no new `any`, unsafe assertion, unused import, or unnecessary rerender path was introduced.
- Security/data integrity: no API, permission, attendance calculation, or form payload changed.
- Reuse/dead code: existing icons, handlers, components, and asset paths are reused; no orphaned code was added.
```

- [ ] **Step 6: Commit only if verification made a documentation-only adjustment**

If verification requires a small plan/spec correction, stage only that documentation file and use:

```powershell
git add -- docs/superpowers/plans/2026-08-13-attendance-responsive-branding.md docs/superpowers/specs/2026-08-13-attendance-responsive-branding-design.md
git commit -m "docs: record attendance responsive verification"
```

Otherwise, make no extra commit and report the exact commands and results.

## Plan self-review

- Spec coverage: responsive header, clock card, summary cards, overtime, history, modals, branding, accessibility, tests, build, browser widths, and out-of-scope boundaries are each mapped to Tasks 1–4.
- Placeholder scan: no vague or unspecified implementation step is used; all code changes include concrete snippets and exact paths.
- Type consistency: the `time-in-page` test id, `aria-live="polite"`, responsive heading classes, and responsive clock-action classes are produced by Task 3 and consumed by Task 1.
- Scope: only the approved Attendance page, shared wordmark/utility branding, tests, and verification records are changed; backend and business rules remain untouched.
