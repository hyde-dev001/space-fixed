# Saved Address Management and Global Loading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve saved customer addresses across add/select flows, add edit/default/delete management with digits-only phone input, and show a polished accessible loader during the global first application load.

**Architecture:** Keep `CustomerAddressManager` as the address-list owner and reconcile its state with the existing Laravel address endpoints after every mutation. Add server-side phone validation without changing the address schema. Render a critical Blade preloader before the Inertia root and dismiss it from a small bootstrap utility after the first React page is rendered.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, Vitest, Testing Library, CSS/SVG.

## Global Constraints

- Reuse `/api/user/addresses` GET, POST, PUT, DELETE, and `/{id}/set-default`; do not change address schema or booking/order payloads.
- Treat phone numbers as digit strings so leading zeroes remain intact.
- Preserve unrelated working-tree changes in `package-lock.json` and `DESIGN.md`; never stage either file.
- Follow `DESIGN.md` black/white editorial styling, 8px spacing, restrained accents, readable labels, and vector-only icons.
- Respect `prefers-reduced-motion`; animate opacity/transform only; keep the loader non-blocking after initial handoff.
- Use TDD: each behavior gets a failing test before its production implementation.

---

### Task 1: Add failing address-management and phone-input tests

**Files:**
- Modify: `resources/js/components/address/__tests__/CustomerAddressManager.test.tsx`

**Interfaces:**
- Consumes: Existing `CustomerAddressManager` fetch contract and mocked `CustomerAddressMapPicker`.
- Produces: Regression coverage for list reconciliation, delete/default actions, selection fallback, and phone sanitization.

- [ ] **Step 1: Write the failing tests**

Add tests that mock the GET response as `[address, secondAddress]`, then assert:

```tsx
it('keeps the existing address after creating another saved address', async () => {
  const created = { ...address, id: 9, name: 'Ana Reyes', is_default: false };
  vi.mocked(fetch)
    .mockResolvedValueOnce(response({ addresses: [address] }))
    .mockResolvedValueOnce(response({ address: created }, true, 201))
    .mockResolvedValueOnce(response({ addresses: [address, created] }));

  render(<CustomerAddressManager onSelect={vi.fn()} />);
  await screen.findByText(/126 Ilang-ilang Street/);
  fireEvent.click(screen.getByRole('button', { name: 'Add address' }));
  fireEvent.change(screen.getByLabelText('Full name'), { target: { value: 'Ana Reyes' } });
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '09AB-987' } });
  fireEvent.click(screen.getByRole('button', { name: 'Choose map pin' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save address' }));

  expect(await screen.findByText(/Ana Reyes/)).toBeInTheDocument();
  expect(screen.getByText(/126 Ilang-ilang Street/)).toBeInTheDocument();
  expect(fetch).toHaveBeenLastCalledWith('/api/user/addresses', expect.objectContaining({ method: 'GET' }));
});
```

Add separate tests that click `Set as default` and `Delete`, assert the exact endpoint/method, and verify the remaining address is selected after deletion. Add an input test that enters `09AB-987` and expects the controlled Phone input value to be `09987`.

- [ ] **Step 2: Run the focused tests and verify the new tests fail for the missing behavior**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/components/address/__tests__/CustomerAddressManager.test.tsx
```

Expected: existing tests pass, while the new action/reconciliation assertions fail because the UI has no default/delete controls and does not reload after mutations.

---

### Task 2: Implement persistent saved-address management

**Files:**
- Modify: `resources/js/components/address/CustomerAddressManager.tsx`

**Interfaces:**
- Consumes: Existing `CustomerAddress` type, props, modal, and Laravel endpoints.
- Produces: A stable address reload/mutation flow with select, edit, default, and delete controls.

- [ ] **Step 1: Add a reload helper and mutation state**

Create a `loadAddresses` callback that fetches the complete list and preserves the valid selected address. Keep the current list on reload failure and expose the error. Add `mutatingId`/`mutatingAction` state so only the active card action is disabled.

- [ ] **Step 2: Replace post-save local-only merging with server reconciliation**

After POST/PUT succeeds, close the modal, call `loadAddresses(saved.id)`, and select the returned saved address. The reload must use the server list and must not replace the list with a single-address response.

- [ ] **Step 3: Add default and delete actions**

Use `POST /api/user/addresses/{id}/set-default` and `DELETE /api/user/addresses/{id}` with the existing CSRF/request headers. Confirm deletion with the project’s existing `Swal`/confirmation pattern when available; otherwise use a native confirmation fallback. Reload after each successful action and select the server default/first remaining address when needed.

- [ ] **Step 4: Sanitize the phone field before state updates and validate before save**

Use a `type="tel"` input with `inputMode="numeric"`, `pattern="[0-9]*"`, and `event.target.value.replace(/\D/g, '')`. Reject an empty phone with the existing inline error path and submit only the sanitized digit string.

- [ ] **Step 5: Improve the address card UI without changing the booking flow**

Keep the current selected-card treatment, add a compact default badge, and place `Edit`, `Set as default`, and `Delete` as accessible text actions with visible focus states. Keep all action targets at least 44px high, use the `DESIGN.md` monochrome palette, and include `aria-busy`/status text for async actions.

- [ ] **Step 6: Run the focused tests and verify they pass**

Run the same focused Vitest command from Task 1. Expected: all address-manager tests pass.

---

### Task 3: Enforce digits-only phone values at the Laravel boundary

**Files:**
- Modify: `app/Http/Controllers/UserSide/UserAddressController.php`
- Modify: `tests/Feature/UserSide/UserAddressCoordinateTest.php`

**Interfaces:**
- Consumes: Existing authenticated address store/update validation.
- Produces: The same response payloads with digits-only phone validation.

- [ ] **Step 1: Add the failing controller validation assertion**

Extend `tests/Feature/UserSide/UserAddressCoordinateTest.php` with a test that posts an address with `phone = '09AB-987'` and expects a validation response, while `phone = '09098765432'` remains valid.

- [ ] **Step 2: Run the focused Laravel test and verify it fails**

Run `php artisan test --filter=phone_values_must_contain_digits_only`. Expected: the alphabetic phone value is currently accepted because the controller uses only `string|max:20`.

- [ ] **Step 3: Change only the store/update phone rules**

Use the project’s Laravel validation style to replace `required|string|max:20` with `required|digits_between:7,20` in both `store` and `update`. This keeps phone values as strings, preserves leading zeroes, and rejects letters, punctuation, signs, and decimals.

- [ ] **Step 4: Run the focused Laravel test and verify it passes**

Run `php artisan test --filter=phone_values_must_contain_digits_only` again. Expected: invalid phone input is rejected and valid digit strings are accepted.

---

### Task 4: Add the global luxury first-load animation

**Files:**
- Modify: `resources/views/app.blade.php`
- Modify: `resources/css/app.css`
- Modify: `resources/js/app.jsx`
- Create: `resources/js/utils/appLoader.ts`
- Create: `resources/js/utils/__tests__/appLoader.test.ts`

**Interfaces:**
- Consumes: Existing Blade/Inertia root and React bootstrap.
- Produces: `dismissAppLoader()` that safely fades/removes the static `#solespace-app-loader` element after initial render.

- [ ] **Step 1: Write the failing loader utility test**

Add a test that appends a `div#solespace-app-loader`, calls `dismissAppLoader()`, and asserts it receives the `is-leaving` class and is removed after the transition fallback. Add a no-element test to ensure the helper is safe when the loader is absent.

- [ ] **Step 2: Run the loader test and verify it fails**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd exec vitest run resources/js/utils/__tests__/appLoader.test.ts
```

Expected: FAIL because the utility does not yet exist.

- [ ] **Step 3: Implement the minimal dismissal utility**

Create `dismissAppLoader()` with a single DOM lookup, an `is-leaving` class toggle, and a bounded cleanup timeout. Make cleanup idempotent so React Strict Mode or repeated bootstrap calls cannot throw.

- [ ] **Step 4: Add the critical static preloader markup**

Place the loader before `@inertia` in `resources/views/app.blade.php`. Use semantic status text, a CSS/SVG sneaker outline, and the SoleSpace wordmark. Keep all visual assets code-native and self-contained.

- [ ] **Step 5: Add styling and reduced-motion rules**

Add `.solespace-app-loader` styles and keyframes to `resources/css/app.css`. Use `#111111`, `#ffffff`, `#f5f5f5`, and the existing information accent. Animate only opacity/transform/stroke dash properties, and disable continuous motion under `@media (prefers-reduced-motion: reduce)`.

- [ ] **Step 6: Dismiss the loader after Inertia setup renders**

Import `dismissAppLoader` into `resources/js/app.jsx` and call it immediately after the existing `root.render(...)` branch. Do not add a route-wide overlay or alter existing page navigation behavior.

- [ ] **Step 7: Run the loader test and verify it passes**

Run the focused loader test again. Expected: all loader utility tests pass.

---

### Task 5: Verification and review

**Files:**
- Review: all files changed by Tasks 1–4
- Include: fresh `public/build` only if the user later requests a push/build handoff

- [ ] **Step 1: Run focused frontend tests**

Run the address and loader tests plus existing repair integration coverage.

- [ ] **Step 2: Run the complete frontend suite**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd run test:frontend -- --testTimeout=15000
```

Expected: zero failed tests.

- [ ] **Step 3: Build the frontend**

Run:

```powershell
$env:PNPM_CONFIG_PM_ON_FAIL='ignore'; pnpm.cmd run build
```

Expected: Vite exits with code 0.

- [ ] **Step 4: Run hygiene and dead-code checks**

Run `git diff --check`, inspect changed imports and references, and confirm `package-lock.json` and `DESIGN.md` remain unstaged/uncommitted user files.

- [ ] **Step 5: Browser verification when the local app is runnable**

Use the webapp-testing helper after running its `--help` command. Verify repair-services initial load, add two addresses, select the older one, edit it, set default, delete the newer one, and confirm the loader disappears without blocking the page.
