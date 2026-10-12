# HR and repair UI consistency refinement

## Goal

Apply the approved neutral SoleSpace presentation rules to the requested HR and repairer pages without changing business behavior, data contracts, canonical status values, calculations, permissions, or workflow transitions.

## Scope

### HR pages

- `resources/js/Pages/ERP/HR/EmployeeDirectory.tsx`
  - Keep the existing Add Employee handler, permission checks, button size, and visual treatment.
  - Align the existing action wrapper to the right above the employee metric grid.

- `resources/js/Pages/ERP/HR/AttendanceRecords.tsx`
  - Remove the standalone header placement of Download and place the same button in a compact, right-aligned toolbar immediately above the unchanged attendance table, visually associated with the Action column.
  - Keep `handleDownloadCSV`, filtered export data, permissions, and table columns unchanged.
  - Add a presentation-only status label mapping. The canonical `half_day` value remains unchanged in state/API data but renders as `Half Day`.
  - Use the existing green status treatment for `Half Day`, existing red treatment for `Late`, and preserve the other semantic status styles.
  - Render total hours as ordinary readable text without a background, badge, or colored fill. Do not change the hours value or calculation.

- `resources/js/Pages/ERP/HR/OvertimeApprovals.tsx`
  - Keep the existing Assign Overtime handler, permission gate, button styling, and modal behavior.
  - Align the page action wrapper to the right above the overtime metric grid and remove the layout gap caused by the old placement.

- `resources/js/Pages/ERP/HR/generateSlip.tsx`
  - Reduce the Payroll Period trigger to the existing compact control scale while preserving its selected period, dropdown, keyboard/click behavior, and metadata.
  - Change only the Generate Selected presentation to compact black primary styling with a readable disabled state. Keep selection, generation, calculations, and API calls unchanged.
  - Compact the Select Payroll Period modal shell and rows without removing search, scrolling, status metadata, close, or Done controls.
  - Replace blue hover/selection treatment in the affected payroll period and employee-selection UI with neutral gray/charcoal/black treatment. Keep semantic colors in unrelated payroll messages, statuses, charts, and icons.
  - Keep the payroll page's existing information sections and cards; where a statistic grid wrapper is present in the rendered markup, remove only the wrapper's card-like background, border, radius, or excess padding so the cards remain standalone.

- `resources/js/Pages/ERP/HR/SalaryChanges.tsx`
  - Keep the existing New Salary Change permission check, handler, disabled behavior, and button dimensions; align the page action to the right above the metric grid.
  - Change only the Propose Daily Rate Change modal's Submit Salary Change button to the existing black primary-action treatment. Preserve validation, form state, request payload, and submission flow.

### Repairer detail page

- `resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx`
  - Remove the blue fill from the Delivery progress logistics section while retaining its border/divider, data fields, and Refresh delivery status action.
  - Remove the purple fill from the Shop Delivery to Customer return-handoff section while keeping its title, messages, logistics fields, and workflow actions.
  - Remove the yellow fill from Customer's Collection Address while retaining the address data and a neutral section treatment.
  - Render Assigned to Repairer with plain/neutral text or an outlined neutral treatment, without changing the underlying repair status or transition logic.

## Visual rules

- Reuse existing Tailwind classes, page shells, metric-card patterns, compact controls, and black primary-button conventions already present in the ERP.
- Use white/transparent surfaces, subtle gray borders, gray hover states, and readable dark text for the requested neutral sections.
- Preserve semantic color only where the request explicitly keeps it meaningful, such as Late (red) and Half Day (green).
- Keep responsive flex/grid behavior and avoid introducing fixed widths or horizontal overflow.
- Do not add a shared global override because these changes are intentionally scoped to the named page consumers.

## Data and behavior invariants

- No Laravel routes, controllers, models, migrations, API contracts, authorization checks, database values, or backend enum/status values change.
- No attendance or payroll calculations change.
- No employee, overtime, salary-change, payroll-generation, download/export, or repair logistics handlers change.
- `half_day` remains the canonical value; only its rendered label changes to `Half Day`.
- Existing status filters continue to use canonical values, not presentation labels.

## Verification strategy

- Add or update focused frontend tests/source contracts for:
  - right-aligned HR actions;
  - Download being in the attendance table toolbar;
  - `half_day` rendering as `Half Day`, `Late` using red styling, and hours without a background;
  - compact/neutral payroll controls and modal selection;
  - black salary submit styling;
  - neutral repair logistics surfaces and Assigned to Repairer presentation.
- Run the focused HR and repairer Vitest tests first, then the full frontend test suite, production build, and `git diff --check`.
- Use browser verification for the affected routes at desktop and narrow viewport widths when the local app is runnable.

## Non-goals

- No broad ERP-wide color replacement.
- No redesign of unrelated pages or shared components.
- No change to the separate Warranty Queue refresh control unless it is included by a later, explicit request; the repair detail refresh action remains visible as specified above.
