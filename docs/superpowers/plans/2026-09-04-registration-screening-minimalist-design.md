# Registration ID Screening Overlay Minimalist Design Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the over-designed registration ID-screening loading dialog with a compact, monochrome overlay that matches the customer-facing registration page without changing validation behavior.

**Architecture:** Keep `DocumentScreeningOverlay` as the existing presentation-only component in `Register.tsx`. Update only its JSX/classes and the focused regression assertions; leave the state derivation, upload lifecycle, OCR calls, duplicate checks, and submission flow untouched. Regenerate `public/build` after the source and test changes.

**Tech Stack:** Laravel 12, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, Vitest, Vite 7.

## Global Constraints

- Use `DESIGN.md`'s neutral customer palette: white/canvas, ink `#111111`, soft cloud `#f5f5f5`, hairlines, and muted text.
- Do not add dependencies or change registration data flow.
- Preserve `role="dialog"`, `aria-modal="true"`, the labelled heading, live status semantics, and reduced-motion handling.
- Preserve the existing `loading` and `recognizing` visibility contract.
- Keep the overlay responsive at 375px, 768px, 1024px, and desktop widths with no horizontal scrolling.
- Do not stage or modify unrelated local worktree files.

---

### Task 1: Record and commit the approved design

**Files:**
- Create: `docs/superpowers/specs/2026-09-04-registration-screening-minimalist-design.md`
- Create: `docs/superpowers/plans/2026-09-04-registration-screening-minimalist-design.md`

**Interfaces:**
- Produces the visual and behavior contract used by the overlay implementation and test review.

- [x] **Step 1: Write the approved design and plan**

  The design specifies a flat white dialog, neutral scrim, simple two-step progress list, no decorative navy block, and no changes to screening logic.

- [x] **Step 2: Commit only the design and plan files**

  Run:

  ```powershell
  git add docs/superpowers/specs/2026-09-04-registration-screening-minimalist-design.md docs/superpowers/plans/2026-09-04-registration-screening-minimalist-design.md
  git commit -m "docs: define minimalist registration screening overlay"
  ```

  Expected: one documentation-only commit; unrelated worktree files remain unstaged.

---

### Task 2: Lock the minimalist overlay contract in tests

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx` around the existing `registration-screening-overlay` test.

**Interfaces:**
- Consumes the existing `Register` component and the current fingerprint/OCR release helpers.
- Produces regression coverage for the overlay's accessible structure and neutral visual contract.

- [x] **Step 1: Add assertions for the presentation contract**

  Keep the existing assertions for `role="dialog"`, `aria-modal="true"`, dynamic title, status steps, disabled validation button, and disappearance after OCR. Add assertions that the overlay contains `ID VERIFICATION`, `aria-live="polite"`, and the new step labels, and does not contain the removed `Secure ID check` or old `front-screening-loader` markers.

- [x] **Step 2: Run the focused test before implementation**

  Run:

  ```powershell
  .\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx
  ```

  Expected: existing behavior assertions pass; any new presentation assertion should fail until Task 3 changes the component.

---

### Task 3: Implement the neutral, compact overlay

**Files:**
- Modify: `resources/js/Pages/UserSide/Auth/Register.tsx:190-270` (`DocumentScreeningOverlay` only).

**Interfaces:**
- Consumes the unchanged `side?: RegistrationDocumentSide` and `status?: RegistrationOcrStatus` props.
- Produces the same overlay mount/unmount behavior and accessible status semantics with updated presentation only.

- [x] **Step 1: Replace the current decorative markup**

  Keep this visibility guard and dynamic copy unchanged:

  ```tsx
  if (!side || (status !== 'loading' && status !== 'recognizing')) return null;
  const isRecognizing = status === 'recognizing';
  const title = isRecognizing ? 'Validating ID' : 'Preparing image';
  ```

  Replace only the returned presentation with the equivalent compact, state-aware markup. The progress list must remain dynamic: `loading` keeps the first step active and the second pending; `recognizing` marks the first step complete and the second active.

  ```tsx
  <div className="fixed inset-0 z-[120] flex min-h-dvh items-center justify-center overflow-y-auto bg-black/40 px-4 py-6 sm:px-6">
    <div
      className="w-full max-w-[400px] rounded-2xl border border-gray-200 bg-white p-6 font-outfit sm:p-8"
      role="dialog"
      aria-modal="true"
      aria-labelledby="registration-screening-title"
      data-testid="registration-screening-overlay"
    >
      <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">ID verification</p>
      <h2 id="registration-screening-title" className="mt-3 text-[26px] font-semibold leading-tight tracking-tight text-gray-900">
        {title}
      </h2>
      <p className="mt-2 text-sm leading-6 text-gray-600">{description}</p>

      <div className="relative mt-7">
        <span className="absolute left-3 top-6 h-8 w-px bg-gray-200" aria-hidden="true" />
        <ol aria-label="Validation progress" className="relative space-y-5">
          <li aria-current={isRecognizing ? undefined : 'step'}>
            <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-[11px] text-white" aria-hidden="true">
              {isRecognizing ? <span aria-hidden="true">&#10003;</span> : <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-400 border-t-white motion-reduce:animate-none" />}
            </span>
            <span>Image uploaded</span>
            <span>{isRecognizing ? 'Complete' : 'In progress'}</span>
          </li>
          <li aria-current={isRecognizing ? 'step' : undefined}>
            <span aria-hidden="true">{isRecognizing ? 'spinner' : '02'}</span>
            <span>Secure validation</span>
            {isRecognizing && <span>In progress</span>}
          </li>
        </ol>
      </div>

      <p className="mt-7 border-t border-gray-100 pt-4 text-xs leading-5 text-gray-500">
        Your image is processed securely and is only used to screen the selected document type.
      </p>
    </div>
  </div>
  ```

  The source implementation uses valid `ol`/`li` structure for the progress list. Use the existing safe SVG/check approach if the codebase lint/type rules reject a text check, and keep `motion-reduce:animate-none`. Do not alter state, effects, upload handlers, or the page-level render location.

- [x] **Step 2: Run the focused tests**

  Run the Register test and expect all existing screening lifecycle assertions to pass.

---

### Task 4: Verify the full change and refresh generated assets

**Files:**
- Modify: `public/build/` generated output only.

**Interfaces:**
- Consumes the final `Register.tsx` and existing Vite configuration.
- Produces a fresh manifest and hashed assets for the updated overlay.

- [x] **Step 1: Run focused and full frontend tests**

  ```powershell
  .\node_modules\.bin\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts
  .\node_modules\.bin\vitest.cmd run
  ```

  Expected: focused tests pass and the full suite reports all test files/tests passed.

- [x] **Step 2: Generate the production build**

  ```powershell
  .\node_modules\.bin\vite.cmd build
  ```

  Expected: Vite completes successfully and rewrites `public/build/manifest.json` plus current hashed assets.

- [x] **Step 3: Run diff hygiene and inspect scope**

  ```powershell
  git diff --check
  git diff --name-status origin/solespace-b...HEAD
  git diff --stat origin/solespace-b...HEAD
  ```

  Expected: no whitespace errors; only the two docs, Register component/test, and intended generated build files are staged for this change. Unrelated local work remains unstaged.

- [x] **Step 4: Commit the implementation and generated build**

  ```powershell
  git add resources/js/Pages/UserSide/Auth/Register.tsx resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx public/build
  git commit -m "style: simplify registration screening overlay"
  ```

  Expected: one implementation commit containing source, regression coverage, and the fresh build; no unrelated files included.
