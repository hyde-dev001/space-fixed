# Repair Shop Information and Rating Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the separate Shop Information and Customer Rating cards on the repair shop details page with one cohesive, responsive table-style container while preserving every existing interaction and data flow.

**Architecture:** Keep the existing RepairShow component, state, handlers, API calls, and conditional content in place. Replace only the Info Grid presentation with one semantic section: a shared Shop Information header, a responsive two-column body on large screens, and a stacked body with dividers on smaller screens.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Modify only resources/js/Pages/UserSide/Repairs/repairShow.tsx and the focused frontend test, plus generated public/build output.
- Do not change controllers, routes, review endpoints, booking payloads, state variables, event handlers, or shared navigation.
- Preserve the Message link, authenticated Report menu, hours status, payment policy copy, rating statistics, star rendering, and no-reviews empty state.
- Use a visual table-style layout rather than a literal HTML <table> so links and interactive controls remain responsive and accessible.
- Use black, white, and gray surfaces for this section; remove the blue payment-policy block background and yellow rating-card background.
- Rebase onto origin/solespace-b before the final build and push, preserve unrelated local changes, stage explicit paths, and include a fresh public/build.
- Push only to feature/monochrome-erp-theme-clean; the user will create the PR.

---

### Task 1: Add the repair-shop layout regression contract

**Files:**
- Create: resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts

**Interfaces:**
- Consumes: the current source contract in resources/js/Pages/UserSide/Repairs/repairShow.tsx.
- Produces: a focused test that requires one responsive container and rejects the old separate color treatments.

- [ ] **Step 1: Write the failing test**

Create the test with these exact assertions:

```ts
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const repairShowSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('Repair shop information and rating layout', () => {
  it('keeps both panels inside one responsive neutral container', () => {
    expect(repairShowSource).toContain('data-testid="repair-shop-info-rating"');
    expect(repairShowSource).toContain('aria-labelledby="repair-shop-information-heading"');
    expect(repairShowSource).toContain('id="repair-shop-information-heading"');
    expect(repairShowSource).toContain('grid grid-cols-1 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.85fr)]');
    expect(repairShowSource).toContain('border-t border-gray-200 lg:border-l lg:border-t-0');
    expect(repairShowSource).toContain('Shop Information');
    expect(repairShowSource).toContain('Customer Rating');
    expect(repairShowSource).toContain('Message');
    expect(repairShowSource).toContain('No reviews yet');
    expect(repairShowSource).not.toContain('from-yellow-50 to-white');
    expect(repairShowSource).not.toContain('border-blue-100 bg-blue-50/70');
    expect(repairShowSource).not.toContain('bg-yellow-400');
  });
});
```

- [ ] **Step 2: Run the focused test and verify the expected failure**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts
```

Expected result: the test fails because the current source has separate cards and still contains the old yellow/blue classes.

- [ ] **Step 3: Commit the test**

```powershell
git add -- resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts
git commit -m "test: define repair shop info rating layout"
```

### Task 2: Implement the unified responsive container

**Files:**
- Modify: resources/js/Pages/UserSide/Repairs/repairShow.tsx around the Info Grid block at lines 591-775.

**Interfaces:**
- Consumes: the existing shop, shopStatus, paymentPolicyLabel, paymentPolicyHint, reviewStats, renderStars, showMoreActions, and existing handlers.
- Produces: the same rendered information and actions inside data-testid="repair-shop-info-rating".

- [ ] **Step 1: Replace the Info Grid wrapper and move the existing Shop Information header into it**

Replace the current outer Info Grid opening and Shop Information card header with this shared section/header. Keep the existing Message link and authenticated more-actions menu JSX and handlers in the action container:

```tsx
<section
  className="mb-8 overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm xl:mb-10"
  aria-labelledby="repair-shop-information-heading"
  data-testid="repair-shop-info-rating"
>
  <div className="flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5 xl:p-8">
    <div className="flex items-center gap-3">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-black shadow-md xl:h-12 xl:w-12">
        <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 xl:w-6 xl:h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
          <polyline points="9 22 9 12 15 12 15 22" />
        </svg>
      </div>
      <h2 id="repair-shop-information-heading" className="text-xl font-bold text-black xl:text-2xl">Shop Information</h2>
    </div>
    <div className="flex w-full items-center gap-2 sm:w-auto">
      Keep the existing Message link and authenticated Report menu JSX, including href, ref, aria attributes, and event handlers.
    </div>
  </div>

  <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.85fr)]">
    <div className="p-4 sm:p-5 xl:p-8">
      Keep the existing Location, payment policy, Hours, Phone, and Email rows in this column.
    </div>

    <div className="border-t border-gray-200 bg-gray-50/60 p-4 sm:p-5 lg:border-l lg:border-t-0 xl:p-8">
      Keep the existing Customer Rating content and review empty state in this column.
    </div>
  </div>
</section>
```

Use the existing SVG and JSX bodies in their new locations; do not add placeholder comments to the production file. Remove the old grid xl:grid-cols-2 wrapper and the two independent card wrappers.

- [ ] **Step 2: Normalize only this section’s block surfaces**

Apply these exact presentation changes while leaving values and handlers untouched:

```tsx
// Payment policy row
className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-gray-50 p-3.5 xl:p-4"
// Payment policy icon and labels
className="w-5 h-5 text-black mt-0.5 shrink-0"
className="font-bold text-black mb-1"
className="text-black font-semibold leading-6"
className="text-gray-600 text-xs sm:text-sm mt-1 leading-5"

// Customer rating column
className="border-t border-gray-200 bg-gray-50/60 p-4 sm:p-5 lg:border-l lg:border-t-0 xl:p-8"
// Customer rating icon
className="flex h-10 w-10 items-center justify-center rounded-xl bg-black shadow-md xl:h-12 xl:w-12"
```

Remove the old from-yellow-50 to-white, border-yellow-100, bg-yellow-400, border-blue-100, and bg-blue-50/70 classes from this section only. Keep the existing yellow star SVG/text returned by renderStars because it communicates rating value and is also used by the review workflow.

- [ ] **Step 3: Run the focused test and verify it passes**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts
```

Expected result: 1 test file passes with 1 test passing.

- [ ] **Step 4: Commit the implementation**

```powershell
git add -- resources/js/Pages/UserSide/Repairs/repairShow.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts
git commit -m "fix: unify repair shop info and rating layout"
```

### Task 3: Run the quality gates

**Files:**
- Verify: resources/js/Pages/UserSide/Repairs/repairShow.tsx
- Verify: resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts

**Interfaces:**
- Consumes: the committed unified layout.
- Produces: fresh evidence that the focused presentation contract and existing frontend behavior remain green.

- [ ] **Step 1: Check source diff hygiene**

Run:

```powershell
git diff --check HEAD~1..HEAD
```

Expected result: no output and exit code 0.

- [ ] **Step 2: Run the full frontend test suite**

Run:

```powershell
.\node_modules\.bin\vitest.cmd run
```

Expected result: all frontend test files and tests pass.

- [ ] **Step 3: Review the final source contract**

Run:

```powershell
rg -n -C 8 "repair-shop-info-rating|repair-shop-information-heading|Shop Information|Customer Rating|from-yellow-50|bg-yellow-400|bg-blue-50/70" resources/js/Pages/UserSide/Repairs/repairShow.tsx
```

Expected result: the unified section markers and both headings are present; the old yellow/blue block classes are absent from the Info section.

### Task 4: Rebase, build, stage generated assets, and push

**Files:**
- Include: resources/js/Pages/UserSide/Repairs/repairShow.tsx
- Include: resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts
- Include: fresh public/build
- Preserve: all unrelated working-tree files listed by git status --short

**Interfaces:**
- Consumes: the tested implementation and current feature branch.
- Produces: a pushed branch with source, regression test, and fresh production assets ready for the user’s PR.

- [ ] **Step 1: Fetch and rebase before the build**

Run:

```powershell
git fetch origin --prune
git rebase --autostash origin/solespace-b
```

Expected result: the feature branch is based on the latest origin/solespace-b, and unrelated local changes are restored.

- [ ] **Step 2: Temporarily isolate unrelated HR frontend edits before compiling**

Run only if those files are still modified:

```powershell
git stash push -m "codex-temp-unrelated-frontend-build-repair-shop-layout" -- resources/js/Pages/ERP/HR/LeaveApprovals.tsx resources/js/Pages/ERP/HR/OvertimeApprovals.tsx
```

After the build, restore immediately with:

```powershell
git stash pop
```

- [ ] **Step 3: Generate a fresh production build**

Run:

```powershell
.\node_modules\.bin\vite.cmd build
```

Expected result: Vite exits with code 0 and reports transformed modules plus generated files under public/build.

- [ ] **Step 4: Stage only the intended files**

Run:

```powershell
git add -- resources/js/Pages/UserSide/Repairs/repairShow.tsx resources/js/Pages/UserSide/Repairs/__tests__/repairShow.info-layout.test.ts
git add -f -- public/build
git diff --cached --check
```

Expected result: the staged non-build paths are exactly the repair-show source and focused test; the staged diff check is clean.

- [ ] **Step 5: Commit the source, test, and fresh build**

```powershell
git commit -m "fix: unify repair shop info and rating layout"
```

- [ ] **Step 6: Push the authorized feature branch**

```powershell
git push --progress -u origin feature/monochrome-erp-theme-clean
```

- [ ] **Step 7: Confirm delivery and preserved unrelated work**

Run:

```powershell
git status --short --branch
git rev-parse HEAD
git rev-parse origin/feature/monochrome-erp-theme-clean
```

Expected result: local and remote commit IDs match, and only the user’s unrelated existing changes remain in the working tree.
