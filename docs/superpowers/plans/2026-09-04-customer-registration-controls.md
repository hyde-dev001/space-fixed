# Customer Registration Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the customer registration address buttons match the `Next` button and expose password requirements on hover only.

**Architecture:** Keep the existing `Register.tsx` event handlers, form validation, map, and geolocation flow. Change only the two address-action class strings and remove the password panel's `group-focus-within` utilities; retain the absolute panel and existing accessibility text.

**Tech Stack:** React 18, TypeScript, Tailwind utility classes, Vitest.

## Global Constraints

- Scope is customer `/register` only: `resources/js/Pages/UserSide/Auth/Register.tsx`.
- Do not change address lookup, GPS, map, validation, or submission behavior.
- Use existing black `Next` button styling: `bg-black text-white hover:bg-black/85`.
- Keep the password panel absolute and non-layout-shifting.
- Do not add dependencies or stage unrelated working-tree files.

---

### Task 1: Add the regression contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts`

**Interfaces:**
- Consumes: the existing `Register.tsx` source-contract fixture.
- Produces: assertions for black address buttons and hover-only password guidance.

- [ ] **Step 1: Write the failing assertions**

Add one test that slices the address/password section and asserts both address buttons include `bg-black` and `text-white`, while the password field keeps `group-hover:opacity-100` and no longer contains `group-focus-within`.

- [ ] **Step 2: Run the focused test to verify it fails**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts
```

Expected: FAIL because the current address buttons still use blue classes and the password panel still includes `group-focus-within` utilities.

### Task 2: Apply the minimal UI change

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/Register.tsx:1297-1342`

**Interfaces:**
- Consumes: existing `handleAddressSearch`, `handleUseMyGPS`, `isSearching`, and `gettingGPS` state.
- Produces: unchanged address actions with updated visual classes and a hover-only password guidance panel.

- [ ] **Step 1: Update the two address button class strings**

Use the existing `Next` palette while preserving dimensions, loading labels, disabled state, and handlers:

```tsx
className="h-10 rounded-xl bg-black px-4 text-[12px] font-semibold text-white transition hover:bg-black/85 disabled:opacity-60"
```

and:

```tsx
className="h-10 rounded-xl bg-black px-3 text-[12px] font-semibold text-white transition hover:bg-black/85 disabled:opacity-60"
```

- [ ] **Step 2: Remove only the focus-triggered password utilities**

Keep the panel absolute, hidden by default, and shown on hover. Remove `group-focus-within:pointer-events-auto`, `group-focus-within:translate-y-0`, and `group-focus-within:opacity-100`; retain the existing `group-hover` utilities and `aria-describedby`.

- [ ] **Step 3: Run the focused test to verify it passes**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts
```

Expected: PASS with all registration password-guidance assertions green.

### Task 3: Verify the final revision

**Files:**
- Inspect: `resources/js/Pages/UserSide/Auth/Register.tsx`
- Inspect: `resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts`

**Interfaces:**
- Consumes: the completed UI change and regression coverage.
- Produces: verified frontend tests and a fresh production bundle.

- [ ] **Step 1: Run the registration test set**

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts
```

- [ ] **Step 2: Run the full frontend suite**

```powershell
.\node_modules\.bin\vitest.cmd run
```

- [ ] **Step 3: Build the production assets once on the final revision**

```powershell
.\node_modules\.bin\vite.cmd build
```

- [ ] **Step 4: Check the diff**

```powershell
git diff --check
git status --short
```

Confirm that only the approved source/test/docs files and fresh `public/build` are intended for the eventual push; preserve unrelated local files.
