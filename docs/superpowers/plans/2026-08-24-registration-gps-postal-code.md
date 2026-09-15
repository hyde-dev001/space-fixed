# Registration GPS Postal Code Autofill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Populate the shop-owner registration Postal Code / ZIP Code field from reverse-geocoded GPS data without erasing a manually entered value when the provider omits a postcode.

**Architecture:** Add a small pure mapper beside the existing registration address parser. Both `Use My GPS` and map “Save location” will call that mapper, update the address/city as before, and update postal code only when the response provides one. No API contract or backend change is needed because the proxy already forwards Nominatim’s `address.postcode`.

**Tech Stack:** React 18, TypeScript 5.7, Vite/Vitest, Laravel 12 address-geocoding proxy, Tailwind CSS 4.

## Global Constraints

- Preserve unrelated working-tree changes and stage only files for this task.
- Use the existing `/api/address/geocode?latitude=...&longitude=...` proxy; do not call Nominatim directly from the browser.
- Keep the existing manual postal-code input behavior and numeric-only normalization.
- Rebuild `public/build` before pushing the feature branch.
- Rebase onto `origin/solespace-b` before pushing `fix/darkmode-selected-size`.

---

### Task 1: Add the failing postal-code mapping regression test

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/__tests__/registrationAddress.test.ts`
- Modify: `resources/js/Pages/UserSide/Auth/registrationAddress.ts`

**Interfaces:**
- Produces `getRegistrationAddressFields(result: unknown): { businessAddress: string; postalCode: string } | null` for the registration page.

- [x] **Step 1: Write the failing test**

Add a test that imports `getRegistrationAddressFields` and expects a Nominatim response with `address.postcode: '4114'` to produce `postalCode: '4114'`, while a response without a postcode produces an empty `postalCode` and keeps the display name.

```ts
it('maps reverse-geocoded postal codes for registration', () => {
  expect(getRegistrationAddressFields({
    display_name: 'Vibrant Street, Dasmarinas, Cavite',
    address: { postcode: '4114' },
  })).toEqual({
    businessAddress: 'Vibrant Street, Dasmarinas, Cavite',
    postalCode: '4114',
  });

  expect(getRegistrationAddressFields({
    display_name: 'Vibrant Street, Dasmarinas, Cavite',
    address: {},
  })).toEqual({
    businessAddress: 'Vibrant Street, Dasmarinas, Cavite',
    postalCode: '',
  });
});
```

- [x] **Step 2: Run the focused test and verify it fails**

Run:

```powershell
node.exe node_modules/vitest/vitest.mjs run resources/js/Pages/UserSide/Auth/__tests__/registrationAddress.test.ts --pool=threads --maxWorkers=1 --minWorkers=1 --reporter=dot
```

Expected: the test fails because `getRegistrationAddressFields` does not exist yet.

### Task 2: Implement one shared reverse-geocode mapper and wire both GPS entry points

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/registrationAddress.ts`
- Modify: `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx:16,600-642`

**Interfaces:**
- Consumes the raw JSON returned by `/api/address/geocode`.
- Produces `{ businessAddress, postalCode }` or `null` when no display name exists.

- [x] **Step 1: Add the minimal mapper**

Implement:

```ts
export const getRegistrationAddressFields = (result: unknown): {
  businessAddress: string;
  postalCode: string;
} | null => {
  const payload = result as {
    display_name?: unknown;
    address?: { postcode?: unknown };
  } | null;
  const businessAddress = typeof payload?.display_name === 'string'
    ? payload.display_name
    : '';
  if (!businessAddress) return null;

  const postalCode = typeof payload?.address?.postcode === 'string'
    ? payload.address.postcode.replace(/\D/g, '')
    : '';

  return { businessAddress, postalCode };
};
```

- [x] **Step 2: Apply the mapper in the GPS handler**

Import `getRegistrationAddressFields`, then replace the duplicated raw `data.display_name` update in `handleUseMyGPS` with:

```ts
const addressFields = getRegistrationAddressFields(data);
if (addressFields) {
  setGeoAddress(addressFields.businessAddress);
  setFormData((previous) => ({
    ...previous,
    businessAddress: addressFields.businessAddress,
    postalCode: addressFields.postalCode || previous.postalCode,
  }));
  setSelectedCity(inferCaviteCity(addressFields.businessAddress));
}
```

- [x] **Step 3: Apply the same mapper in the map save handler**

Use the same update block in `handleSaveAddress`, preserving the existing fallback error behavior when no display name is returned.

- [x] **Step 4: Run the focused test and verify it passes**

Run the same Vitest command from Task 1. Expected: the registration address tests pass.

### Task 3: Review, build, and deliver

**Files:**
- Include in commit: `resources/js/Pages/UserSide/Auth/registrationAddress.ts`, `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx`, its focused test, the approved design/plan docs, and generated `public/build` changes.

- [x] **Step 1: Run relevant frontend tests**

```powershell
node.exe node_modules/vitest/vitest.mjs run resources/js/Pages/UserSide/Auth/__tests__/registrationAddress.test.ts --pool=threads --maxWorkers=1 --minWorkers=1 --reporter=dot
node.exe node_modules/vitest/vitest.mjs run --pool=threads --maxWorkers=1 --minWorkers=1 --reporter=dot
```

- [x] **Step 2: Build production assets and check the diff**

```powershell
node.exe node_modules/vite/bin/vite.js build --logLevel error
git diff --check
```

Expected: Vite exits with code 0 and `git diff --check` prints no errors.

- [x] **Step 3: Review scope and commit only intended files**

```powershell
git diff --name-only
git diff --stat
git add docs/superpowers/specs/2026-08-24-registration-gps-postal-code-design.md docs/superpowers/plans/2026-08-24-registration-gps-postal-code.md resources/js/Pages/UserSide/Auth/registrationAddress.ts resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx resources/js/Pages/UserSide/Auth/__tests__/registrationAddress.test.ts public/build
git diff --cached --check
git commit -m "fix: autofill registration postal code from GPS"
```

- [x] **Step 4: Rebase and push the feature branch**

```powershell
git fetch origin --prune
git rebase origin/solespace-b
git push -u origin fix/darkmode-selected-size
```

Expected: the branch remains `fix/darkmode-selected-size`, unrelated worktree changes remain unstaged, and the PR target is `solespace-b`.
