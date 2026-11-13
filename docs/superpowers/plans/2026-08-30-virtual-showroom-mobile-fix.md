# Virtual Showroom Mobile Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the Virtual Showroom's first-frame initialization crash and remove the shared customer navigation from the standalone showroom page without changing navigation on any other page.

**Architecture:** Keep the shared `Navigation` component unchanged and isolate the visual change at the standalone page boundary by removing its mount from `VirtualShowroomPage`. Fix the crash at its source by declaring `clearMovementKeys` before the Three.js render loop is first invoked. Use dynamic viewport-height classes only for the standalone showroom shell so mobile and tablet browser chrome does not distort the canvas.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript/TSX, Vite 7, Tailwind CSS 4, Three.js, Vitest.

## Global Constraints

- Change the standalone Virtual Showroom page only; keep shared navigation behavior unchanged on every other page.
- Remove only the moving promo bar, SoleSpace logo, hamburger, search, bell, chat/messages, and cart controls from the showroom.
- Keep Back to Shop Profile, Night Mode, room switching, joystick, drag/swipe controls, and product focus behavior.
- Do not change routes, controllers, product payloads, premium access, Three.js scene geometry, or global CSS.
- Use `pnpm` for frontend commands.
- Preserve all unrelated working-tree changes.

---

### Task 1: Add focused showroom regression tests

**Files:**
- Create: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts`

**Interfaces:**
- Consumes: Source text from `VirtualShowroomPage.tsx` and `VirtualShowroom.tsx`.
- Produces: Regression coverage for standalone navigation isolation, dynamic viewport sizing, and callback initialization order.

- [x] **Step 1: Write the failing contract tests**

Create the contract test with the existing repository pattern for source-level frontend tests:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const pageSource = readFileSync(
  resolve('resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx'),
  'utf8',
);
const showroomSource = readFileSync(
  resolve('resources/js/Pages/UserSide/Products/VirtualShowroom.tsx'),
  'utf8',
);

describe('standalone virtual showroom', () => {
  it('does not mount shared customer navigation', () => {
    expect(pageSource).not.toContain("import Navigation from '../Shared/Navigation';");
    expect(pageSource).not.toContain('{!isFocusMode && <Navigation />}');
    expect(pageSource).toContain('Back to Shop Profile');
  });

  it('uses dynamic viewport height for the standalone layout', () => {
    expect(pageSource).toContain('className="h-dvh overflow-hidden bg-white"');
    expect(pageSource).toContain('<main className="h-dvh">');
    expect(showroomSource).toContain("? 'h-dvh w-full bg-white'");
    expect(showroomSource).toContain("isStandalonePage ? 'h-dvh min-h-0'");
  });

  it('declares movement cleanup before starting the render loop', () => {
    const movementCleanup = showroomSource.indexOf('const clearMovementKeys = () => {');
    const firstRenderLoopInvocation = showroomSource.indexOf('\n\t\tanimate();');

    expect(movementCleanup).toBeGreaterThan(-1);
    expect(firstRenderLoopInvocation).toBeGreaterThan(-1);
    expect(movementCleanup).toBeLessThan(firstRenderLoopInvocation);
  });
});
```

- [x] **Step 2: Run the focused test to verify it fails for the intended reasons**

Run:

```text
pnpm exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
```

Expected: FAIL because the page still imports/mounts `Navigation`, uses `h-screen`, and `clearMovementKeys` is currently declared after the first `animate()` call. Do not change production code before observing these failures.

- [x] **Step 3: Commit the regression test**

```text
git add -- resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
git commit -m "test: cover standalone showroom shell"
```

### Task 2: Isolate the showroom shell and make its viewport responsive

**Files:**
- Modify: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx:1-73`
- Modify: `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx:2250,2287`
- Modify: `resources/js/Pages/UserSide/Shared/__tests__/navigationCoverage.contract.test.ts`
- Test: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts`

**Interfaces:**
- Consumes: Existing standalone page props and existing `VirtualShowroom` props.
- Produces: A full-viewport showroom that does not render the shared navigation and uses `dvh` sizing on mobile/tablet.

- [x] **Step 1: Remove only the standalone page's Navigation import and render**

In `VirtualShowroomPage.tsx`, delete:

```tsx
import Navigation from '../Shared/Navigation';
```

and delete:

```tsx
{!isFocusMode && <Navigation />}
```

Leave the focus-mode state because it still controls the back-link visibility, and leave the existing back link unchanged.

- [x] **Step 2: Replace standalone `h-screen` sizing with `h-dvh`**

Change the page shell and main element to:

```tsx
<div className="h-dvh overflow-hidden bg-white">
  <Head title={`${shop.name} - Virtual Showroom`} />

  <main className="h-dvh">
```

In `VirtualShowroom.tsx`, change only the standalone section/canvas branches to:

```tsx
? 'h-dvh w-full bg-white'
```

and:

```tsx
isStandalonePage ? 'h-dvh min-h-0'
```

Keep the non-standalone classes unchanged.

Update the shared-navigation coverage contract to classify `VirtualShowroomPage` as
a standalone exception while keeping the shared `Navigation` requirement for all
other customer pages.

- [x] **Step 3: Run the two shell-focused tests**

Run:

```text
pnpm exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts -t "does not mount shared customer navigation|uses dynamic viewport height"
```

Expected: PASS for the two shell tests; the initialization-order test remains red until Task 3.

- [x] **Step 4: Commit the isolated shell change**

```text
git add -- resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx resources/js/Pages/UserSide/Products/VirtualShowroom.tsx
git commit -m "fix: isolate virtual showroom navigation"
```

### Task 3: Fix the first-frame initialization error

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/VirtualShowroom.tsx:1746-2055`
- Test: `resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts`

**Interfaces:**
- Consumes: Existing `keyState`, `clearJoystickVector`, event handlers, and Three.js render loop.
- Produces: A render loop that can safely call movement cleanup on its first frame and on blur/visibility changes.

- [x] **Step 1: Move the existing cleanup callback before the render loop**

Move this exact existing callback block:

```tsx
const clearMovementKeys = () => {
  keyState.forward = false;
  keyState.backward = false;
  keyState.left = false;
  keyState.right = false;
  clearJoystickVector();
};
```

from its current position after `const handleKeyUp = ...` to immediately before `const animate = () => {`. Do not change its body or the event listener registrations.

- [x] **Step 2: Run the full focused regression test**

Run:

```text
pnpm exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
```

Expected: PASS for all three tests, including the ordering assertion that prevents the minified `Xe` temporal-dead-zone error.

- [x] **Step 3: Commit the root-cause fix**

```text
git add -- resources/js/Pages/UserSide/Products/VirtualShowroom.tsx
git commit -m "fix: initialize showroom movement cleanup before render"
```

### Task 4: Run project quality and browser checks

**Files:**
- Modify: none unless a verification failure identifies a regression in the changed files.

**Interfaces:**
- Consumes: The completed showroom changes and regression test.
- Produces: Fresh evidence that the frontend test suite, production build, diff hygiene, and browser-visible showroom behavior are healthy.

- [x] **Step 1: Run all frontend tests**

```text
pnpm run test:frontend
```

The pnpm shim could not verify its pinned package-manager signature in this
environment, so the installed local Vitest binary was used instead. The full
suite ran with 157 passing files/tests groups and one pre-existing failure in
`Navigation.contract.test.ts` for the unrelated `myRepairs` heading contract.

- [x] **Step 2: Build the production assets**

```text
cmd /c node_modules\\.bin\\vite.cmd build
```

Expected: exit code 0 and a newly generated showroom asset without compile errors.

- [x] **Step 3: Check diff hygiene**

```text
git diff --check HEAD~3..HEAD
```

Expected: no whitespace errors. If commits are not created individually, run `git diff --check` against the working tree instead.

- [x] **Step 4: Verify the showroom in a browser at multiple sizes**

The local route was checked and correctly returned 403 because the local database
has no premium subscription rows. A temporary Playwright/Vite harness mounted
the actual showroom component at 390×844 and 768×1024 and checked:

- desktop: the 3D showroom loads without `ReferenceError: Cannot access 'Xe' before initialization`;
- mobile portrait and landscape: the page loads and the canvas fills the dynamic viewport;
- tablet portrait and landscape: the canvas remains operable without horizontal overflow;
- showroom only: no promo ticker, SoleSpace logo, hamburger, search, bell, chat, or cart is rendered;
- another user-side page: shared navigation still renders normally.

- [x] **Step 5: Record final status**

Report the exact commands and exit results, list changed files, confirm unrelated working-tree changes were preserved, and identify any browser verification that was unavailable rather than implying it passed.
