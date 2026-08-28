# Shop Owner Profile UI Design

## Goal

Refresh the authenticated shop owner profile page into a responsive, Facebook-inspired profile surface while keeping the existing monochrome SoleSpace visual language and all existing backend behavior intact.

## Design

The page keeps one responsive profile shell: a cover-photo hero, an overlapping profile-photo avatar, identity metadata, and compact photo actions. The content below uses a two-column desktop grid that collapses to one column on smaller screens. The primary information flow is:

1. Profile identity and photo controls.
2. Personal information and shop description.
3. Contact and address information.
4. Operating hours.
5. Security/password update.

Light Mode uses the existing SoleSpace monochrome tokens from `DESIGN.md`: `#111111` for primary actions and text, white surfaces, soft neutral gray for secondary surfaces, and neutral hairline borders. Hover and focus states remain visible without introducing brand-colored controls. Dark Mode keeps explicit `dark:` variants and is not replaced by Light Mode values.

Photo controls remain direct, accessible buttons with labels/tooltips, visible loading/disabled states, image alt text, and local image updates after the existing upload response. Removing a photo remains confirmation-gated. The edit form and operating-hours modal keep their current field names, Inertia routes, validation behavior, and success/error feedback.

## Backend Contract

No Laravel controller, route, migration, model, validation rule, API endpoint, or database behavior changes are required. The UI continues to use:

- `POST /shop-owner/shop-profile`
- `POST /shop-owner/shop-profile/password`
- `POST /api/shop-owner/upload-profile-photo` with either `profile_photo` or `cover_photo`
- `DELETE /api/shop-owner/profile-photo`
- `DELETE /api/shop-owner/cover-photo`

The profile payload and operating-hours keys remain unchanged.

## Interaction and Accessibility

- Every form control has a visible label or an explicit accessible name.
- Photo buttons expose whether they upload or remove a cover/profile image.
- Async actions disable their trigger and provide progress text or a spinner.
- Errors remain announced through the existing SweetAlert feedback path.
- The layout is usable at mobile, tablet, and desktop widths without horizontal overflow.
- Focus rings use neutral contrast in Light Mode and preserve dark-theme contrast in Dark Mode.

## Acceptance Criteria

- The profile page visually matches the existing monochrome dashboard/card language.
- All visible blue/orange/purple decorative buttons and Light Mode status treatments on this page are neutral monochrome.
- Cover and profile photos can still upload, replace, and remove through the current endpoints.
- Edit Profile still submits the same profile and operating-hours payload.
- Password update still submits the same password payload.
- Operating-hours modal validation and copy-to-all behavior still work.
- Dark Mode retains explicit dark surfaces, text, borders, and semantic status variants.
- Focused tests, full frontend tests, `vite build`, and `git diff --check` pass.
