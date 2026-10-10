# Student ID Manual Review

## Goal

Allow customers to register with a Student ID while keeping the document in the existing private identity-verification workflow. Student IDs require front and back images, never run OCR, and always remain pending authorized admin review.

## Behavior

- Registration offers `Student ID` as a document type.
- Both front and back images are required.
- Browser screening computes only the existing duplicate-image fingerprint; it does not invoke OCR.
- The backend treats Student ID as manual-review-only, regardless of client-supplied screening metadata.
- Existing private storage, admin review, authorization, and duplicate-side checks remain in use.
- Admin review identifies Student ID as manual review and shows the existing secure image previews.

## Non-goals

- No OCR or automatic identity approval for Student IDs.
- No new database column, storage visibility, or face verification system.

## Acceptance criteria

1. A customer can select Student ID and must provide both sides.
2. Student ID uploads complete without an OCR call and submit as `manual_review_required`.
3. Forged client metadata cannot turn a Student ID into automatic approval.
4. An authorized admin can recognize the document type and review both private images.
5. Existing identity-document tests and focused frontend/backend checks pass.
