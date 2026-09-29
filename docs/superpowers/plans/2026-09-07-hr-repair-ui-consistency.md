# HR and repair UI consistency implementation plan

## Execution contract

- Implement only presentation/layout changes covered by the approved design spec.
- Preserve all existing handlers, state names, fetch/API calls, permission checks, filters, calculations, status values, and workflow actions.
- Prefer the smallest local Tailwind/className changes; do not introduce a shared global color override.
- Use focused tests before each implementation batch so the new presentation contract fails first.

## Task 1: Add failing presentation contracts

Files:

- Add `resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts`.
- Extend `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.visual.test.tsx` only where existing coverage can naturally assert the detail-section classes.

Steps:

1. Read the five HR page sources from the new test and assert the intended local structure/classes:
   - Employee Directory action wrapper contains right alignment above metrics.
   - Attendance Download is absent from the page header and present in the table toolbar; `half_day` has a display label of `Half Day`, `late` uses red styling, and the hours cell has no `bg-*` class.
   - Overtime and Salary Changes actions use right alignment above their metrics.
   - Payroll Period and Generate Selected use compact control classes, Generate Selected uses black primary styling, affected payroll selection classes contain no blue background/hover treatment, and any payroll-stat wrapper is not card-styled.
   - Salary modal submit uses black primary styling.
2. Assert the repair detail source keeps `Refresh delivery status` and removes blue/purple/amber fills from the requested detail blocks while making `Assigned to Repairer` neutral.
3. Run the focused new/updated tests and confirm they fail against the current implementation. Record the failures before changing source files.

## Task 2: Align HR page actions without touching behavior

Files:

- `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
- `resources/js/Pages/ERP/HR/OvertimeApprovals.tsx`
- `resources/js/Pages/ERP/HR/SalaryChanges.tsx`

Steps:

1. Change only the existing page-header/action wrapper alignment to right-align the already guarded buttons above each metric grid.
2. Keep existing button dimensions and handlers for Add Employee, Assign Overtime, and New Salary Change.
3. Change only the salary modal Submit Salary Change class from blue to the existing black primary pattern, retaining disabled behavior and all submit code.
4. Run the focused HR source/action tests and inspect the diff to verify no handler or permission lines changed.

## Task 3: Normalize Attendance presentation

File: `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`

Steps:

1. Add a local presentation-only label helper for attendance statuses. Map `half_day` to `Half Day` and normalize other visible snake_case labels without changing the stored `record.status` value used by filters/API/export.
2. Extend the local status-style helper so `late` uses the existing red/destructive classes and `half_day` uses the existing green classes.
3. Render the helper label in the status cell while preserving the canonical value for filtering and export.
4. Remove the old standalone Download header block and place the same button in a compact, right-aligned toolbar immediately inside the attendance table container above the unchanged table/columns.
5. Replace only the Hours badge classes with ordinary readable text classes; keep `record.totalHours` and its `h` suffix unchanged.
6. Run `AttendanceRecords.test.tsx` and the HR visual contract test before continuing.

## Task 4: Refine payroll controls and neutral selection states

File: `resources/js/Pages/ERP/HR/generateSlip.tsx`

Steps:

1. Reduce only the Payroll Period trigger padding/height/width classes while preserving the button label, selected period, dropdown behavior, and metadata.
2. Change Generate Selected to the compact black primary button classes and retain its existing disabled expression, count, title, and handler.
3. Compact the payroll period modal shell, list rows, and spacing without removing its search input, scroll container, statuses, close control, or Done control.
4. Replace blue hover/selected backgrounds and borders in the affected employee/period selection states with neutral gray/charcoal/black equivalents. Do not touch semantic blue informational UI outside those states.
5. Remove only any outer card-like wrapper around payroll statistic cards if present in the rendered payroll markup; keep the card content and data unchanged.
6. Run the payroll visual contract tests and build the page source through the focused Vitest suite.

## Task 5: Remove decorative repair detail fills

File: `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`

Steps:

1. Replace the Delivery progress blue surface classes with a neutral white/transparent surface and gray border, keeping all logistics fields and the Refresh delivery status button.
2. Replace the Shop Delivery to Customer purple surface classes with the same neutral treatment; keep action button semantics and logistics data.
3. Replace the Customer's Collection Address amber surface classes with a neutral section while retaining address rendering.
4. Locate the detail-view status presentation for `Assigned to Repairer` and remove only its blue fill, using readable neutral text/outline styling. Do not change `getRepairStatusLabel` or status transitions.
5. Run the existing repairer visual and logistics tests plus the new neutral-surface assertions.

## Task 6: Review and verification

1. Run the focused affected tests:

   ```powershell
   .\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/ERP/HR/__tests__/AttendanceRecords.test.tsx resources/js/Pages/ERP/HR/__tests__/SalaryChanges.owner-actions.test.tsx resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.visual.test.tsx resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.logistics.test.tsx
   ```

2. Run the full frontend tests:

   ```powershell
   .\\node_modules\\.bin\\vitest.cmd run
   ```

3. Run the production build:

   ```powershell
   .\\node_modules\\.bin\\vite.cmd build
   ```

4. Run `git diff --check` and inspect the final diff for changed presentation only.
5. If the local app is runnable, use the existing browser-testing workflow to inspect the affected routes at desktop and narrow widths, especially the moved actions, attendance toolbar, payroll modal, and repair detail sections.
6. Record Standards, Spec, TypeScript/React, simplify, security (`N/A` for no auth/input/backend changes), code-splitting (`N/A`), gauge (`not measured` unless evidence is collected), reuse, dead-code, and verification results in the final handoff.

## Expected files

- `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
- `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`
- `resources/js/Pages/ERP/HR/OvertimeApprovals.tsx`
- `resources/js/Pages/ERP/HR/generateSlip.tsx`
- `resources/js/Pages/ERP/HR/SalaryChanges.tsx`
- `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
- `resources/js/Pages/ERP/HR/__tests__/hr-ui-consistency.visual.test.ts`
- `resources/js/Pages/ERP/repairer/__tests__/JobOrdersRepair.visual.test.tsx`
- Generated `public/build` assets only if the production build updates tracked output.
