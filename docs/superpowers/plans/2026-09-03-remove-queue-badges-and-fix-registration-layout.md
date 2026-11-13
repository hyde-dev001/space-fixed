# Remove Queue Badges and Fix Registration Document Layout Implementation Plan

> **For agentic workers:** Execute this plan sequentially in the current feature worktree. Preserve unrelated working-tree changes.

**Goal:** Remove the decorative `Queue` badge from every identity-review metric card and prevent the Shop Owner Registration document cards from overlapping their metadata fields.

**Architecture:** Keep the identity-review metrics, counts, filters, and actions unchanged; remove only the badge markup from `ReviewMetricCard`. Fix the shared Dropzone layout at its source by allowing the standard (non-compact) dropzone to use natural height, so grid rows include the metadata content that follows each upload area.

**Tech Stack:** React 18, TypeScript, Tailwind CSS 4, Vitest, Laravel/Vite asset build.

## Global Constraints

- Do not change backend endpoints, upload payloads, counts, filters, or unrelated working-tree files.
- Reuse the existing `DropzoneComponent` and current identity-review tests; add no dependencies.
- Preserve the compact dropzone behavior already covered by its regression test.
- Verify with targeted Vitest tests, the full frontend suite, a Vite build, and `git diff --check`.

---

### Task 1: Remove metric-card badges and make registration upload cards flow naturally

**Files:**
- Modify: `resources/js/Pages/superAdmin/IdentityVerificationReviews/Index.tsx` — remove the decorative `Queue` span from `ReviewMetricCard` only.
- Test: `resources/js/Pages/superAdmin/IdentityVerificationReviews/__tests__/Index.test.tsx` — assert metric cards do not render `Queue`.
- Modify: `resources/js/components/form/form-elements/DropZone.tsx` — remove unconditional `h-full` classes from the standard dropzone wrapper and root.
- Test: `resources/js/components/form/form-elements/__tests__/DropZone.test.tsx` — assert standard dropzones keep natural height as well as compact dropzones.

**Interfaces:**
- `ReviewMetricCard` continues to receive the same `ReviewMetric` props and renders the same icon, title, value, and description without the badge.
- `DropzoneComponent` keeps the same props and upload behavior; only its standard layout height changes from forced fill to content height.

- [x] **Step 1: Write the failing tests**

Add one identity-review assertion after rendering the metric page:

```tsx
expect(screen.queryAllByText('Queue')).toHaveLength(0);
```

Add one standard-dropzone test alongside the existing compact test:

```tsx
it('keeps the standard card height natural for following metadata fields', () => {
  render(<DropzoneComponent isUploaded fileName="permit.jpg" />);

  const dropzoneRoot = screen.getByTestId('dropzone-root');

  expect(dropzoneRoot).not.toHaveClass('h-full');
  expect(dropzoneRoot.parentElement).not.toHaveClass('h-full');
});
```

- [x] **Step 2: Run the focused tests and confirm the expected failures**

Run:

```text
.\node_modules\.bin\vitest.cmd run resources/js/Pages/superAdmin/IdentityVerificationReviews/__tests__/Index.test.tsx resources/js/components/form/form-elements/__tests__/DropZone.test.tsx
```

Expected: the new identity-review assertion finds six `Queue` badges and the standard-dropzone assertion finds `h-full`; existing tests remain otherwise runnable.

- [x] **Step 3: Implement the minimum fix**

In `ReviewMetricCard`, delete only the `<span>Queue</span>` badge. In `DropZone.tsx`, change the standard layout class expressions from:

```tsx
`${compact ? '' : 'h-full'} ...`
```

and:

```tsx
`h-full p-7 lg:p-10 ...`
```

to natural-height classes with no `h-full`, while leaving the compact branch and all upload handlers unchanged.

- [x] **Step 4: Run focused tests and verify the fix**

Run the same Vitest command from Step 2. Expected: all tests in both files pass, including zero `Queue` text and natural-height assertions.

- [x] **Step 5: Run quality gates**

Run:

```text
.\node_modules\.bin\vitest.cmd run
.\node_modules\.bin\vite.cmd build
git diff --check
```

Expected: the full frontend suite has zero failures, the production build exits with code 0, and `git diff --check` has no output.

- [x] **Step 6: Review the final diff**

Confirm only the identity metric badge, shared standard dropzone height classes, their focused tests, and generated `public/build` assets changed. Confirm the unrelated existing working-tree changes remain unstaged and untouched.
