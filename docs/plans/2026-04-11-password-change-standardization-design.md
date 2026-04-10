# Password Change Standardization Design

Date: 2026-04-11
Status: Approved
Owner: GitHub Copilot (brainstorming workflow)

## 1. Problem Statement

Change password support is inconsistent across account roles:
- Customer: backend and UI exist, but UI visibility is coupled to personal-info edit mode.
- Employee (ERP): dedicated change password card already exists.
- Shop owner: no regular authenticated change password endpoint or UI card (only first-time setup flow exists).

This creates UX inconsistency and security-policy drift across roles.

## 2. Goals

- Provide a standard change password experience for customer, shop owner, and employee.
- Make security controls consistent across roles.
- Keep changes minimal and aligned with current architecture.

## 3. Non-Goals

- No account recovery/forgot-password redesign.
- No broad auth refactor to centralize all role controllers.
- No database schema changes.

## 4. Approved Decisions

1. Security card placement: always visible as a separate section on each profile page.
2. Password policy: strong Laravel policy (min 8, mixed case, numbers, symbols).
3. Current password: required for every regular password change.
4. Shop owner backend placement: reuse existing ShopProfileController.
5. Customer UX: decouple password form visibility from personal edit mode.
6. Rate limit: apply throttle middleware to all password update routes.

## 5. Selected Approach

Approach 1 (targeted per-role update) was selected.

Why:
- Minimal risk and smallest code diff.
- Matches existing route/controller organization.
- Fastest path to consistent UX and policy.

## 6. Architecture

Keep role-owned routes/controllers/pages, and standardize policy behavior:

- Shop owner:
  - Add updatePassword method in existing ShopProfileController.
  - Add authenticated POST route for password update near existing shop profile routes.

- Customer:
  - Keep existing endpoint/controller method.
  - Make change password section always visible in profile UI.

- Employee (ERP):
  - Keep existing endpoint and UI placement.
  - Align validation and throttle behavior with other roles.

## 7. Component and Route Design

### 7.1 Shop Owner

Backend:
- Add password update route inside shop owner authenticated group in routes/web.php.
- Route naming follows existing group naming conventions.

Controller:
- Add updatePassword(Request $request) in ShopProfileController.
- Validate current_password and new password policy.
- Verify current password with Hash::check.
- Save hashed password on success.

Frontend:
- Add dedicated Change Password card to ShopOwner Settings profile page.
- Keep feedback style consistent with current alerts/forms.

### 7.2 Customer

Backend:
- Keep current route and CustomerProfileController::updatePassword.
- Align validation rule style with standard policy.

Frontend:
- Move Change Password into an always-visible Security card.
- Remove dependency on personal-info edit state.

### 7.3 Employee (ERP)

Backend:
- Keep UserProfileController::updatePassword endpoint.
- Align password rule implementation and middleware behavior.

Frontend:
- Keep current Change Password card placement.

## 8. Data Flow

For each role:
1. User enters current password, new password, and confirmation.
2. Frontend posts to role-specific endpoint.
3. Backend validates fields and password policy.
4. Backend checks current password hash.
5. On success, backend stores hashed new password and returns success response.
6. On failure, backend returns field-level errors.

## 9. Security and Error Handling

### 9.1 Security Controls

- Enforce strong password policy consistently across all three role controllers.
- Require current password on all regular password updates.
- Add throttle middleware to all change-password endpoints.
- Keep role/guard isolation intact:
  - shop owner via auth:shop_owner
  - customer and ERP employee via auth:user and existing role boundaries

### 9.2 Error Handling

- Validation errors returned as field-level errors.
- Invalid current password mapped to current_password field.
- Throttle errors shown with retry-later message.
- Success uses each page's existing UX pattern.

## 10. Testing Strategy

### 10.1 Feature Tests

- Successful password change for each role.
- Reject incorrect current password.
- Reject weak password.
- Reject password confirmation mismatch.
- Verify throttle behavior on repeated attempts.

### 10.2 UI/Behavior Checks

- Customer security card visible even when not editing profile details.
- Shop owner profile includes change password card.
- ERP profile card still works after alignment.

### 10.3 Regression Checks

- Customer profile update remains functional.
- Shop owner profile/business update remains functional.
- ERP profile page behavior remains stable.
- Existing shop owner first-time password setup flow remains unchanged.

## 11. Rollout Plan

1. Implement backend route and controller updates.
2. Implement frontend security-card updates for customer and shop owner.
3. Apply throttle middleware and policy alignment to all routes.
4. Run tests and smoke checks.
5. Deploy as a no-migration change.

## 12. Risks and Mitigations

- Risk: inconsistent validation behavior between controllers.
  - Mitigation: align rules explicitly in each method during implementation.
- Risk: UI state coupling regressions on customer page.
  - Mitigation: isolate password form state from personal info edit state.
- Risk: user friction from stronger policy.
  - Mitigation: show clear helper text for password requirements.

## 13. Acceptance Criteria

- Shop owner, customer, and employee can all change password from profile security card.
- All three require current password.
- All three enforce strong password rule.
- All three routes include throttle protection.
- Existing profile update features are unaffected.
