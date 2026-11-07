# Repair Service Images Design

**Date:** 2026-09-20

**Status:** Approved visual direction; implementation pending written-spec review

## Goal

Allow repair staff to attach one optional service image when creating or editing a repair service, then show that image on the customer-side Individual Services cards in the existing SoleSpace monochrome design.

## Approved scope

- One optional main image per `RepairService` record.
- Add and edit flows in the ERP repair service manager.
- Customer-side display on `UserSide/Repairs/repairShow`.
- Existing package cards remain unchanged.
- Services without an image show a quiet neutral placeholder and keep the existing card content usable.
- No image gallery, image cropping editor, CDN integration, or unrelated service-page redesign.

The approved visual reference is [repair-service-image-upload-high-fidelity.html](../../mockups/repair-service-image-upload-high-fidelity.html).

## Architecture

Store the public-disk path directly on `repair_services` using a nullable `image_path` column. The existing repair service create/update endpoints will accept multipart form data, validate the image server-side, and return a normalized `image_url` in service payloads. The existing customer Inertia page will map the stored path to a public URL and pass it to the existing service card component.

The implementation reuses Laravel's existing `public` storage disk and the current tenant-scoped repair service controller. No new media table or dependency is needed for a single image.

## Data flow

1. A permitted repair staff member opens Add Service or Edit Service.
2. The form accepts one optional JPG, JPEG, PNG, or WEBP image up to 5 MB and shows a local preview before submission.
3. The frontend submits the existing service fields plus the image as multipart form data.
4. `RepairServiceController` validates the file and resolves the authenticated shop owner using the existing authorization/context logic.
5. The image is stored under `repair-services/{shop-owner-id}/` on the `public` disk; only the relative storage path is saved in the database.
6. On replacement, the new file is stored before the previous file is removed. On explicit removal during edit, the database path is cleared and the previous public file is deleted.
7. Public repair-shop payloads expose `image_url` for active services.
8. `repairShow.tsx` renders the image above each individual service card with lazy loading and a neutral fallback when `image_url` is absent.

## ERP upload UI

The Add/Edit Service modal will add an optional `Service image` field beside the existing form content:

- Visible label: `Service image (optional)`.
- Dashed upload area with keyboard focus support and a native file picker.
- Immediate preview using the selected local file.
- `Replace` and `Remove image` actions when a preview exists.
- Helper copy: `JPG, PNG, or WEBP / Maximum 5 MB / Recommended ratio 16:9`.
- Existing service fields, material template rules, duration inputs, and submit behavior remain unchanged.
- The submit action keeps loading/error/success feedback and surfaces image validation errors without losing the other form values.
- The modal scrim remains light enough to preserve context; the new surface uses white, black, and neutral gray only.

For an edit with no image change, the existing image remains. For an edit with a removed image, the backend receives an explicit removal flag so removal is not confused with an omitted file.

## Customer-side UI

Each Individual Services card on the repair-shop page will use the following hierarchy:

1. Image area with a fixed 16:9 aspect ratio and `object-fit: cover`.
2. Category badge over the image.
3. Service name and description bullets.
4. Price and duration footer.

The image area has a neutral black/white/gray fallback with an image icon and `No image yet` label. The image has descriptive alt text based on the service name, uses `loading="lazy"`, and never changes the card's content height unexpectedly. Existing package cards, booking selection behavior, service data, and monochrome customer-side styling remain unchanged.

## Backend and storage rules

- Add nullable `image_path` to `repair_services` with a reversible migration.
- Add `image_path` to the model's fillable attributes. Do not add file paths or file contents to activity-log entries; the existing service-change log should continue to record only its current service fields.
- Validate with Laravel's image/file rules: image, JPG/JPEG/PNG/WEBP, maximum 5120 KB.
- Store generated paths through `UploadedFile::store()` on the configured `public` disk; never trust the client filename as a path.
- Keep create/update/delete authorization and shop-owner scoping unchanged.
- Delete replaced/removed files only after the database write succeeds; do not delete a previous image when validation or persistence fails.
- Do not expose private upload paths, local filesystem paths, or exception details in JSON responses.

## Tests and verification

### Backend

- Feature coverage for creating a service with a valid image and persisting the scoped path.
- Feature coverage for rejecting invalid MIME/type and oversized images.
- Feature coverage for replacing and explicitly removing an existing image.
- Feature coverage that a service image cannot be changed across shop-owner scopes.
- Payload coverage for `image_url` on active public repair-shop services and a null/empty fallback case.

### Frontend

- Focused upload-service test for the image input, preview state, replace/remove controls, and multipart payload shape.
- Customer repair-shop test for rendering the image URL, alt text, lazy loading, and the no-image fallback.
- Preserve existing package-card, service selection, and duration behavior tests.

### Delivery checks

- Run focused Laravel and frontend tests first.
- Run the full frontend suite and production Vite build after implementation.
- Include a fresh `public/build` only after the final source changes and rebase, per `docs/git-workflow.md`.
- Run browser verification for desktop and 390px mobile layouts, including the upload preview and customer service cards.
- Run `git diff --check` and inspect the final diff before pushing the feature branch.

## Acceptance criteria

- Staff can add a service with no image, with one valid image, and edit/replace/remove an existing image.
- Invalid or oversized files are rejected with a clear recoverable error.
- A saved image appears on the matching customer-side Individual Services card after refresh.
- Services without images still render a stable, polished card.
- Package cards and existing repair booking behavior are unchanged.
- No blue or unrelated visual redesign is introduced in the new surfaces.
- Authorization, tenant scoping, and existing material-template requirements remain enforced.
