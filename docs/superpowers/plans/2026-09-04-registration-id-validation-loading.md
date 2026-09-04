# Customer Registration ID Validation Loading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Replace the customer registration ID screening placeholder with an accessible, stage-aware loading UI backed by the existing screening states.

**Architecture:** Keep the current Register.tsx upload and screening flow intact. Add one small presentational loader that maps the existing loading and recognizing side statuses to professional UI copy, then render it inside the relevant upload card. Suppress the duplicate global "Checking image" message while a side loader is visible, and place the remaining document guidance inside the existing "Why we ask for a valid ID" panel as a neutral note.

**Tech Stack:** React 18, TypeScript 5.7, Inertia 2, Tailwind CSS 4, Vitest, Testing Library.

## Global Constraints

- Do not change the OCR, fingerprint, validation, submission, or backend payload flow.
- Use the existing monochrome registration classes and no new dependency.
- Preserve visible labels, file controls, focus behavior, Swal error feedback, and submission blocking.
- The loading state must use role="status", aria-live="polite", and motion-reduce:animate-none.
- Preserve unrelated working-tree changes and stage only the files named in this plan.

---

### Task 1: Add the failing UI regression test

**Files:**

- Modify: resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx in the customer registration document screening UI suite

**Interfaces:**

- Consumes: the existing mocks.fingerprintRegistrationImage, mocks.readRegistrationId, goToIdStep, and selectFile helpers.
- Produces: a regression contract proving both actual screening stages render the new loader and the old plain copy is absent.

- [ ] **Step 1: Write the failing test**

Add this test after the existing upload-card tests:

~~~tsx
it('shows stage-aware loading feedback while an ID side is being screened', async () => {
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

  expect(screen.getByTestId('front-screening-loader')).toHaveTextContent(/Preparing image/i);
  expect(screen.queryByText('Checking image...', { exact: true })).not.toBeInTheDocument();

  releaseFingerprint?.({ exact: 'national-front.png', perceptual: null });
  await waitFor(() => expect(screen.getByTestId('front-screening-loader')).toHaveTextContent(/Validating ID/i));

  releaseOcr?.(driverFrontOcr);
  await waitFor(() => expect(screen.getByLabelText('Front image ready')).toBeInTheDocument());
  expect(screen.queryByTestId('front-screening-loader')).not.toBeInTheDocument();
});
~~~

- [ ] **Step 2: Run the focused test and verify it fails for the missing UI**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx -t "stage-aware loading feedback"
~~~

Expected: FAIL because front-screening-loader does not exist and the current implementation still renders plain Checking image... text.

### Task 2: Implement the minimal stage-aware loader

**Files:**

- Modify: resources/js/Pages/UserSide/Auth/Register.tsx beside the existing document-screening modal helper
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the documentScreeningMessage calculation
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the front/biodata and back upload cards
- Modify: resources/js/Pages/UserSide/Auth/Register.tsx in the Create Account button label

**Interfaces:**

- Consumes: RegistrationDocumentSide, RegistrationOcrStatus, and the existing sideStatuses values.
- Produces: DocumentScreeningLoader with no effect on screening or submission state.

- [ ] **Step 1: Add the presentational loader**

Use this component beside the existing document-screening modal helper:

~~~tsx
const DocumentScreeningLoader = ({
  side,
  status,
}: {
  side: RegistrationDocumentSide;
  status?: RegistrationOcrStatus;
}) => {
  if (status !== 'loading' && status !== 'recognizing') return null;

  const sideLabel = side === 'biodata' ? 'passport biodata page' : side + ' image';
  const isRecognizing = status === 'recognizing';
  const title = isRecognizing ? 'Validating ID' : 'Preparing image';
  const description = isRecognizing
    ? 'Securely comparing the ' + sideLabel + ' with your selected ID type.'
    : 'Preparing the ' + sideLabel + ' for secure validation.';

  return (
    <div
      className="mt-3 flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900"
      role="status"
      aria-live="polite"
      aria-label={title + '. ' + description}
      data-testid={side + '-screening-loader'}
    >
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
        <span className="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-gray-900 motion-reduce:animate-none dark:border-gray-600 dark:border-t-white" />
      </span>
      <span className="min-w-0">
        <span className="block text-[11px] font-semibold uppercase tracking-[0.08em] text-gray-800 dark:text-gray-100">{title}</span>
        <span className="mt-0.5 block text-[11px] leading-4 text-gray-500 dark:text-gray-400">{description}</span>
      </span>
    </div>
  );
};
~~~

- [ ] **Step 2: Remove the duplicate active-screening panel**

Keep the existing checkingSlot calculation, but return an empty message while it is active:

~~~tsx
if (checkingSlot) return '';
~~~

Remove the separate blue `documentScreeningMessage` panel from the upload section. Keep the existing duplicate, error, rejected, ready, and upload guidance branches unchanged.

- [ ] **Step 3: Render remaining guidance as a neutral note**

Render `documentScreeningMessage` inside the existing `Why we ask for a valid ID` panel with a small left rule and no blue background. Keep `role="status"` and `aria-live="polite"` so readiness guidance remains announced when it changes.

- [ ] **Step 4: Render the loader for each active side**

Replace each plain Checking image... conditional with the matching component:

~~~tsx
<DocumentScreeningLoader
  side={isPassport ? 'biodata' : 'front'}
  status={sideStatuses[isPassport ? 'biodata' : 'front']}
/>
~~~

~~~tsx
<DocumentScreeningLoader side="back" status={sideStatuses.back} />
~~~

- [ ] **Step 5: Align the submit button text**

Use Validating ID... for the existing disabled active-screening branch:

~~~tsx
{isLoading ? 'Creating account...' : isCheckingDocument ? 'Validating ID...' : 'Create account'}
~~~

### Task 3: Verify the focused and full behavior

**Files:**

- Test: resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx
- Test: resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts
- Test: resources/js/Pages/UserSide/Auth/registrationDocumentScreening.test.ts

- [ ] **Step 1: Run the focused registration tests**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx resources/js/Pages/UserSide/Auth/__tests__/Register.password-guidance.test.ts resources/js/Pages/UserSide/Auth/registrationDocumentScreening.test.ts
~~~

Expected: all registration tests pass, including the new loader test.

- [ ] **Step 2: Run the full frontend suite**

Run:

~~~powershell
.\\node_modules\\.bin\\vitest.cmd run
~~~

Expected: zero failed test files and zero failed tests.

- [ ] **Step 3: Build the frontend**

Run:

~~~powershell
.\\node_modules\\.bin\\vite.cmd build
~~~

Expected: Vite exits with code 0 and updates public/build.

- [ ] **Step 4: Check diff hygiene**

Run:

~~~powershell
git diff --check
~~~

Expected: no output and exit code 0.

### Task 4: Review and commit the scoped change

**Files:**

- Stage only: resources/js/Pages/UserSide/Auth/Register.tsx
- Stage only: resources/js/Pages/UserSide/Auth/__tests__/Register.test.tsx
- Stage only: docs/superpowers/specs/2026-09-04-registration-id-validation-loading-design.md
- Stage only: docs/superpowers/plans/2026-09-04-registration-id-validation-loading.md
- Stage only: public/build

- [ ] **Step 1: Inspect the source diff and confirm no validation flow changed**

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
