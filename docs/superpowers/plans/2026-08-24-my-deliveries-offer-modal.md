# My Deliveries Offer and Picker UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve the rider's My Deliveries offer and mobile picker UX without changing the existing logistics API contract.

**Architecture:** Keep the change inside `MyDeliveries.tsx`. Reuse `DeliveryActionModal` for decline reasons and keep `logisticsApi.rejectLeg` / `rejectBatch` unchanged. Render the existing native picker only on desktop and a custom button/listbox picker on compact viewports so iOS cannot open a second native selection surface.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, Testing Library, Laravel logistics API.

## Global Constraints

- New assignment offers use a white surface and black/slate text and borders instead of the amber surface.
- Common decline reasons fill an editable textarea before submission.
- The existing `rejection_reason` API field and 1,000-character backend validation remain unchanged.
- Compact pickers close only through their close button, Escape, or selecting an option; unrelated outside taps do not dismiss them.
- Desktop picker behavior and existing delivery actions remain unchanged.
- Do not add dependencies or modify the unrelated Logistics controller, `package-lock.json`, Logistics page test, `.pnpm-store/`, or `DESIGN.md`.

---

### Task 1: Add failing regression tests for the approved UX

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx:293-319`
- Modify: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx:435-472`

**Interfaces:**
- Consumes: the existing `mocks.props.deliveryData`, `workItem`, `leg`, `render`, `fireEvent`, `within`, and `waitFor` helpers.
- Produces: failing behavior tests for the compact picker and both batch/standalone offer decline paths.

- [x] **Step 1: Replace the compact picker interaction with a custom-trigger regression test.**

Use the existing arrival validation setup, but open the picker through its button trigger and verify that the native combobox is absent, the modal stays open after an unrelated backdrop tap, and selecting an option closes it:

```tsx
  it('keeps the compact arrival picker open until an explicit picker action', async () => {
    mocks.arrive.mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          errors: {
            exception_reason: ['Reason required.'],
            arrival_result: ['outside_geofence'],
          },
        },
      },
    });
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [leg(10, null, 'in_transit')]);
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: "I've arrived" }));
    await waitFor(() => expect(screen.getByLabelText('Arrival reason')).toBeVisible());
    fireEvent.click(screen.getByLabelText('Arrival reason'));

    const picker = screen.getByRole('dialog', { name: 'Arrival reason' });
    expect(picker).toBeVisible();
    expect(screen.queryByRole('combobox', { name: 'Arrival reason' })).not.toBeInTheDocument();

    fireEvent.pointerDown(picker.parentElement as HTMLElement);
    expect(screen.getByRole('dialog', { name: 'Arrival reason' })).toBeVisible();

    fireEvent.click(within(picker).getByRole('option', { name: 'GPS location is inaccurate' }));
    expect(screen.getByLabelText('Arrival reason')).toHaveValue('gps_inaccurate');
    expect(screen.queryByRole('dialog', { name: 'Arrival reason' })).not.toBeInTheDocument();
  });
```

- [x] **Step 2: Replace the inline batch decline test with a modal and editable-reason test.**

The test must prove that Decline opens the modal, a suggestion fills the textarea, the rider can edit it, and the same batch API mock receives the edited string:

```tsx
  it('opens a batch decline modal with common reasons and an editable reason', async () => {
    mocks.props.deliveryData.offers = [
      workItem('batch', 'offered', [leg(1, 1)], { group: 'offer' }),
    ];

    render(<MyDeliveries />);

    const offerCard = screen.getByText('New assignment').closest('article');
    expect(offerCard).toHaveClass('bg-white', 'border-slate-950');
    expect(offerCard).not.toHaveClass('bg-amber-50', 'border-amber-300');

    fireEvent.click(screen.getByRole('button', { name: 'Decline batch' }));
    const dialog = screen.getByRole('dialog', { name: 'Decline batch' });
    expect(within(dialog).getByText('Common reasons')).toBeVisible();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Schedule conflict' }));
    const reason = within(dialog).getByLabelText('Decline reason');
    expect(reason).toHaveValue('Schedule conflict');

    fireEvent.change(reason, { target: { value: 'Schedule conflict - route changed' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm decline' }));

    await waitFor(() => expect(mocks.rejectBatch).toHaveBeenCalledWith(
      7,
      'Schedule conflict - route changed',
    ));
  });
```

- [x] **Step 3: Update the standalone decline test to use the modal path.**

Keep the existing accept assertion, then open the modal, choose `Safety concern`, edit it to a custom reason, and assert:

```tsx
    fireEvent.click(screen.getByRole('button', { name: 'Decline delivery' }));
    const dialog = screen.getByRole('dialog', { name: 'Decline delivery' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Safety concern' }));
    const reason = within(dialog).getByLabelText('Decline reason');
    fireEvent.change(reason, { target: { value: 'Safety concern near the pickup route' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm decline' }));

    await waitFor(() => expect(mocks.rejectLeg).toHaveBeenCalledWith(
      9,
      'Safety concern near the pickup route',
    ));
```

- [x] **Step 4: Run the focused test file and verify the new tests fail for the missing behavior.**

Run:

```powershell
cmd /c "node_modules\.bin\vitest.CMD run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx --pool=threads --maxWorkers=1 --minWorkers=1"
```

Expected: FAIL in the new tests because the current mobile picker still renders a native select and the current offer decline flow has no dialog or suggestion buttons.

---

### Task 2: Remove the duplicate mobile native picker surface

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx:70-228`

**Interfaces:**
- Consumes: the existing `PickerOption`, `CompactModalPicker` props, `isCompactViewport`, `pickerOptions`, and custom listbox markup.
- Produces: the same `value`/`onChange` contract for all arrival, issue, and incident pickers, with no native `<select>` rendered on compact viewports.

- [x] **Step 1: Add an explicit compact viewport branch.**

Inside `CompactModalPicker`, derive `const compact = isCompactViewport();` and keep `modalIsOpen` as `isOpen && compact`. Render a custom button on compact viewports and keep the existing select only for desktop:

```tsx
  const compact = isCompactViewport();
  const modalIsOpen = isOpen && compact;

  return (
    <div ref={pickerRef} className="relative mt-1">
      {compact ? (
        <button
          type="button"
          id={pickerId}
          aria-label={label}
          aria-haspopup="listbox"
          aria-controls={modalIsOpen ? dialogId : undefined}
          aria-expanded={modalIsOpen}
          onClick={() => setIsOpen(true)}
          className="flex min-h-12 w-full items-center justify-between rounded-2xl border border-slate-300 bg-white px-4 text-left text-base text-slate-950 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
        >
          <span>{selectedLabel}</span>
          <svg aria-hidden="true" className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none">
            <path d="m5 7.5 5 5 5-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </button>
      ) : (
        <select
          id={pickerId}
          aria-label={label}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className={`min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-950 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-950 dark:text-white ${className}`}
        >
          {pickerOptions.map(([option, optionLabel]) => (
            <option key={option || 'empty'} value={option}>{optionLabel}</option>
          ))}
        </select>
      )}
```

Retain the existing custom modal listbox beneath this branch so every option still calls `choose(option)`, which updates the parent value and closes the picker.

- [x] **Step 2: Remove outside-pointer dismissal from the custom picker.**

Delete the `closeOnOutsidePress` handler and its `pointerdown` document listener from the compact picker effect. Keep the Escape listener, resize cleanup, body overflow restoration, and focus restoration. Remove the backdrop `onPointerDown={() => setIsOpen(false)}` handler so an unrelated tap cannot dismiss the list.

- [x] **Step 3: Run the focused picker test and verify it passes.**

Run the same focused Vitest command from Task 1. Expected: the compact picker regression passes; the offer tests remain red until Task 3.

---

### Task 3: Convert offer decline to a modal and neutralize the card colors

**Files:**
- Modify: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx:470-489,1517-1600`

**Interfaces:**
- Consumes: `DeliveryActionModal`, `ActionRunner`, `logisticsApi.rejectLeg`, `logisticsApi.rejectBatch`, `pendingAction`, and the existing `online` state.
- Produces: `OfferCard` with a modal decline form that passes the trimmed edited textarea value to the existing rejection endpoints.

- [x] **Step 1: Add the fixed common-reason labels beside the existing logistics reason constants.**

Add this typed constant without introducing a new API code or backend mapping:

```tsx
const declineReasons = [
  'Schedule conflict',
  'Too far from my current location',
  'Vehicle or equipment problem',
  'Safety concern',
  'Already handling another delivery',
] as const;
```

- [x] **Step 2: Replace the inline decline state with modal content.**

Keep `declining` and `reason` state, but make the Decline button open the modal and render the common-reason buttons plus an editable textarea:

```tsx
  const openDeclineModal = () => {
    setReason('');
    setDeclining(true);
  };

  const declineAction = () => isBatch
    ? logisticsApi.rejectBatch(item.id, reason.trim())
    : logisticsApi.rejectLeg(item.id, reason.trim());

  const declineCancelClass =
    'min-h-12 w-full touch-manipulation rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold text-slate-950 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-white sm:w-auto';
  const declineSubmitClass =
    'min-h-12 w-full touch-manipulation rounded-xl bg-slate-950 px-4 text-sm font-bold text-white transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950 sm:w-auto';
```

Use the existing action key and runner for the submit button:

```tsx
<DeliveryActionModal
  modalId={`decline-${item.key}`}
  open={declining}
  title={`Decline ${offerLabel}`}
  description={`Tell dispatch why you cannot take this ${offerLabel}.`}
  onClose={() => setDeclining(false)}
>
  <div className="space-y-5">
    <fieldset>
      <legend className="text-sm font-bold text-slate-950 dark:text-white">Common reasons</legend>
      <div className="mt-3 grid gap-2 sm:grid-cols-2">
        {declineReasons.map((option) => (
          <button
            key={option}
            type="button"
            aria-pressed={reason === option}
            onClick={() => setReason(option)}
            className={`min-h-12 rounded-xl border px-3 text-left text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:focus:ring-white ${
              reason === option
                ? 'border-slate-950 bg-slate-950 text-white dark:border-white dark:bg-white dark:text-slate-950'
                : 'border-slate-300 bg-white text-slate-950 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:hover:bg-slate-900'
            }`}
          >
            {option}
          </button>
        ))}
      </div>
    </fieldset>

    <label htmlFor={`decline-reason-${item.key}`} className="block text-sm font-semibold text-slate-950 dark:text-white">
      Decline reason
      <textarea
        id={`decline-reason-${item.key}`}
        aria-label="Decline reason"
        rows={4}
        maxLength={1000}
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        placeholder="Choose a common reason or enter your own."
        className="mt-2 w-full resize-y rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
      />
      <span className="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">
        {reason.length}/1000 characters
      </span>
    </label>

    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
      <button type="button" onClick={() => setDeclining(false)} className={declineCancelClass}>
        Cancel
      </button>
      <button
        type="button"
        disabled={!online || !reason.trim() || pendingAction !== null}
        onClick={() => runAction(declineKey, declineAction)}
        className={declineSubmitClass}
      >
        Confirm decline
      </button>
    </div>
  </div>
</DeliveryActionModal>
```

- [x] **Step 3: Change the offer card surface and decline trigger.**

Change the offer article and `New assignment` label from amber classes to neutral classes:

```tsx
<article className="rounded-2xl border border-slate-950 bg-white p-5 text-slate-950 dark:border-white dark:bg-slate-950 dark:text-white xl:p-4">
  <p className="text-xs font-bold uppercase tracking-wide text-slate-950 dark:text-white">New assignment</p>
```

Keep the existing blue `Accept` button. Change the Decline button to call `openDeclineModal`, use black/white border/text states, and remove the old inline `{declining && (...)}` block. Render the `DeliveryActionModal` after the article content so it remains a sibling overlay rather than changing the card layout.

- [x] **Step 4: Run the focused My Deliveries test file and verify all offer/picker tests pass.**

Run:

```powershell
cmd /c "node_modules\.bin\vitest.CMD run resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx --pool=threads --maxWorkers=1 --minWorkers=1"
```

Expected: the focused file passes, including the custom compact picker test, batch modal test, and standalone modal test.

---

### Task 4: Review the scoped diff and run quality gates

**Files:**
- Review: `resources/js/Pages/ERP/Logistics/MyDeliveries.tsx`
- Review: `resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx`
- Generate only if tracked build output changes: `public/build/`

**Interfaces:**
- Consumes: the completed focused tests and the approved design spec.
- Produces: a verified, scoped frontend change ready for handoff.

- [x] **Step 1: Review for dead code and accidental color/API changes.**

Run:

```powershell
rg -n "declining|declineReasons|CompactModalPicker|bg-amber|border-amber|rejectLeg|rejectBatch" resources/js/Pages/ERP/Logistics/MyDeliveries.tsx
git diff --check
git diff --stat
```

Confirm that the old inline decline block is gone, `declining` is used only for the modal, no backend/service file changed, and amber classes remain only where they serve unrelated arrival/issue states.

- [x] **Step 2: Run the full frontend suite.**

Run:

```powershell
cmd /c "node_modules\.bin\vitest.CMD run --pool=threads --maxWorkers=1 --minWorkers=1"
```

Expected: all existing frontend test files pass.

- [x] **Step 3: Build the production frontend.**

Run:

```powershell
cmd /c "node_modules\.bin\vite.CMD build"
```

Expected: Vite exits with code 0. If tracked `public/build` hashes change, inspect them and include only the fresh generated output that corresponds to this source tree.

- [x] **Step 4: Confirm unrelated work is still untouched.**

Run:

```powershell
git status --short
git diff --name-only -- app/Http/Controllers/Logistics/ErpLogisticsController.php package-lock.json tests/Feature/Logistics/LogisticsPageAccessTest.php
```

Expected: the three pre-existing tracked files and `.pnpm-store/` / `DESIGN.md` remain uncommitted and are not included in the implementation staging set.

- [x] **Step 5: Commit only the implementation files and any verified generated build output.**

Stage exactly:

```powershell
git add -- resources/js/Pages/ERP/Logistics/MyDeliveries.tsx resources/js/Pages/ERP/Logistics/__tests__/MyDeliveries.test.tsx
```

If and only if the build generated tracked changes required by the repository, add `public/build` separately and inspect `git diff --cached --name-status` before committing. Commit with:

```powershell
git commit -m "fix: improve rider delivery offer and picker UX"
```
