# Low-End Loader Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the first-open SoleSpace loader compositor-friendly and adaptive so its motion stays smooth on low-end and mobile devices without changing its three-second lifecycle.

**Architecture:** Keep the server-rendered markup and JavaScript dismissal flow unchanged. Replace expensive loader-only CSS with transform-only full motion, a one-layer small-device fallback, reduced-motion behavior, and a lightweight static galaxy surface; lock the contract with the existing PHP feature test.

**Tech Stack:** Laravel 12 feature tests, Blade, Tailwind CSS 4 stylesheet, CSS media queries, Vitest, Vite 7.

## Global Constraints

- Preserve the loader's 3,000 millisecond first-open lifecycle and `solespace-app-ready` landing-animation handoff.
- Preserve the white background, light-gray `SOLESPACE` wordmark, and minimal galaxy visual.
- Add no dependency, browser fingerprinting, canvas, WebGL, or JavaScript hardware scoring.
- Do not stage or modify the unrelated local `package-lock.json` or `DESIGN.md`.
- Generate `public/build` only if explicitly requested in a later delivery step.

---

### Task 1: Lock the lightweight animation contract

**Files:**
- Modify: `tests/Feature/AppShellLoaderTest.php`
- Test: `tests/Feature/AppShellLoaderTest.php`

**Interfaces:**
- Consumes: the loader CSS block beginning with `.solespace-app-loader {` and ending before `@layer base`.
- Produces: regression assertions for transform-only movement, a bounded static galaxy surface, adaptive motion, and compositor-hint cleanup.

- [ ] **Step 1: Replace the old animation-shape assertions with the approved performance contract**

Update `test_loader_uses_the_approved_galaxy_reveal_styles()` so its loader-specific assertions are:

```php
self::assertStringContainsString('animation: solespace-loader-letter 720ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;', $loaderStyles);
self::assertStringContainsString('transform: translate3d(var(--loader-origin-x), var(--loader-origin-y), 0);', $loaderStyles);
self::assertStringContainsString('will-change: auto;', $loaderStyles);
self::assertStringContainsString('@media (max-width: 640px) and (pointer: coarse)', $loaderStyles);
self::assertStringContainsString('animation: solespace-loader-wordmark 560ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;', $loaderStyles);
self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $loaderStyles);
self::assertLessThanOrEqual(2, substr_count($loaderStyles, 'radial-gradient('));
self::assertStringNotContainsString('rotate(', $loaderStyles);
self::assertStringNotContainsString('scale(', $loaderStyles);
self::assertStringNotContainsString('filter:', $loaderStyles);
self::assertStringNotContainsString('box-shadow:', $loaderStyles);
self::assertStringNotContainsString('background-position', $loaderStyles);
self::assertStringNotContainsString('animation-delay:', $loaderStyles);
```

Retain the existing assertions for containment, nowrap text, no text shadow, the named letter keyframes, origin variables, white background, and gray text. Remove assertions requiring the old 840 ms linear animation or a permanent `will-change: transform` declaration.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
php artisan test tests/Feature/AppShellLoaderTest.php
```

Expected: FAIL in `test_loader_uses_the_approved_galaxy_reveal_styles` because the CSS still contains the old 840 ms linear translate/rotate/scale animation and oversized multi-gradient surface.

- [ ] **Step 3: Review the failure**

Confirm the failure is caused by the missing approved CSS contract, not a syntax error or unrelated test failure. Do not edit production CSS until that expected failure is observed.

### Task 2: Implement the adaptive transform-only loader

**Files:**
- Modify: `resources/css/app.css`
- Test: `tests/Feature/AppShellLoaderTest.php`

**Interfaces:**
- Consumes: unchanged Blade markup and unchanged `dismissAppLoader()` timing classes.
- Produces: `.solespace-app-loader` styles with full, small/coarse-pointer, and reduced-motion presentations.

- [ ] **Step 1: Replace the oversized galaxy paint layers**

Keep one `::before` pseudo-element at `inset: 0` and use only these two static layers:

```css
background:
  radial-gradient(circle at center, rgba(22, 35, 59, 0.08), transparent min(34rem, 68vw)),
  radial-gradient(circle, rgba(22, 35, 59, 0.24) 0 1px, transparent 1.4px) 0 0 / 10rem 10rem;
```

Remove the `.solespace-app-loader::after` block entirely. Neither layer may animate.

- [ ] **Step 2: Simplify every full-motion letter to translation only**

Remove the rotation variables and replace the span animation declaration with:

```css
animation: solespace-loader-letter 720ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;
backface-visibility: hidden;
transform: translate3d(0, 0, 0);
transform-origin: center;
```

Keep the nine existing X/Y origin values and delete every `--loader-origin-rotate` assignment. Replace the keyframes with:

```css
@keyframes solespace-loader-letter {
  0% {
    transform: translate3d(var(--loader-origin-x), var(--loader-origin-y), 0);
    will-change: transform;
  }

  100% {
    transform: translate3d(0, 0, 0);
    will-change: auto;
  }
}
```

- [ ] **Step 3: Add a one-layer fallback for small coarse-pointer devices**

Add before the reduced-motion query:

```css
@keyframes solespace-loader-wordmark {
  0% {
    transform: translate3d(0, 0.6rem, 0);
    will-change: transform;
  }

  100% {
    transform: translate3d(0, 0, 0);
    will-change: auto;
  }
}

@media (max-width: 640px) and (pointer: coarse) {
  .solespace-app-loader__wordmark {
    animation: solespace-loader-wordmark 560ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;
  }

  .solespace-app-loader__wordmark span {
    animation: none;
    transform: none;
    will-change: auto;
  }
}
```

Keep the existing reduced-motion query, remove its deleted `::after` selector, and include `.solespace-app-loader__wordmark` so both adaptive motion paths stop when reduced motion is requested.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run:

```powershell
php artisan test tests/Feature/AppShellLoaderTest.php
```

Expected: all tests in `AppShellLoaderTest` pass.

- [ ] **Step 5: Run the JavaScript loader lifecycle tests**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/utils/__tests__/appLoader.test.ts
```

Expected: 3 tests pass, proving the first-open duration, refresh behavior, and missing-loader behavior remain unchanged.

### Task 3: Verify, review, and prepare the branch

**Files:**
- Review: `resources/css/app.css`
- Review: `tests/Feature/AppShellLoaderTest.php`
- Review: `docs/superpowers/specs/2026-08-12-low-end-loader-performance-design.md`
- Review: `docs/superpowers/plans/2026-08-12-low-end-loader-performance.md`

**Interfaces:**
- Consumes: completed CSS and regression contract.
- Produces: a verified, reviewable branch with unrelated local work preserved.

- [ ] **Step 1: Run the full frontend suite**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run
```

Expected: all frontend test files pass with zero failures.

- [ ] **Step 2: Run the production build without committing generated output**

Run:

```powershell
.\node_modules\.bin\vite.cmd build
```

Expected: Vite exits 0. If `public/build` differs, leave it unstaged until the user explicitly requests a fresh build delivery.

- [ ] **Step 3: Run diff hygiene and inspect scope**

Run:

```powershell
git diff --check
git status --short
git diff -- resources/css/app.css tests/Feature/AppShellLoaderTest.php
```

Expected: no whitespace errors; only planned loader files plus known unrelated local files/build output appear.

- [ ] **Step 4: Perform the required sequential review**

Confirm:

- Simplify: no new dependency, JavaScript detector, or extra component was introduced.
- Standards/spec: implementation matches the approved design and existing naming conventions.
- TypeScript/performance: no TS change; CSS animation uses transform-only compositor work and adaptive motion.
- Security: N/A because no input, authorization, data, or network behavior changed.
- Reuse/dead code: existing markup and dismissal flow remain; removed pseudo-element and rotation variables have no references.
- Improvement evidence: before/after CSS contract records nine transform/rotate/scale/opacity animations and many gradients versus transform-only motion and at most two gradients. Runtime FPS is not measured in this environment.

- [ ] **Step 5: Commit only the implementation scope**

Run:

```powershell
git add resources/css/app.css tests/Feature/AppShellLoaderTest.php docs/superpowers/plans/2026-08-12-low-end-loader-performance.md
git commit -m "perf: smooth first-open loader motion"
```

Expected: the commit excludes `package-lock.json`, `DESIGN.md`, and `public/build` unless the user separately requests generated output.
