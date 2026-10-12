# Logistics Module Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the existing logistics module filter into the Available deliveries filter row and keep All modules, Retail, and Repair filtering behavior synchronized with the backend.

**Architecture:** Reuse `Batches.tsx`'s existing `module` state and `changeModule` route handler. Pass the available module options and callback into `AvailableDeliveriesPanel`, render one responsive select beside the existing date/window/status controls, and remove the duplicate header select. Reset module together with the existing clear-filters action using one request.

**Tech Stack:** Laravel/Inertia, React 18, TypeScript, Tailwind CSS, Vitest, Testing Library.

## Global Constraints

- Preserve the existing `/erp/logistics/batches` and shop-owner batches route behavior.
- Do not add dependencies, APIs, or new filtering abstractions.
- Keep unrelated local changes untouched, including the existing `Proceed` rename and untracked folders.
- Keep the filter keyboard-accessible with a visible accessible label and existing native select behavior.

---

### Task 1: Add the filter behavior tests

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx`

- [ ] **Step 1: Update the module-filter test to target the Available deliveries control**

Use the existing `availableModules: ['retail', 'repair']` fixture and assert the filter is inside the available-deliveries panel:

```tsx
expect(screen.getByTestId('batch-filter-grid')).toContainElement(
  screen.getByLabelText('Filter deliveries by module'),
);
expect(screen.getByRole('option', { name: 'All modules' })).toBeInTheDocument();
expect(screen.getByRole('option', { name: 'Retail' })).toBeInTheDocument();
expect(screen.getByRole('option', { name: 'Repair' })).toBeInTheDocument();
expect(screen.queryByLabelText('Filter batches by module')).not.toBeInTheDocument();
```

- [ ] **Step 2: Add a failing clear-filter assertion**

Select `repair`, click `Clear filters`, and assert the delivery module select returns to `all` and the final router request uses the default module:

```tsx
fireEvent.change(screen.getByLabelText('Filter deliveries by module'), { target: { value: 'repair' } });
fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));

expect(screen.getByLabelText('Filter deliveries by module')).toHaveValue('all');
expect(mocks.get).toHaveBeenLastCalledWith('/erp/logistics/batches', {
  module: 'all',
  date: undefined,
  window: 'morning',
}, expect.objectContaining({ only: ['batches', 'pool', 'unscheduled', 'filters'] }));
```

- [ ] **Step 3: Run the focused test file and verify the new assertions fail for the missing control/reset**

Run:

```powershell
node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: the tests fail because the module control is still in the header and clear filters does not reset its state.

### Task 2: Move and wire the existing module filter

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/components/AvailableDeliveriesPanel.tsx`
- Modify: `resources/js/Pages/ERP/Logistics/Batches.tsx`

- [ ] **Step 1: Extend the panel props with the existing module values and callback**

Add `module: 'all' | LogisticsModule`, `availableModules: LogisticsModule[]`, `showModuleFilter: boolean`, and `onModuleChange: (value: 'all' | LogisticsModule) => void` to the panel props, then render the select only when `showModuleFilter` is true:

```tsx
<MonochromeSelect
  aria-label="Filter deliveries by module"
  value={module}
  onChange={(event) => onModuleChange(event.target.value as 'all' | LogisticsModule)}
  className="min-h-11 rounded-xl border border-gray-300 px-3 text-sm"
>
  <option value="all">All modules</option>
  {availableModules.map((available) => (
    <option key={available} value={available}>{logisticsModuleLabel(available)}</option>
  ))}
</MonochromeSelect>
```

Place it in the existing responsive filter grid between the delivery window and schedule status controls. Set the grid to `grid-cols-1 sm:grid-cols-2 xl:grid-cols-4` so all four controls share one desktop row and wrap cleanly at smaller widths.

- [ ] **Step 2: Pass module state/options to the panel and remove the header duplicate**

Remove the `showModuleFilter` header select in `Batches.tsx`. Pass `module`, `availableModules`, `showModuleFilter`, and `onModuleChange={(value) => changeModule(value)}` into `AvailableDeliveriesPanel`.

- [ ] **Step 3: Reset module with clear filters using one existing route request**

Extend `changeSlot` with an optional `nextModule` defaulting to the current module, update local module state when it changes, and use `nextModule` in the existing `router.get` payload. Change `clearFilters` to call `changeSlot('', 'morning', showModuleFilter ? 'all' : module)` so visible module filters reset in one request without changing the server-side filter on single-module pages.

- [ ] **Step 4: Run the focused tests and verify they pass**

Run:

```powershell
node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx
```

Expected: all Batches tests pass, including module selection and clear-filter reset.

### Task 3: Final verification

- [ ] **Step 1: Run the broader frontend test command available in the repository**

Run:

```powershell
node_modules\.bin\vitest.cmd run resources/js/Pages/ERP/Logistics/__tests__/Batches.test.tsx resources/js/Pages/ERP/Logistics/__tests__/Logistics.actions-layout.test.ts
```

Expected: all selected logistics tests pass.

- [ ] **Step 2: Inspect the final diff and whitespace**

Run:

```powershell
git diff --check
git diff --stat
git status --short
```

Expected: only the requested panel/page/test/plan files are changed; unrelated local files remain untouched and no whitespace errors are reported.
