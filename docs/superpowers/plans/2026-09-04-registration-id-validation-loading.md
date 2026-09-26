# Customer Registration ID Validation Loading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Replace the customer registration ID screening placeholder with an accessible, stage-aware loading UI backed by the existing screening states.

**Architecture:** Keep the current Register.tsx upload and screening flow intact. Add one presentational full-screen overlay that maps the existing loading and recognizing side statuses to professional UI copy, then render it once at the registration page level. Suppress the duplicate global "Checking image" message while the overlay is visible, and place the remaining document guidance inside the existing "Why we ask for a valid ID" panel as a neutral note.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, Testing Library.

## Global Constraints

- Do not change the OCR, fingerprint, validation, submission, or backend payload flow.
- Use the existing monochrome registration classes and no new dependency.
- Preserve visible labels, file controls, focus behavior, Swal error feedback, and submission blocking.
- The blocking loading state must use role="dialog", aria-modal="true", a nested role="status" with aria-live="polite", and motion-reduce:animate-none.
- Preserve unrelated working-tree changes and stage only the files named in this plan.

---

### Task 1: Add the failing UI regression test

**Files:**

- Modify: resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx in the customer registration document screening UI suite

**Interfaces:**

- Consumes: the existing mocks.fingerprintRegistrationImage, mocks.readRegistrationId, goToIdStep, and selectFile helpers.
- Produces: a regression contract proving both actual screening stages render the blocking overlay and the old plain copy/inline loader are absent.

- [x] **Step 1: Write the failing test**

Add this test after the existing upload-card tests:

~~~tsx
it('shows a full-screen stage-aware loading experience while an ID side is being screened', async () => {
  let releaseFingerprint: ((value: { exact: string; perceptual: null }) => void) | undefined;
  let releaseOcr: ((value: typeof driverFrontOcr) => void) | undefined;

  mocks.fingerprintRegistrationImage.mockImplementationOnce(() => new Promise(resolve => {
    releaseFingerprint = resolve;
  }));
  mocks.readRegistrationId.mockImplementationOnce(async (_file: File, onStage?: (stage: string) => void) => {
    onStage?.('recognizing');
    return new Promise(resolve => {
      releaseOcr = resolve;
    });
  });

  render(<Register />);
  await goToIdStep();
  fireEvent.change(screen.getByLabelText('ID Type'), { target: { value: 'drivers_license' } });
  await selectFile('ID file', 'national-front.png');

  const screeningOverlay = screen.getByTestId('registration-screening-overlay');
  expect(screeningOverlay).toHaveAttribute('role', 'dialog');
  expect(screeningOverlay).toHaveAttribute('aria-modal', 'true');
  expect(screeningOverlay).toHaveTextContent(/Preparing image/i);
  expect(screeningOverlay).toHaveTextContent(/Image uploaded/i);
  expect(screeningOverlay).toHaveTextContent(/Secure validation/i);
  expect(screen.queryByTestId('front-screening-loader')).not.toBeInTheDocument();
  expect(screen.queryByText('Checking image...', { exact: true })).not.toBeInTheDocument();

  releaseFingerprint?.({ exact: 'national-front.png', perceptual: null });
  await waitFor(() => expect(screen.getByTestId('registration-screening-overlay')).toHaveTextContent(/Validating ID/i));

  releaseOcr?.(driverFrontOcr);
  await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
  expect(screen.queryByTestId('registration-screening-overlay')).not.toBeInTheDocument();
});
~~~

- [x] **Step 2: Run the focused test and verify it fails for the missing UI**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx -t "full-screen stage-aware loading experience"
~~~

Expected: FAIL because the full-screen overlay does not exist and the current implementation still renders the inline loader card.

### Task 2: Implement the full-screen stage-aware overlay

**Files:**

- Modify: resources/js/Pages/UserSide/Auth/Register.tsx beside the existing document-screening modal helper
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the active screening status derivation and page-level render
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the documentScreeningMessage calculation
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the front/biodata and back upload cards
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the Create Account button label

**Interfaces:**

- Consumes: RegistrationDocumentSide, RegistrationOcrStatus, and the existing sideStatuses values.
- Produces: DocumentScreeningOverlay with no effect on screening or submission state.

- [x] **Step 1: Add the presentational overlay**

Use one fixed, responsive component beside the existing document-screening modal helper. It should render only while an active side is `loading` or `recognizing`, use a monochrome panel, show the current stage, and include `Image uploaded` plus `Secure validation` progress labels. Use `role="dialog"`, `aria-modal="true"`, a labelled heading, a nested polite status announcement, and `motion-reduce:animate-none` on animated indicators. Do not add a percentage or a new dependency.

The overlay should be mounted once near `Navigation` and use the first active slot from `requiredSlots`; this keeps front/back/passport behavior consistent and prevents duplicate loaders.

- [x] **Step 2: Remove the duplicate active-screening panel**

Keep the existing checkingSlot calculation, but return an empty message while it is active:

~~~tsx
if (checkingSlot) return '';
~~~

Remove the separate blue `documentScreeningMessage` panel from the upload section. Keep the existing duplicate, error, rejected, ready, and upload guidance branches unchanged.

- [x] **Step 3: Render remaining guidance as a neutral note**

Render `documentScreeningMessage` inside the existing `Why we ask for a valid ID` panel with a small left rule and no blue background. Keep `role="status"` and `aria-live="polite"` so readiness guidance remains announced when it changes.

- [x] **Step 4: Render the overlay once and remove inline loaders**

Render the overlay at page level from the active slot/status:

~~~tsx
<DocumentScreeningOverlay side={screeningSlot} status={screeningStatus} />
~~~

Remove the old per-card `DocumentScreeningLoader` renders so the form is dimmed and blocked by one clear status screen.

- [x] **Step 5: Align the submit button text**

Use Validating ID... for the existing disabled active-screening branch:

~~~tsx
{isLoading ? 'Creating account...' : isCheckingDocument ? 'Validating ID...' : 'Create account'}
~~~

### Task 3: Verify the focused and full behavior

**Files:**

- Test: resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx
- Test: resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts
- Test: resources/js/Pages/UserSide/Auth/registrationDocumentScreening.test.ts

- [x] **Step 1: Run the focused registration tests**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts resources/js/Pages/UserSide/Auth/registrationDocumentScreening.test.ts
~~~

Expected: all registration tests pass, including the new loader test.

- [x] **Step 2: Run the full frontend suite**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run
~~~

Expected: zero failed test files and zero failed tests.

- [x] **Step 3: Build the frontend**

Run:

~~~powershell
.\\node_modules\\.bin\\vite.cmd build
~~~

Expected: Vite exits with code 0 and updates public/build.

- [x] **Step 4: Check diff hygiene**

Run:

~~~powershell
git diff --check
~~~

Expected: no output and exit code 0.

### Task 4: Review and commit the scoped change

**Files:**

- Stage only: resources/js/Pages/UserSide/Auth/Register.tsx
- Stage only: resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx
- Stage only: resources/js/Pages/UserSide/Profile/ShopProfile.tsx
- Stage only: resources/js/components/common/__tests__/CustomerFooter.reveal.test.ts
- Stage only: resources/js/Pages/UserSide/Shared/__tests__/Navigation.contract.test.ts
- Stage only: docs/superpowers/specs/2026-09-04-registration-id-validation-loading-design.md
- Stage only: docs/superpowers/plans/2026-09-04-registration-id-validation-loading.md
- Stage only: public/build

- [x] **Step 1: Inspect the source diff and confirm no validation flow changed**

Run:

~~~powershell
git diff -- resources/js/Pages/UserSide/Auth/Register.tsx resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx
~~~

Confirm the diff only adds the loader presentation, status copy, and regression coverage.

- [ ] **Step 2: Stage explicit paths and commit**

Run:

~~~powershell
git add resources/js/Pages/UserSide/Auth/Register.tsx resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx docs/superpowers/specs/2026-09-04-registration-id-validation-loading-design.md docs/superpowers/plans/2026-09-04-registration-id-validation-loading.md public/build
git commit -m "fix: improve ID validation loading feedback"
~~~

Expected: one scoped commit with no unrelated local files staged.
