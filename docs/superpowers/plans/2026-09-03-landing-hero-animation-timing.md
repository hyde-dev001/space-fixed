# Landing Hero Animation Timing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the landing hero headline, description, and CTA entrance slower, smoother, and subtly surreal while preserving the existing carousel, layout, loader handoff, and reduced-motion behavior.

**Architecture:** Keep the animation CSS colocated with the landing hero in `LandingPage.tsx`. Update the existing duration/delay declarations and keyframes with a lightweight blur/scale bloom, and protect the approved values with a source-level layout contract test.

**Tech Stack:** React 18, TypeScript/TSX, Inertia, inline CSS keyframes, Vitest, Vite, Laravel Blade asset delivery.

## Global Constraints

- Use the existing hero animation classes and keyframes; do not add a dependency.
- Headline timing is `1400ms` with delays `0ms`, `300ms`, and `600ms`.
- Description timing is `1200ms` with a `1050ms` delay.
- CTA timing is `1200ms` with a `1450ms` delay.
- Use the existing CSS only for the subtle blur/scale bloom and gentle settle.
- Preserve the `4500ms` hero carousel interval and reset blur/transform/opacity under `prefers-reduced-motion`.
- Refresh and commit `public/build` after the final source revision.

---

### Task 1: Add the timing regression contract

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts`

**Interfaces:**
- Consumes: the current `LandingPage.tsx` source string already loaded by the test.
- Produces: assertions that fail until the approved animation timing is present.

- [x] **Step 1: Write the failing assertions**

Add a test that requires the approved duration, stagger, and dreamy motion values while retaining the existing carousel and reduced-motion markers:

```ts
it('uses slower, smoother timing for the hero copy entrance', () => {
  [
    'animation: hero-line-rise 1400ms cubic-bezier(0.16, 1, 0.3, 1) forwards;',
    'animation-delay: 300ms;',
    'animation-delay: 600ms;',
    'animation: hero-copy-fade 1200ms cubic-bezier(0.16, 1, 0.3, 1) forwards;',
    'animation-delay: 1050ms;',
    'animation-delay: 1450ms;',
    'filter: blur(8px);',
    'filter: blur(6px);',
    'will-change: transform, opacity, filter;',
    'filter: none !important;',
    '}, 4500);',
    '@media (prefers-reduced-motion: reduce)',
  ].forEach((marker) => expect(landingSource).toContain(marker));
});
```

- [x] **Step 2: Run the focused test and verify RED**

Run:

```powershell
..\..\node_modules\.bin\vitest.CMD run resources/js/Pages/UserSide/Products/LandingPage.layout.test.ts
```

Expected: the new timing test fails because the source still contains the previous `1000ms`, `900ms`, `220ms`, `440ms`, `820ms`, and `1080ms` values without the dreamy blur/scale treatment.

### Task 2: Apply the approved timing and motion treatment

**Files:**
- Modify: `resources/js/Pages/UserSide/Products/LandingPage.tsx:593-622`

**Interfaces:**
- Consumes: existing `hero-line-rise`, `hero-copy-fade`, `hero-line-*`, `hero-description`, and `hero-actions` classes.
- Produces: a slower visual entrance with no runtime API changes.

- [x] **Step 1: Update only the existing declarations and keyframes**

Use these values in the existing inline style block:

```css
.hero-headline-line {
  transform: translate3d(0, 36px, 0) scale(1.02);
  filter: blur(8px);
  animation: hero-line-rise 1400ms cubic-bezier(0.16, 1, 0.3, 1) forwards;
  will-change: transform, opacity, filter;
}

.hero-line-2 {
  animation-delay: 300ms;
}

.hero-line-3 {
  animation-delay: 600ms;
}

.hero-description {
  transform: translate3d(0, 24px, 0) scale(0.985);
  filter: blur(6px);
  animation: hero-copy-fade 1200ms cubic-bezier(0.16, 1, 0.3, 1) forwards;
  animation-delay: 1050ms;
  will-change: transform, opacity, filter;
}

.hero-actions {
  transform: translate3d(0, 24px, 0) scale(0.985);
  filter: blur(6px);
  animation: hero-copy-fade 1200ms cubic-bezier(0.16, 1, 0.3, 1) forwards;
  animation-delay: 1450ms;
  will-change: transform, opacity, filter;
}
```

Add the intermediate settle frames to the existing keyframes and keep the `4500` carousel interval. The reduced-motion block must also set `filter: none !important`.

- [x] **Step 2: Run the focused test and verify GREEN**

Run the same Vitest command from Task 1. Expected: all landing layout tests pass.

### Task 3: Verify and deliver the generated bundle

**Files:**
- Modify: `public/build/` (generated output only)

- [x] **Step 1: Run the complete frontend suite**

Run:

```powershell
..\..\node_modules\.bin\vitest.CMD run
```

Expected: zero failed tests.

- [x] **Step 2: Rebase before the final build**

Run `git fetch origin --prune` and rebase onto `origin/solespace-b` while preserving only this task's changes.

- [x] **Step 3: Build once from the final source**

Run:

```powershell
..\..\node_modules\.bin\vite.CMD build
```

Expected: Vite exits with code `0` and refreshes `public/build/manifest.json` and its hashed assets.

- [x] **Step 4: Review, commit, and push**

Run `git diff --check`, stage only the plan/spec/source/test/docs files and `public/build`, commit with `perf: refine landing hero entrance`, and push `feature/account-dashboards`.
