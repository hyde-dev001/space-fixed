# Landing Hero Animation Timing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Slow the landing hero headline, description, and CTA entrance animation while preserving the existing carousel, layout, loader handoff, and reduced-motion behavior.

**Architecture:** Keep the animation CSS colocated with the landing hero in `LandingPage.tsx`. Update only the existing duration and delay declarations, and protect the approved values with a source-level layout contract test.

**Tech Stack:** React 18, TypeScript/TSX, Inertia, inline CSS keyframes, Vitest, Vite, Laravel Blade asset delivery.

## Global Constraints

- Use the existing hero animation classes and keyframes; do not add a dependency.
- Headline timing is `1000ms` with delays `0ms`, `220ms`, and `440ms`.
- Description timing is `900ms` with an `820ms` delay.
- CTA timing is `900ms` with a `1080ms` delay.
- Preserve the `4500ms` hero carousel interval and `prefers-reduced-motion` override.
- Refresh and commit `public/build` after the final source revision.

---

### Task 1: Add the timing regression contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

**Interfaces:**
- Consumes: the current `LandingPage.tsx` source string already loaded by the test.
- Produces: assertions that fail until the approved animation timing is present.

- [ ] **Step 1: Write the failing assertions**

Add a test that requires the approved duration and stagger values while retaining the existing carousel and reduced-motion markers:

```ts
it('uses a slower premium timing for the hero copy entrance', () => {
  [
    'animation: hero-line-rise 1000ms cubic-bezier(0.22, 1, 0.36, 1) forwards;',
    'animation-delay: 220ms;',
    'animation-delay: 440ms;',
    'animation: hero-copy-fade 900ms cubic-bezier(0.22, 1, 0.36, 1) forwards;',
    'animation-delay: 820ms;',
    'animation-delay: 1080ms;',
    '}, 4500);',
    '@media (prefers-reduced-motion: reduce)',
  ].forEach((marker) => expect(landingSource).toContain(marker));
});
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
..\..\node_modules\.bin\vitest.CMD run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: the new timing test fails because the source still contains the faster `600ms`, `650ms`, `160ms`, `320ms`, `520ms`, and `700ms` values.

### Task 2: Apply the approved timing

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx:593-622`

**Interfaces:**
- Consumes: existing `hero-line-rise`, `hero-copy-fade`, `hero-line-*`, `hero-description`, and `hero-actions` classes.
- Produces: a slower visual entrance with no runtime API changes.

- [ ] **Step 1: Update only the existing declarations**

Use these values in the existing inline style block:

```css
.hero-headline-line {
  animation: hero-line-rise 1000ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
}

.hero-line-2 {
  animation-delay: 220ms;
}

.hero-line-3 {
  animation-delay: 440ms;
}

.hero-description {
  animation: hero-copy-fade 900ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  animation-delay: 820ms;
}

.hero-actions {
  animation: hero-copy-fade 900ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  animation-delay: 1080ms;
}
```

Keep the existing `4500` carousel interval and reduced-motion block unchanged.

- [ ] **Step 2: Run the focused test and verify GREEN**

Run the same Vitest command from Task 1. Expected: all landing layout tests pass.

### Task 3: Verify and deliver the generated bundle

**Files:**
- Modify: `public/build/` (generated output only)

- [ ] **Step 1: Run the complete frontend suite**

Run:

```powershell
..\..\node_modules\.bin\vitest.CMD run
```

Expected: zero failed tests.

- [ ] **Step 2: Rebase before the final build**

Run `git fetch origin --prune` and rebase onto `origin/solespace-b` while preserving only this task's changes.

- [ ] **Step 3: Build once from the final source**

Run:

```powershell
..\..\node_modules\.bin\vite.CMD build
```

Expected: Vite exits with code `0` and refreshes `public/build/manifest.json` and its hashed assets.

- [ ] **Step 4: Review, commit, and push**

Run `git diff --check`, stage only the plan/spec/source/test/docs files and `public/build`, commit with `perf: slow landing hero entrance`, and push `feature/account-dashboards`.
