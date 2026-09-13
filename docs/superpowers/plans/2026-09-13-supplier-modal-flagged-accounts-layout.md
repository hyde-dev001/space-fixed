# Supplier Modal and Flagged Accounts Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve Add New Supplier modal spacing and make the Flagged Accounts table larger, fixed-layout, readable, and visibly paginated without changing business behavior.

**Architecture:** Keep the existing page components, table primitive, Inertia paginator, filters, and action handlers. Apply responsive Tailwind classes in the two page components and add narrow frontend assertions for the new layout contracts and single-page pagination state.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Testing Library.

## Global Constraints

- Change only the two requested page components and their existing frontend tests.
- Reuse the existing `Table`, `TableCell`, `Modal`/native modal markup, colors, and action handlers.
- Do not change supplier fields, API payloads, controller queries, pagination size, routes, permissions, or moderation behavior.
- Keep the fixed table horizontally scrollable on narrow screens.
- Preserve accessible labels, focus states, disabled states, and minimum 44px interactive targets.
- Do not commit, stash, reset, or modify unrelated working-tree changes.

---

### Task 1: Add failing layout and pagination assertions

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/__tests__/Procurement.actions-layout.test.ts`
- Modify: `resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx`

**Interfaces:**
- Consumes: Existing source-string procurement contract test and existing mocked Inertia Flagged Accounts page.
- Produces: Failing checks for the viewport-safe supplier modal, fixed Flagged Accounts table, and visible one-page paginator.

- [x] **Step 1: Add supplier modal layout assertions**

Append this test to the existing `Procurement page actions layout` suite:

```ts
it("keeps the add supplier modal inside the viewport with a scrollable body", () => {
  expect(suppliersManagement).toContain(
    'overflow-y-auto p-4 sm:p-6',
  );
  expect(suppliersManagement).toContain(
    'max-h-[calc(100dvh-2rem)] w-full max-w-2xl',
  );
  expect(suppliersManagement).toContain(
    'min-h-0 flex-1 overflow-y-auto p-6 space-y-5',
  );
});
```

- [x] **Step 2: Add fixed table and one-page pagination assertions**

Add this test to `Flagged account state UI`:

```tsx
it("keeps the fixed table readable and shows disabled pagination on one page", () => {
  usePageMock.mockReturnValue({
    props: {
      flaggedAccounts: {
        data: [account("pending_review")],
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 1,
        from: 1,
        to: 1,
      },
      stats: {
        total: 1,
        pending_review: 1,
        under_investigation: 0,
        dismissed: 0,
        account_suspended: 0,
      },
      filters: { search: "", status: "all" },
    },
  });

  render(<FlaggedAccounts />);

  expect(screen.getByRole("table")).toHaveClass("min-w-[1280px]", "table-fixed");
  expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled();
  expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled();
  expect(screen.getByText(/Showing 1.1 of 1 reports/)).toBeInTheDocument();
});
```

- [x] **Step 3: Run the focused tests and verify they fail for the missing contracts**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/Procurement.actions-layout.test.ts resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
```

Expected: the existing tests pass, and the new assertions fail because the new modal/table classes and always-visible paginator are not implemented yet.

---

### Task 2: Make the Add New Supplier modal viewport-safe

**Files:**
- Modify: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx:469-576`

**Interfaces:**
- Consumes: Existing `isModalOpen`, `handleCloseModal`, `formData`, and `handleAddSupplier` behavior.
- Produces: A centered modal with responsive top/bottom space, a scrollable form region, and a footer that remains visible.

- [x] **Step 1: Update only the Add Supplier modal shell**

Use this structure for the existing Add Supplier modal wrapper, preserving all existing fields and handlers inside it:

```tsx
<div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4 sm:p-6">
  <button
    type="button"
    aria-label="Close add supplier modal"
    className="absolute inset-0 bg-black/50 erp-modal-backdrop"
    onClick={handleCloseModal}
  />
  <div className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:max-h-[calc(100dvh-3rem)]">
    <div className="flex shrink-0 items-center justify-between border-b border-gray-200 p-6 dark:border-gray-800">
      <h2 className="text-xl font-semibold text-gray-900 dark:text-white">Add New Supplier</h2>
      <button
        type="button"
        onClick={handleCloseModal}
        aria-label="Close add supplier modal"
        className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-2xl leading-none text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
      >
        ×
      </button>
    </div>

    <div className="min-h-0 flex-1 overflow-y-auto p-6 space-y-5">
      {/* Existing supplier form fields stay unchanged here. */}
    </div>

    <div className="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50 px-6 py-5 dark:border-gray-800 dark:bg-gray-800/50">
      {/* Existing Cancel and Add Supplier buttons stay unchanged here. */}
    </div>
  </div>
</div>
```

- [x] **Step 2: Keep form behavior unchanged**

Do not alter `formData`, `handleFormChange`, `handleAddSupplier`, API payloads, validation, success alerts, or error alerts. Only update shell/spacing classes and the close button's native type/accessible name.

- [x] **Step 3: Re-run the focused frontend tests**

Run the Task 1 command. Expected: the supplier modal layout assertions pass, while any remaining Flagged Accounts assertions still fail until Task 3.

---

### Task 3: Expand and fix the Flagged Accounts table and paginator

**Files:**
- Modify: `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx:280-417`

**Interfaces:**
- Consumes: Existing `serverPage`, `filteredAccounts`, `requestPage`, `filterOptions`, and `Table` primitives.
- Produces: A wider fixed-layout table with readable cell spacing and pagination controls rendered for every server paginator response.

- [x] **Step 1: Expand the page and table surfaces**

Change the page/card/table shell classes to the following values:

```tsx
<div className="min-h-screen bg-gray-50 p-6 lg:p-8 dark:bg-gray-900">
  <div className="mx-auto max-w-[1600px]">
    {/* existing header */}
    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/5 lg:p-8">
      {/* existing controls */}
      <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
        <Table className="min-w-[1280px] table-fixed">
          <colgroup>
            <col className="w-[19%]" />
            <col className="w-[22%]" />
            <col className="w-[14%]" />
            <col className="w-[14%]" />
            <col className="w-[10%]" />
            <col className="w-[13%]" />
            <col className="w-[8%]" />
          </colgroup>
          {/* existing TableHeader and TableBody */}
        </Table>
      </div>
    </div>
  </div>
</div>
```

- [x] **Step 2: Increase table readability without changing row data**

Use `px-5 py-4` for header cells and `px-5 py-6 align-middle` for body cells. Keep the existing seven columns and values, but apply these display rules:

```tsx
<TableCell isHeader className="whitespace-nowrap px-5 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
  Customer
</TableCell>
```

For body cells, use `leading-6`, `break-words` for email/reason/reporter text, `whitespace-nowrap tabular-nums` for the date, `inline-flex` plus `text-center` for status, and `min-h-11 whitespace-nowrap` for the Review button. Keep the existing icon and text values unchanged.

- [x] **Step 3: Render paginator summary and controls for every server page**

Replace the existing `serverPage && serverPage.last_page > 1` condition with `serverPage &&` and use this pagination markup while preserving `requestPage` calls:

```tsx
{serverPage && (
  <div className="mt-6 flex flex-col gap-4 border-t border-gray-200 px-1 pt-5 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
    <p className="text-sm text-gray-500 dark:text-gray-400">
      Showing {serverPage.from ?? 0}–{serverPage.to ?? 0} of {serverPage.total} reports
    </p>
    <div className="flex items-center gap-3">
      <button
        type="button"
        title="Previous page"
        aria-label="Previous page"
        disabled={serverPage.current_page <= 1}
        onClick={() => requestPage(Math.max(1, serverPage.current_page - 1))}
        className="inline-flex min-h-11 items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
      >
        Previous
      </button>
      <span className="min-w-24 text-center text-sm font-medium text-gray-700 dark:text-gray-300" aria-live="polite">
        Page {serverPage.current_page} of {serverPage.last_page}
      </span>
      <button
        type="button"
        title="Next page"
        aria-label="Next page"
        disabled={serverPage.current_page >= serverPage.last_page}
        onClick={() => requestPage(Math.min(serverPage.last_page, serverPage.current_page + 1))}
        className="inline-flex min-h-11 items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
      >
        Next page
      </button>
    </div>
  </div>
)}
```

- [x] **Step 4: Run the focused frontend tests**

Run:

```bash
pnpm exec vitest run resources/js/Pages/ERP/Procurement/__tests__/Procurement.actions-layout.test.ts resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
```

Expected: all tests in both files pass, including the new fixed-layout and one-page pagination assertions.

---

### Task 4: Verify the requested surface and inspect the final diff

**Files:**
- Inspect: `resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx`
- Inspect: `resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx`
- Inspect: `resources/js/Pages/ERP/Procurement/__tests__/Procurement.actions-layout.test.ts`
- Inspect: `resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx`

**Interfaces:**
- Consumes: The passing focused tests and final working-tree diff.
- Produces: Fresh evidence for the UI changes without modifying unrelated user work.

- [x] **Step 1: Run the frontend build**

Run (the direct Vite entrypoint was used because the PowerShell pnpm launcher was blocked):

```powershell
node node_modules/vite/bin/vite.js build --outDir C:\Users\Acer\AppData\Local\Temp\solespace-ui-build-20260913
```

Expected: Vite exits with code 0.

- [x] **Step 2: Run the focused tests once more after the build**

Run the Task 3 test command. Expected: all selected tests pass with exit code 0.

- [x] **Step 3: Inspect Git scope and whitespace**

Run:

```bash
git status --short
git diff --stat
git diff -- resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx resources/js/Pages/superAdmin/Users/FlaggedAccounts.tsx resources/js/Pages/ERP/Procurement/__tests__/Procurement.actions-layout.test.ts resources/js/Pages/superAdmin/Users/__tests__/FlaggedAccounts.test.tsx
git diff --check
```

Expected: only requested source/test/spec/plan files are attributable to this task; unrelated existing changes remain untouched, and `git diff --check` reports no whitespace errors.

- [x] **Step 4: Perform browser verification when the local app is available** — N/A: no local app server was running.

At desktop width, confirm the Add New Supplier modal has visible top/bottom breathing room and a usable footer. At a narrow viewport, confirm the modal scrolls internally. On Flagged Accounts, confirm the table is wider with stable columns, readable row spacing, horizontal scrolling at narrow width, and disabled Previous/Next controls plus the page summary on a one-page result.

Do not claim browser verification if the local app is not running or authentication prevents access.
