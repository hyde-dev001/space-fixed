# Customer Registration ID Validation Loading Design

**Status:** Approved

## Goal

Replace the plain "Checking image..." text in customer registration Step 3 with a professional, stage-aware full-screen loading experience that reflects the existing ID screening lifecycle without changing validation or submission behavior.

## Scope

- Customer registration page: resources/js/Pages/UserSide/Auth/Register.tsx
- Existing ID screening states: loading and recognizing
- Front, back, and passport biodata upload cards

The upload handlers, OCR/fingerprint services, screening decision, error modals, backend payload, and submission blocking rules remain unchanged.

## Design

Use one presentational DocumentScreeningOverlay rendered once at the registration page level. It blocks the underlying form only for an active side whose status is loading or recognizing:

- loading: show a full-screen monochrome overlay with "Preparing image" and explain that the image is being prepared for secure validation.
- recognizing: keep the overlay visible with "Validating ID" and explain that the side is being compared with the selected document type.
- show a centered responsive panel with a clear spinner, the "Image uploaded" and "Secure validation" stages, and a short privacy reassurance. Do not show a fabricated percentage or progress estimate.
- ready, rejected, and error: render no overlay; the existing Ready indicator, Swal error, and validation behavior remain authoritative.

The overlay uses the existing monochrome registration styling, `role="dialog"`, `aria-modal="true"`, and a nested `role="status"` with `aria-live="polite"`. The old global "Checking ... image..." copy is removed while a side is actively screening so customers see one clear status. The Create Account button uses "Validating ID..." while screening is active. The remaining document guidance is shown as a neutral "Note" inside the existing "Why we ask for a valid ID" panel instead of a separate blue status panel.

## Accessibility and resilience

- Keep the existing visible upload labels and file controls.
- Keep the blocking dialog labelled and screen-reader status announcements polite and descriptive.
- Keep the input focus behavior and existing error recovery unchanged.
- Use transform-safe animate-spin feedback with `motion-reduce:animate-none`.
- Keep the overlay scroll-safe on short mobile viewports and prevent interaction with the form while it is visible.
- Do not add a dependency or change the asynchronous request lifecycle.

## Acceptance criteria

1. Uploading an ID shows a blocking full-screen validation screen instead of plain "Checking image..." or an inline loader card.
2. The loader copy changes between the existing loading and recognizing stages.
3. The screen includes the "Image uploaded" and "Secure validation" stages and disappears when the side becomes ready, rejected, or errored.
4. Existing ready indicators, Swal errors, submission blocking, and payload behavior still pass their tests.
5. The document guidance appears as a readable neutral note inside the "Why we ask for a valid ID" panel with no blue background panel.
6. The UI remains keyboard- and screen-reader-friendly and introduces no new package or backend change.
