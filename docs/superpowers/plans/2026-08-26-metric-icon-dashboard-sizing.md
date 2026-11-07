# Metric Icon Dashboard Sizing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Match every legacy ERP and shop-owner metric card's primary icon treatment to the dashboard's 48x48 tile and 24x24 icon.

**Architecture:** Extend the existing shared metric-card CSS in `resources/css/app.css`; do not edit page-local metric components. A focused Vitest source regression test locks the approved dimensions and selector scope, then Vite regenerates `public/build`.

**Tech Stack:** Tailwind CSS 4, CSS, Vitest 3, Vite 7, pnpm project tooling

## Global Constraints

- Primary metric icon tile must be exactly 48x48 pixels.
- Direct child metric icon SVG must be exactly 24x24 pixels.
- Legacy tile radius must match dashboard `rounded-xl` (12 pixels).
- Existing 1.5 stroke-width treatment remains.
- Trend arrows, badges, table icons, filters, and action icons must remain unchanged.
- Preserve and exclude unrelated working-tree changes.

---

### Task 1: Lock and implement shared metric icon dimensions

**Files:**
- Create: `resources/js/__tests__/metricCardSizing.test.ts`
- Modify: `resources/css/app.css:46-63`

**Interfaces:**
- Consumes: existing `.metrics-card` and legacy metric-card selectors in `resources/css/app.css`
- Produces: shared 48x48 legacy icon tiles and 24x24 primary SVG icons

- [ ] **Step 1: Write the failing source regression test**

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(resolve('resources/css/app.css'), 'utf8');

const ruleAfter = (marker: string) => {
  const markerIndex = appCss.indexOf(marker);
  expect(markerIndex).toBeGreaterThanOrEqual(0);

  const openingBrace = appCss.indexOf('{', markerIndex);
  const closingBrace = appCss.indexOf('}', openingBrace);

  return {
    declarations: appCss.slice(openingBrace + 1, closingBrace),
    selectors: appCss.slice(markerIndex + marker.length, openingBrace),
  };
};

describe('shared metric card icon sizing', () => {
  it('matches the dashboard tile and icon dimensions', () => {
    const tileRule = ruleAfter('/* Match legacy primary metric icon tiles to the dashboard dimensions. */');
    const iconRule = ruleAfter('/* Keep the main metric icon outlines as light as the dashboard icons. */');

    expect(tileRule.declarations).toContain('width: 3rem !important;');
    expect(tileRule.declarations).toContain('height: 3rem !important;');
    expect(tileRule.declarations).toContain('border-radius: 0.75rem !important;');
    expect(iconRule.declarations).toContain('width: 1.5rem !important;');
    expect(iconRule.declarations).toContain('height: 1.5rem !important;');
    expect(iconRule.declarations).toContain('stroke-width: 1.5 !important;');
  });

  it('scopes the tile override to primary non-overlay metric icon containers', () => {
    const tileRule = ruleAfter('/* Match legacy primary metric icon tiles to the dashboard dimensions. */');

    expect(tileRule.selectors).toContain('.metrics-card');
    expect(tileRule.selectors).toContain('[class~="group"]');
    expect(tileRule.selectors).toContain(':not([class*="absolute"])');
  });
});
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
node node_modules/vitest/vitest.mjs run resources/js/__tests__/metricCardSizing.test.ts --reporter=dot
```

Expected: FAIL because the dashboard-dimensions CSS marker and 48x48/24x24 declarations do not exist yet.

- [ ] **Step 3: Add the minimal shared CSS sizing rules**

Insert before the existing stroke-width rule:

```css
/* Match legacy primary metric icon tiles to the dashboard dimensions. */
.metrics-card :is([class*="bg-gradient-to-br"], [class*="bg-linear-to-br"]):not([class*="absolute"]),
[class~="group"][class~="relative"][class~="overflow-hidden"][class~="rounded-2xl"][class~="border-gray-200"][class~="bg-white"][class~="p-5"] :is([class*="bg-gradient-to-br"], [class*="bg-linear-to-br"]):not([class*="absolute"]),
[class~="group"][class~="relative"][class~="overflow-hidden"][class~="rounded-2xl"][class~="border-gray-200"][class~="bg-white"][class~="p-6"] :is([class*="bg-gradient-to-br"], [class*="bg-linear-to-br"]):not([class*="absolute"]) {
  width: 3rem !important;
  height: 3rem !important;
  border-radius: 0.75rem !important;
}
```

Extend the existing primary SVG rule declarations to:

```css
  width: 1.5rem !important;
  height: 1.5rem !important;
  stroke-width: 1.5 !important;
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run:

```powershell
node node_modules/vitest/vitest.mjs run resources/js/__tests__/metricCardSizing.test.ts --reporter=dot
```

Expected: `1 passed` test file and `2 passed` tests.

- [ ] **Step 5: Run source diff hygiene**

Run:

```powershell
git diff --check -- resources/css/app.css resources/js/__tests__/metricCardSizing.test.ts
```

Expected: exit code 0 with no output.

- [ ] **Step 6: Commit the source fix and regression test**

```powershell
git add -- resources/css/app.css resources/js/__tests__/metricCardSizing.test.ts
git commit -m "style: match metric icons to dashboard sizing"
```

### Task 2: Verify and regenerate frontend build

**Files:**
- Modify: `public/build/manifest.json`
- Modify: generated hashed files under `public/build/assets/`

**Interfaces:**
- Consumes: verified source CSS from Task 1
- Produces: deployable Vite assets containing the 48px tile and 24px icon rules

- [ ] **Step 1: Run the complete frontend test suite**

Run:

```powershell
node node_modules/vitest/vitest.mjs run --reporter=dot
```

Expected: all frontend test files pass; existing unrelated console warnings may remain but no test failures are allowed.

- [ ] **Step 2: Generate a fresh Vite build**

Run:

```powershell
node node_modules/vite/bin/vite.js build
```

Expected: exit code 0 and Vite reports a successful production build.

- [ ] **Step 3: Validate generated CSS and manifest assets**

Run:

```powershell
rg -l 'width:3rem.*height:3rem.*border-radius:.75rem|width:1.5rem.*height:1.5rem.*stroke-width:1.5' public/build -g '*.css'
```

Expected: at least one generated CSS asset path.

Run:

```powershell
$manifest = Get-Content -LiteralPath 'public/build/manifest.json' -Raw | ConvertFrom-Json
$entries = @($manifest.PSObject.Properties)
$missing = @()
foreach ($entry in $entries) {
  $file = $entry.Value.file
  if ($file -and -not (Test-Path -LiteralPath (Join-Path 'public/build' $file))) { $missing += $file }
}
"manifest_entries=$($entries.Count) missing_assets=$($missing.Count)"
```

Expected: `missing_assets=0`.

- [ ] **Step 4: Run final diff hygiene**

Run:

```powershell
git diff --check
```

Expected: exit code 0 with no output.

- [ ] **Step 5: Commit the fresh build**

```powershell
git add -- public/build
git commit -m "chore: refresh frontend build"
```

- [ ] **Step 6: Confirm unrelated changes remain unstaged**

Run:

```powershell
git status --short --branch
```

Expected: only the user's pre-existing controller, lockfile, logistics test, and untracked workspace files remain outside the completed commits.
