# SoleSpace Galaxy Loader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a minimal galaxy-inspired letter-convergence animation to the existing three-second SoleSpace first-open loader.

**Architecture:** Keep the existing Blade loader markup and React lifecycle untouched. Extend the loader's CSS with layered radial gradients for the star field/halo and per-letter CSS custom properties for travel origins, then verify the existing loader contract and production bundle.

**Tech Stack:** Laravel Blade, CSS in `resources/css/app.css`, PHPUnit, Vitest, Vite 7.

## Global Constraints

- Do not change the session-storage key, first-open timing, or React loader removal logic.
- Use CSS-only visuals; add no image, canvas, dependency, or network request.
- Preserve `role="status"`, `aria-live`, `aria-label`, and reduced-motion behavior.
- Preserve unrelated working-tree changes; do not stage or commit unrelated files.

---

### Task 1: Lock the galaxy-loader contract with a focused test

**Files:**
- Modify: `tests/Feature/AppShellLoaderTest.php`
- Read: `resources/views/app.blade.php`
- Read: `resources/css/app.css`

**Interfaces:**
- Consumes: the existing app-shell loader markup and stylesheet.
- Produces: a focused regression assertion that the loader has the approved galaxy layers and convergence animation.

- [ ] **Step 1: Add the failing style-contract assertions**

Extend the existing test with a second test method that reads `resources/css/app.css` and asserts these exact design hooks are present:

```php
public function test_loader_uses_the_approved_galaxy_reveal_styles(): void
{
    $styles = file_get_contents(resource_path('css/app.css'));

    self::assertIsString($styles);
    self::assertStringContainsString('solespace-loader-stars', $styles);
    self::assertStringContainsString('solespace-loader-letter', $styles);
    self::assertStringContainsString('--loader-origin-x', $styles);
    self::assertStringContainsString('radial-gradient', $styles);
}
```

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```powershell
php artisan test tests/Feature/AppShellLoaderTest.php
```

Expected: the existing markup test passes and the new galaxy-style assertion fails because the stylesheet does not yet contain the approved star/reveal hooks.

### Task 2: Implement the CSS-only galaxy reveal

**Files:**
- Modify: `resources/css/app.css:314-476`

**Interfaces:**
- Consumes: the existing `.solespace-app-loader__wordmark` spans and the existing `.solespace-app-loader__line`.
- Produces: the `.solespace-loader-stars` animation hook, per-letter `--loader-origin-x` variables, and the `solespace-loader-letter` convergence animation.

- [ ] **Step 1: Add the layered minimal galaxy field**

Keep the existing navy gradient and replace the single glow background with a small set of low-contrast radial gradients named by the `solespace-loader-stars` animation. Add a separate `::after` halo layer and keep both layers behind `.solespace-app-loader__content`.

- [ ] **Step 2: Add per-letter travel origins**

Keep the nine existing spans and assign each one a distinct `--loader-origin-x`, `--loader-origin-y`, and `--loader-origin-rotate` value through `:nth-child()` selectors. The values must stay within the viewport-safe range so letters converge without horizontal overflow.

- [ ] **Step 3: Replace the bounce-only keyframes with convergence keyframes**

Use `transform` and `opacity` only. The first keyframe uses each span's origin and a small scale, the early keyframe settles it into its final position, and the later keyframes hold a subtle premium shimmer/bounce. Keep the existing staggered delays and a sub-three-second loop so the complete reveal fits inside the existing loader lifecycle.

- [ ] **Step 4: Preserve reduced-motion behavior**

Include `.solespace-app-loader::before`, `.solespace-app-loader::after`, and the letter spans in the reduced-motion rule. With motion disabled, the wordmark must render readable and centered without relying on animation keyframes.

### Task 3: Verify the integrated loader and production assets

**Files:**
- Verify: `resources/views/app.blade.php`
- Verify: `resources/css/app.css`
- Regenerate: `public/build/`

**Interfaces:**
- Consumes: the completed CSS-only loader.
- Produces: a passing focused test result and fresh production assets.

- [ ] **Step 1: Run the focused Laravel loader test**

Run `php artisan test tests/Feature/AppShellLoaderTest.php` and expect all tests to pass.

- [ ] **Step 2: Run the loader utility tests**

Run `./node_modules/.bin/vitest.cmd run resources/js/utils/__tests__/appLoader.test.ts --pool=forks --no-file-parallelism` and expect all three loader tests to pass.

- [ ] **Step 3: Build the production bundle**

Run `./node_modules/.bin/vite.cmd build` and expect Vite to finish successfully while refreshing `public/build/manifest.json` and its hashed assets.

- [ ] **Step 4: Check diff hygiene**

Run `git diff --check` and expect no whitespace errors. Confirm `package-lock.json`, `DESIGN.md`, and unrelated existing source changes remain untouched.
