# Customer Registration ID Validation Loading Design

**Status:** Approved

## Goal

Replace the plain "Checking image..." text in customer registration Step 3 with a professional, stage-aware loading treatment that reflects the existing ID screening lifecycle without changing validation or submission behavior.

## Scope

- Customer registration page: resources/js/Pages/UserSide/Auth/Register.tsx
- Existing ID screening states: loading and recognizing
- Front, back, and passport biodata upload cards

The upload handlers, OCR/fingerprint services, screening decision, error modals, backend payload, and submission blocking rules remain unchanged.

## Design

Use one small presentational DocumentScreeningLoader used by each upload card. It renders only for an active side whose status is loading or recognizing:

- loading: show a spinner with "Preparing image" and explain that the image is being prepared for secure validation.
- recognizing: show the same spinner with "Validating ID" and explain that the side is being compared with the selected document type.
- ready, rejected, and error: render no loader; the existing Ready indicator, Swal error, and validation behavior remain authoritative.

The loader uses the existing monochrome registration styling, a stable card-sized layout, role="status", and aria-live="polite". The old global "Checking ... image..." copy is removed while a side is actively screening so customers see one clear status per image. The Create Account button uses "Validating ID..." while screening is active. The remaining document guidance is shown as a neutral "Note" inside the existing "Why we ask for a valid ID" panel instead of a separate blue status panel.

## Accessibility and resilience

- Keep the existing visible upload labels and file controls.
- Keep screen-reader status announcements polite and descriptive.
- Keep the input focus behavior and existing error recovery unchanged.
- Use transform-safe animate-spin feedback with motion-reduce:animate-none.
- Do not add a dependency or change the asynchronous request lifecycle.

## Acceptance criteria

1. Uploading an ID shows a spinner-based loader instead of plain "Checking image...".
2. The loader copy changes between the existing loading and recognizing stages.
3. The loader disappears when the side becomes ready, rejected, or errored.
4. Existing ready indicators, Swal errors, submission blocking, and payload behavior still pass their tests.
5. The document guidance appears as a readable neutral note inside the "Why we ask for a valid ID" panel with no blue background panel.
6. The UI remains keyboard- and screen-reader-friendly and introduces no new package or backend change.
