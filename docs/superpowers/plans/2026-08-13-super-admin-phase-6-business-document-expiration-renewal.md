# Super Admin Phase 6 Business Document Expiration and Renewal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in the existing `super-admin-phase-0-containment` worktree. Apply `superpowers:test-driven-development` before implementation changes, `laravel-best-practices` and `security-review` for uploads, private access, privileged review, scheduling, and reconciliation, the repository UI/design skills for registration/settings/review surfaces, `ponytail` for the minimum coherent solution, and `verification-before-completion` before every completion claim. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an immutable, private, reviewer-verified lifecycle for shop business documents—including DTI/SEC separation, explicit expiration metadata, renewal history, fixed reminders, and safe legacy reconciliation—without creating a second document-version subsystem or automatically changing shop status.

**Architecture:** Keep `shop_documents` as the single shop-document table. Add nullable lifecycle columns so old code can deploy safely, then make every new registration, resubmission, and renewal create a new version row with a stable logical slot. One `ShopDocumentLifecycleService` owns version creation and transactional review/promotion; the existing requirement service owns fixed type/slot/input rules, and one pure validity service owns local-date classification. A dedicated scheduled command scans only current approved reviewer-verified dated rows, while a small reminder-delivery table supplies database-enforced deduplication. Legacy reconciliation is dry-run-first and never guesses DTI versus SEC, expiration, missing files, or ambiguous current versions.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, private Laravel storage, Inertia 2, React 18, TypeScript 5.7, Tailwind CSS 4, PHPUnit 11, Vitest 3, pnpm.

**Status:** DRAFT FOR APPROVAL

---

## Design Authority and Scope Guard

Authoritative design:

- `docs/superpowers/specs/2026-08-12-super-admin-hardening-design.md`
- Phase 6, "Business Document Expiration and Renewal"
- Sections 17-22 and 24 where they define immutable rows, logical slots, expiration declarations, reviewer verification, DTI/SEC compatibility, renewal promotion, reminders, legacy reconciliation, security, and verification
- Approved refinements from the Phase 6 review: same-table immutable versions, exactly one DTI-or-SEC business registration, explicit no-expiration, stable supporting-document identity, no regulatory inference, no automatic shop-status mutation, and scheduled-command ownership

Planning baseline:

- Phase 5 plan commit: `f393ec661`
- Phase 5 implementation is present in the current worktree but is not committed as of this plan's creation.
- Worktree/branch: `.worktrees/super-admin-phase-0-containment` / `super-admin-phase-0-containment`

Before executing Phase 6, commit or otherwise establish a recoverable Phase 5 baseline and record its verification evidence. Do not stage, overwrite, or fold unrelated Phase 5 changes into a Phase 6 commit.

Phase 6 includes:

1. additive lifecycle fields on `shop_documents`, with no separate slot/version table;
2. distinct `dti_registration`, `sec_registration`, and legacy-only ambiguous DTI/SEC representation;
3. stable logical slots and deterministic version numbers;
4. explicit owner declaration of dated versus no-expiration metadata;
5. reviewer verification/correction during initial registration and renewal review;
6. immutable reapplication/resubmission and approved-shop renewal submission;
7. transactional renewal approval/rejection and current-version promotion;
8. derived validity using `Asia/Manila` local calendar dates;
9. fixed 30-day, 7-day, and expiration-date reminders with durable deduplication;
10. dry-run-first legacy reconciliation, private-access regression, focused UIs, and concurrency/failure tests.

Do not add in this phase:

- OCR, document-content extraction, regulatory validity calculations, document-type duration tables, configurable reminder thresholds, or automatic renewal approval;
- automatic shop suspension, deactivation, rejection, visibility removal, or entitlement change when a document expires;
- a generic compliance framework shared with HR, the incomplete HR expiry command, or unrelated employee-document changes;
- a separate document-slot/version aggregate, document manager, workflow engine, or configurable document-type administration UI;
- in-place owner edits to an approved document, file replacement, historical file deletion, or routine `ShopDocument` deletion;
- multiple simultaneous current DTI and SEC rows; they are concrete types in the same singleton `business_registration` slot;
- automatic classification of existing `dti_registration` rows as DTI. Existing uploads used a field labelled DTI/SEC and are therefore ambiguous unless independent evidence proves otherwise;
- supporting-document identity based on array position, display order, filename, or version number;
- reminders to every administrator. Routine expiry reminders go to the Shop Owner; reviewers receive only submitted-renewal/action notifications;
- broad route/controller cleanup, deletion of legacy compatibility paths, or generic structural consolidation owned by Phase 7;
- measured large-scale query tuning beyond bounded commands and a paginated renewal queue; final scale hardening remains Phase 8.

## Confirmed Pre-Phase-6 Baseline

- `shop_documents` currently has owner, type, private/public disk, path, status, and timestamps only. The model has no casts or relationships for versions, review metadata, or expiration.
- `dti_registration` is explicitly described and displayed as "DTI/SEC". New input cannot tell which authority issued the uploaded document.
- Current required types are one `dti_registration`, Mayor's Permit, BIR Certificate, and Valid ID. Supporting documents use `other_supporting_document` and have no stable per-item identity.
- Shop Owner registration writes private files, but one rejected reapplication path deletes all prior document rows and files after replacement.
- Signed resubmission overwrites the latest required row's file path/status, deletes the old file, and changes rejected historical rows back to pending.
- Registration approval locks rows and audits atomically, but it approves the latest type-based rows without expiration declarations, reviewer identity, or current-version promotion.
- Registration rejection changes latest rows to rejected. It must be narrowed to the pending candidate versions once history exists.
- The Shop Owner settings page displays one latest required document per concrete type but offers no derived validity, history, or renewal submission.
- The privileged registration page loads every owner/document and exposes only document type/URL. It cannot verify or correct expiration metadata.
- `PrivateSensitiveDocumentController` safely serves private bytes after access audit. Regular Admin can currently inspect pending/rejected registration documents but cannot inspect an approved shop's renewal case.
- Business-upgrade evidence may copy any approved `ShopDocument` of the matching old type. Phase 6 must restrict reuse to current approved evidence while preserving its separate immutable snapshot table.
- The application timezone is UTC, while `config('app.shop_timezone')` is `Asia/Manila`. Document validity/reminders must use the shop timezone without changing the global application timezone.
- Database queue and database cache are configured by default, but actual production scheduler topology/shared locking is not proven. The Phase 6 schedule may use `withoutOverlapping()` now; add `onOneServer()` only after deployment verification confirms a shared atomic cache and multi-node need.
- `hr:check-document-expiry` scans employee documents, mutates HR status, and contains notification TODOs. It is not reused or extended for shop compliance.
- The general notification `group_key` is indexed but not unique. A dedicated delivery identity is required for race-safe shop-document reminder deduplication.

## Frozen Phase 6 Contracts

### Concrete types and logical slots

```text
document_type                         logical_slot
---------------------------------------------------------------
dti_registration                     business_registration
sec_registration                     business_registration
legacy_dti_sec_registration          business_registration
mayors_permit                        mayors_permit
bir_certificate                      bir_certificate
valid_id                             valid_id
supporting_document                  supporting_document:<stable UUID>
```

- `legacy_dti_sec_registration` is reconciliation-only and cannot be submitted by current forms.
- New registration requires exactly one of DTI or SEC, selected by the owner and confirmed by the reviewer.
- DTI and SEC may replace one another in a renewal because they share one logical slot.
- Singleton slots are business registration, Mayor's Permit, BIR Certificate, and Valid ID.
- Each supporting-document upload receives a stable UUID slot. A renewal derives that same slot from its predecessor; UI ordering and filenames never define identity.

### Lifecycle fields

Add the smallest same-table lifecycle contract:

```text
logical_slot                   nullable for pre-reconciliation legacy rows
version_number                 nullable for pre-reconciliation legacy rows
predecessor_document_id        nullable self-reference
is_current                     nullable boolean: true for current, null otherwise
superseded_at                  nullable timestamp
issued_on                      nullable date
expiration_mode                dated | none | unknown; nullable before reconciliation
expires_on                     nullable date
reviewed_by_super_admin_id     nullable reviewer reference
reviewed_at                    nullable timestamp
rejection_reason               nullable bounded text
submission_key                 nullable unique UUID for renewal retry identity
checksum_sha256                nullable; required for new uploads/reused-version evidence
```

Database constraints/indexes:

- unique `(shop_owner_id, logical_slot, version_number)` for non-null version rows;
- unique `(shop_owner_id, logical_slot, is_current)`, where historical/non-current rows store null so only one true row is possible;
- unique `submission_key` when present;
- indexes for predecessor, pending renewal queue, and current dated expiry scans;
- self-reference uses `nullOnDelete()` only for schema compatibility; application code never deletes historical rows;
- reviewer reference uses `restrictOnDelete()` so identity is retained; routine administrator deletion remains unavailable.

`is_current=true` is valid only when status is approved. Application services enforce that cross-column invariant under lock, with the unique index as the race backstop.

### Expiration declaration and verification

Owner input:

```text
Expiration
( ) Has expiration date -> expires_on required
( ) No expiration       -> expires_on prohibited
```

Rules:

- Mayor's Permit requires `dated` and an explicit date.
- DTI, SEC, BIR Certificate, Valid ID, and supporting documents require either `dated` plus date or explicit `none`.
- `unknown` is never accepted from a current request; only legacy reconciliation may write it.
- `issued_on` is optional. If provided, it cannot be after `expires_on` for dated documents.
- Do not infer dates, calculate durations, or reject a truthful date merely because a hard-coded regulatory expectation says otherwise.
- Pending metadata is the owner's declaration. Reviewer approval may correct type within the business-registration slot and may correct issue/expiration fields after inspecting the private file. The audit records submitted and verified values.
- Once approved, file/type/slot/version/expiration metadata cannot be edited in place. A correction requires a new version through the same renewal review path.

### Derived validity

Only `is_current=true`, approved, reviewer-verified documents participate in ordinary validity:

```text
expiration_mode = none                           -> valid_no_expiration
dated and expires_on > local_today + 30 days    -> valid
dated and local_today <= expires_on <= +30 days -> expiring_soon
dated and expires_on < local_today               -> expired
unknown or not reviewer-verified                 -> metadata_unverified
```

The document remains valid on `expires_on` and becomes expired on the next `Asia/Manila` calendar date. This derived label never writes document status and never changes `ShopOwner::status`.

### Immutable submission and promotion

```text
current approved v1
        -> owner uploads v2 for same logical slot
        -> v1 stays approved/current
        -> v2 is pending/non-current

review approve under lock
        -> validate predecessor and slot
        -> v1 is_current = null, superseded_at = now
        -> v2 approved/reviewer-verified/is_current = true

review reject under lock
        -> v2 rejected/non-current with reason
        -> v1 remains unchanged/current
```

- At most one non-terminal renewal may exist per owner/logical slot.
- Lock order is Shop Owner, then all slot versions by ID.
- Version is `max(version_number)+1` under that lock.
- The submitted predecessor must still be current at review time. A stale renewal cannot replace a newer version.
- Exact duplicate submission key/decision returns current outcome. Reused key with conflicting metadata/checksum or conflicting terminal decision returns `409`.
- Files are staged privately before the transaction and deleted only if row creation fails. Historical files are never deleted after commit.

### Legacy compatibility

- Reconciliation maps old ambiguous `dti_registration` rows to `legacy_dti_sec_registration`, never to concrete DTI or SEC.
- A reliably preserved current approved legacy DTI/SEC row continues satisfying an approved shop's `business_registration` requirement until classified or replaced through an approved renewal.
- Legacy rows receive `expiration_mode=unknown`; the system does not infer dates from type or timestamps.
- During additive deployment, lifecycle-aware reads include a narrow fallback for unreconciled null-slot rows. New writes never use that fallback shape. Phase 7 removes destructive replacement code and compatibility after reconciliation evidence permits it.

### Reminder delivery

The command signature is:

```text
shop-documents:send-expiry-reminders
    {--date= : Asia/Manila date; defaults to local today}
    {--shop-owner-id= : Optional bounded operational scope}
    {--chunk=100 : Positive chunk size capped by the command}
```

It considers only:

```text
is_current = true
status = approved
reviewed_at is not null
expiration_mode = dated
expires_on in [today + 30, today + 7, today]
```

Reminder identity includes document/version, expiration date, threshold, recipient type, and recipient ID. The command creates the Shop Owner notification and reminder-delivery row atomically. A unique constraint makes command reruns and overlapping workers safe. If the scheduler misses a date, operators rerun with deterministic `--date=YYYY-MM-DD`; the command does not send three stale reminders at once or invent configurable catch-up policy.

The command runs daily in `config('app.shop_timezone')` with `withoutOverlapping()`. Do not add `onOneServer()` until shared atomic locking and production scheduler topology are verified.

### Routes and ownership

```text
Initial/resubmission upload -> existing Shop Owner registration controllers
Approved-shop renewal       -> ShopOwnerDocumentRenewalController
Registration verification   -> ShopOwnerRegistrationDecisionService
Renewal queue/review         -> superAdmin/ShopDocumentRenewalController
Private bytes                -> PrivateSensitiveDocumentController
Expiry reminders             -> SendShopDocumentExpiryReminders command
Legacy repair                -> ReconcileLegacyShopDocuments command
```

Canonical new routes:

```text
POST /shop-owner/compliance-documents/{document}/renewals -> shop-owner.compliance-documents.renewals.store
GET  /admin/document-renewals                         -> admin.document-renewals.index
POST /admin/document-renewals/{document}/approve      -> admin.document-renewals.approve
POST /admin/document-renewals/{document}/reject       -> admin.document-renewals.reject
```

Shop Owner renewal routes require the authenticated owner and object scope. Admin review routes require privileged authentication, active status, MFA, and `review_registrations`. Regular Admin and Super Admin may review; platform-security administration and recent reauthentication are not required for this operational review. UI visibility is never authorization.

---

## Task 1: Freeze the Phase 6 Boundary with Failing Tests

**Files:**

- Create: `tests/Feature/ShopDocuments/ShopDocumentLifecycleSchemaTest.php`
- Create: `tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php`
- Create: `tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php`

- [ ] **Step 1: Write failing schema/model contract tests**

Assert lifecycle columns, casts, relationships, hidden file path/checksum behavior, nullable-current uniqueness, per-slot version uniqueness, and supported concrete/legacy types. Assert `legacy_dti_sec_registration` and `expiration_mode=unknown` are impossible through current request rules.

- [ ] **Step 2: Write failing requirement/validity tests**

Cover exactly one DTI-or-SEC, same business slot, explicit none versus missing, Mayor's Permit dated-only, supporting stable slots, local-date boundaries, no-expiration, unverified/unknown, and no `ShopOwner::status` mutation.

- [ ] **Step 3: Write failing route and access tests**

Assert the four canonical routes, guards/capability/CSRF, owner object scope, Admin/Super Admin review access, and denial for unauthenticated, wrong-owner, setup, suspended, inactive, and non-MFA actors. Assert no generic document update/delete endpoint exists.

- [ ] **Step 4: Write regression tests for destructive current behavior**

Prove reapplication/resubmission currently overwrites/deletes history, registration approval lacks verified metadata, and regular Admin cannot inspect an approved-shop renewal case. These tests must fail before implementation.

- [ ] **Step 5: Run the red boundary group**

```powershell
php artisan test tests/Feature/ShopDocuments/ShopDocumentLifecycleSchemaTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Feature/SuperAdmin/PhaseSixDocumentRouteBoundaryTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php
```

## Task 2: Add the Same-Table Lifecycle Schema and Domain Rules

**Files:**

- Create via Artisan: `database/migrations/<timestamp>_add_lifecycle_fields_to_shop_documents_table.php`
- Create via Artisan: `database/migrations/<timestamp>_create_shop_document_reminder_deliveries_table.php`
- Modify: `app/Models/ShopDocument.php`
- Create: `app/Models/ShopDocumentReminderDelivery.php`
- Modify: `app/Models/ShopOwner.php`
- Modify: `app/Services/ShopOwnerDocumentRequirementService.php`
- Create: `app/Services/ShopDocumentValidityService.php`
- Create: `app/Services/ShopDocumentLifecycleService.php`
- Modify: `tests/Feature/ShopDocuments/ShopDocumentLifecycleSchemaTest.php`
- Modify: `tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php`
- Create: `tests/Unit/ShopDocumentValidityServiceTest.php`

- [ ] **Step 1: Generate additive migrations**

Do not edit deployed migrations. Add nullable lifecycle columns first, named constraints/indexes short enough for MySQL, and reversible `down()` operations for schema-only rollback. The reminder-delivery table stores document, expiration identity, threshold, recipient type/ID, linked notification ID where available, and timestamps; add one unique composite deduplication constraint.

- [ ] **Step 2: Expand models with explicit fillable fields, casts, and relationships**

Use date casts for `issued_on`/`expires_on`, datetime casts for review/supersession, boolean for nullable `is_current`, and relationships for predecessor, successors, reviewer, and reminders. Keep private path and checksum hidden from serialization. Define query scopes for current approved, pending renewals, and dated reminder candidates; do not add a global scope.

- [ ] **Step 3: Refactor the fixed requirement map around logical slots**

Expose fixed definitions for business registration, Mayor's Permit, BIR, Valid ID, and supporting documents. Keep concrete-type-to-slot normalization separate from legacy aliases. New input accepts only DTI/SEC concrete types; legacy fallback remains read-only. Update one-of evaluation so DTI and SEC never become two required slots.

- [ ] **Step 4: Implement pure derived validity**

Accept a document and an explicit local `CarbonImmutable` date for deterministic tests. Return a fixed enum-like string only; do not persist status or perform queries. Resolve production today through `config('app.shop_timezone')`.

- [ ] **Step 5: Implement the shared lifecycle service boundary**

The service owns immutable version creation and review/promotion transactions. It stages/cleans files through existing private storage conventions, locks owner then slot rows, computes version, validates predecessor/current/pending state, and writes normalized owner activity or privileged audit. It must not become a generic document repository or handle HR documents.

- [ ] **Step 6: Verify the domain layer**

```powershell
php artisan test tests/Feature/ShopDocuments/ShopDocumentLifecycleSchemaTest.php tests/Feature/ShopDocuments/ShopDocumentRequirementPolicyTest.php tests/Unit/ShopDocumentValidityServiceTest.php
```

## Task 3: Convert Registration and Resubmission to Immutable Versions

**Files:**

- Modify: `app/Http/Controllers/ShopOwnerAuthController.php`
- Modify: `app/Http/Controllers/ShopRegistrationController.php`
- Modify: `resources/js/Pages/UserSide/Auth/ShopOwnerRegistration.tsx`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php`
- Modify: `tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php`
- Create: `resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx`

- [ ] **Step 1: Change the owner contract to explicit business authority and expiration mode**

The canonical form submits one `business_registration` file plus `business_registration_type=dti_registration|sec_registration`, fixed required files, and metadata keyed by logical slot. Supporting items use client UUID keys carrying file plus metadata; the server validates the UUID and uses it as stable slot identity. The server rejects both/neither business types, missing expiration choice, `unknown`, dated-without-date, none-with-date, and Mayor `none`.

- [ ] **Step 2: Create version-one rows for new applications**

Every initial document receives logical slot, version 1, pending/non-current status, checksum, owner declaration, private disk/path, and no reviewer fields. Exactly one concrete business-registration row is created. Do not set current before approval.

- [ ] **Step 3: Replace destructive rejected reapplication**

Remove `documents()->delete()` and all post-commit historical-file deletion. Under lock, create a new version for every required slot presented for the new application. Reused unaffected evidence becomes a new pending row pointing to the historical predecessor and retaining the same immutable private file/checksum; a newly uploaded file gets a new private path. Historical rejected rows remain unchanged.

- [ ] **Step 4: Replace in-place signed resubmission updates**

Do not set rejected rows back to pending or replace their path. Create new rows for replaced and reused required evidence, preserve predecessor/version/slot, and create new stable UUID slots for newly added supporting documents. Existing supporting items retained for review keep their stable slot through a new version; omitted old supporting evidence remains historical and is not deleted.

- [ ] **Step 5: Keep all registered upload routes safe**

The active UI posts to `ShopOwnerAuthController`, but `/shop/register-full`, `/api/shop/register`, and `/api/shop/register-full` remain registered. Make each delegate to the shared lifecycle rules or fail closed with the new required contract; no route may continue creating unversioned/ambiguous rows. Do not remove compatibility routes until Phase 7 dependency review.

- [ ] **Step 6: Verify registration/resubmission**

```powershell
php artisan test tests/Feature/LocationPolicy/ShopOwnerAuthRegistrationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationTest.php tests/Feature/LocationPolicy/ShopOwnerRegistrationEmailVerificationSuccessTest.php
pnpm exec vitest run resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx
```

## Task 4: Require Reviewer-Verified Metadata for Registration Decisions

**Files:**

- Create: `app/Http/Requests/SuperAdmin/ApproveShopOwnerRegistrationRequest.php`
- Modify: `app/Http/Controllers/superAdmin/ShopOwnerRegistrationViewController.php`
- Modify: `app/Services/ShopOwnerRegistrationDecisionService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx`
- Modify: `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`
- Modify: `tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php`

- [ ] **Step 1: Expose only pending candidate metadata and private URLs**

Registration payloads include document ID, concrete/legacy-safe label, logical slot, version, submitted issue/expiration fields, derived preview state, and private URL. Never serialize path, disk, checksum, or arbitrary model fields. Keep existing registration pagination behavior for now if changing it would leak Phase 8 scope, but avoid adding per-document queries.

- [ ] **Step 2: Validate a complete reviewer decision payload**

Approval must identify exactly one pending candidate for each singleton slot and all supporting candidates included in the application. For each, require verified type where applicable, expiration mode/date, and optional issue date. Business type correction is limited to DTI/SEC within `business_registration`; no other slot/type reassignment is accepted.

- [ ] **Step 3: Promote registration documents transactionally**

Reuse the lifecycle service inside the existing owner-lock transaction. Verify file privacy/existence, source state, slot completeness, and one-of business registration. Mark candidates approved, reviewer-verified, and current; supersede a prior current only when a legitimate reapplication has one. Update shop status, setup token, module provisioning, mandatory audit, and durable approval delivery atomically as Phase 3 requires.

- [ ] **Step 4: Narrow registration rejection to pending candidate versions**

Set only the current application's pending rows to rejected with the reason/reviewer/time. Never rewrite approved/current/historical predecessors. Exact repeated rejection with the same outcome is inert; a conflicting decision returns `409`.

- [ ] **Step 5: Make the UI enforce inspection and verification without granting authority**

Show owner declarations beside reviewer controls, require each private document to be viewed, provide dated/no-expiration controls, and show DTI/SEC confirmation. Submit real metadata to the protected endpoint and handle `403`, `409`, `422`, and sanitized `500` without optimistic success.

- [ ] **Step 6: Verify registration review and rollback**

```powershell
php artisan test tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/PrivilegedTransactionFailureInjectionTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

## Task 5: Add Approved-Shop Renewal Submission and Compliance UI

**Files:**

- Create: `app/Http/Requests/ShopOwner/SubmitShopDocumentRenewalRequest.php`
- Create: `app/Http/Controllers/ShopOwner/ShopOwnerDocumentRenewalController.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `app/Services/ShopOwnerDocumentRequirementService.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
- Create: `resources/js/Pages/ShopOwner/Settings/components/BusinessDocumentCompliance.tsx`
- Create: `resources/js/Pages/ShopOwner/Settings/components/__tests__/BusinessDocumentCompliance.test.tsx`
- Create: `tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php`

- [ ] **Step 1: Write failing owner scope and immutable-submission tests**

Cover approved owner, wrong owner, non-approved/archived/suspended access according to existing auth policy, current approved predecessor, stale/non-current predecessor, existing pending renewal, same-key replay, conflicting key reuse, DTI-to-SEC renewal, metadata validation, private storage failure, and no mutation/deletion of predecessor.

- [ ] **Step 2: Add one scoped renewal mutation**

Use nested/scoped route binding or explicit owner/predecessor checks. Pass the validated upload/metadata to the lifecycle service, which stages and hashes the generated private file before its locked row-creation transaction. On database failure it deletes only the uncommitted staged file. On success retain every historical file, create one capability-scoped recipient row in the existing `Notification` model per eligible active/MFA reviewer inside the transaction, and queue deduplicated reviewer mail after commit through the existing privileged dispatcher. Return the pending row's safe state, not storage internals.

- [ ] **Step 3: Build a truthful compliance payload**

For each singleton and supporting slot, return current approved version, derived validity, verified metadata, pending renewal summary, and ordered safe history with private URLs. Preserve a narrow legacy fallback label such as "Legacy DTI/SEC — classification pending". Never silently present it as DTI.

- [ ] **Step 4: Replace the read-only settings card with a focused component**

Show valid/no-expiration/expiring/expired/unverified states, expiration date, version, history, and pending decision. Renewal opens a form for file, optional issue date, and explicit expiration mode. Business registration also permits DTI or SEC selection. Current metadata has no edit controls; pending submission disables another renewal.

- [ ] **Step 5: Verify owner flow**

```powershell
php artisan test tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
pnpm exec vitest run resources/js/Pages/ShopOwner/Settings/components/__tests__/BusinessDocumentCompliance.test.tsx
```

## Task 6: Add the Privileged Renewal Queue and Transactional Review

**Files:**

- Create: `app/Http/Requests/SuperAdmin/ReviewShopDocumentRenewalRequest.php`
- Create: `app/Http/Controllers/superAdmin/ShopDocumentRenewalController.php`
- Modify: `app/Http/Controllers/PrivateSensitiveDocumentController.php`
- Modify: `app/Services/ShopDocumentLifecycleService.php`
- Modify: `app/Services/PrivilegedAudit.php`
- Modify: `app/Enums/NotificationType.php`
- Modify: `app/Enums/PrivilegedDeliveryType.php`
- Modify: `app/Jobs/SendPrivilegedWorkflowMail.php`
- Create: `app/Notifications/ShopDocumentRenewalSubmitted.php`
- Create: `app/Notifications/ShopDocumentRenewalReviewed.php`
- Modify: `routes/web.php`
- Create: `resources/js/Pages/superAdmin/Shops/DocumentRenewalQueue.tsx`
- Modify: `resources/js/layout/AppSidebar.tsx`
- Create: `resources/js/Pages/superAdmin/Shops/__tests__/DocumentRenewalQueue.test.tsx`
- Create: `tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php`
- Create: `tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php`

- [ ] **Step 1: Build a scoped paginated queue**

List pending renewal rows with owner summary, predecessor/current summary, safe private URLs, owner-declared metadata, version, submitted time, and fixed filters. Default ordering is deterministic (`created_at`, then ID). Use a modest capped page size and eager loading; Phase 8 measures/tunes at production scale.

- [ ] **Step 2: Expand private access only for authorized renewal cases**

Regular Admin with `review_registrations` may view a pending renewal for an approved shop and the exact predecessor needed to decide it. They do not gain unrestricted approved-shop document browsing. Super Admin retains full authorized scope. Access audit remains mandatory before bytes are served, and all history remains private/no-store/nosniff.

- [ ] **Step 3: Implement approval under canonical locks**

Extend the fixed privileged-delivery enum/job payload allowlists with submitted/reviewed document-renewal types. Submitted mail resolves only active, MFA-complete recipients with `review_registrations`; reviewed mail resolves the target Shop Owner. Lock owner then all slot versions. Verify pending source, same owner/slot, current predecessor, no competing current, and reviewer metadata rules. Apply reviewer corrections, approve/promote target, supersede predecessor, write normalized privileged audit and one Shop Owner operational notification in the same transaction, then queue deduplicated owner mail after commit using a document/decision business identity.

- [ ] **Step 4: Implement rejection without disturbing current evidence**

Require a bounded reason, mark only the pending target rejected/reviewed, preserve predecessor/current and shop status, write audit plus one Shop Owner operational notification atomically, and queue deduplicated mail after commit. Matching terminal replay is inert; different outcome/reason or stale predecessor is `409`.

- [ ] **Step 5: Add concurrency and failure injection**

Cover two approvals, approve versus reject, renewal approval versus a newer current promotion, and two submissions for one slot. Assert one current row, one terminal result, one audit, one decision notification, and rollback when audit fails. Use the repository's database-engine concurrency pattern and explicit skip reason where SQLite cannot prove locking.

- [ ] **Step 6: Verify review flow**

```powershell
php artisan test tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php
pnpm exec vitest run resources/js/Pages/superAdmin/Shops/__tests__/DocumentRenewalQueue.test.tsx resources/js/layout/__tests__/AppSidebar.test.tsx
```

## Task 7: Add Deterministic Expiry Reminders Without Status Mutation

**Files:**

- Create: `app/Console/Commands/SendShopDocumentExpiryReminders.php`
- Create: `app/Services/ShopDocumentReminderService.php`
- Modify: `app/Models/ShopDocumentReminderDelivery.php`
- Modify: `app/Enums/NotificationType.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php`
- Modify: `tests/Feature/Notifications/NotificationRouteContractTest.php`
- Modify: `docs/runbooks/super-admin-operations.md`

- [ ] **Step 1: Write failing threshold, timezone, and deduplication tests**

Use `Carbon::setTestNow()` and explicit `--date` values around UTC/Asia-Manila midnight. Cover 31/30/29, 8/7/6, expiration day, next day, no-expiration, unknown, unverified, pending/rejected/historical, changed expiration identity, archived owner, command replay, and two workers racing for one identity.

- [ ] **Step 2: Implement a bounded candidate query**

Query only indexed current approved reviewer-verified dated rows for the three exact dates and process with `chunkById()`. Select/eager-load only required owner columns. The service inserts the owner notification plus delivery identity atomically; duplicate-key contention returns the existing outcome rather than a second notification.

- [ ] **Step 3: Use a dedicated shop-document notification type**

Add the fixed `SHOP_DOCUMENT_EXPIRING`, `SHOP_DOCUMENT_RENEWAL_SUBMITTED`, and `SHOP_DOCUMENT_RENEWAL_REVIEWED` types to the appropriate owner/admin/category mappings. Notification data contains document ID, logical slot, version, decision/expiration date/threshold where relevant, and safe action URL only—never private path/checksum or document bytes.

- [ ] **Step 4: Register the separate schedule**

Schedule `shop-documents:send-expiry-reminders` daily at a fixed local time with `->timezone(config('app.shop_timezone'))->withoutOverlapping()`. Do not call or modify `hr:check-document-expiry`. Do not use `onOneServer()` until the runbook's shared-lock verification succeeds.

- [ ] **Step 5: Document retry and missed-day operation**

Record deterministic `--date`, `--shop-owner-id`, capped `--chunk`, duplicate-safe rerun, scheduler health check, and the rule that the command never changes document or shop status.

- [ ] **Step 6: Verify reminders**

```powershell
php artisan test tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php tests/Feature/Notifications/NotificationRouteContractTest.php
php artisan schedule:list
```

## Task 8: Reconcile Legacy Rows and Preserve Cross-Workflow Compatibility

**Files:**

- Create: `app/Console/Commands/ReconcileLegacyShopDocuments.php`
- Create: `app/Services/LegacyShopDocumentReconciler.php`
- Modify: `app/Services/ShopOwnerDocumentRequirementService.php`
- Modify: `app/Actions/ShopOwner/SubmitShopOwnerUpgradeRequest.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `tests/Feature/BusinessScaling/ShopOwnerUpgradeSubmissionTest.php`
- Modify: `tests/Feature/BusinessScaling/ShopSettingsBusinessScalingPayloadTest.php`
- Create: `tests/Feature/Console/ReconcileLegacyShopDocumentsTest.php`
- Modify: `docs/runbooks/super-admin-operations.md`

The command signature is `shop-documents:reconcile-legacy {--apply} {--shop-owner-id=} {--chunk=100}`; validate and cap numeric options before querying.

- [ ] **Step 1: Build fixture groups from actual legacy shapes**

Cover one reliable required row, multiple chronological versions, ambiguous DTI/SEC, public/private/missing file, duplicate approved candidates, pending/rejected applications, approved shop with legacy business evidence, independent supporting rows, null timestamps, and rerun. Dry-run changes nothing and reports local IDs/counts without filenames/paths/PII.

- [ ] **Step 2: Implement conservative deterministic backfill**

In bounded owner chunks and short locked transactions:

- normalize old ambiguous DTI/SEC to `legacy_dti_sec_registration` and `business_registration`;
- assign singleton logical slots and deterministic versions only where ordering/evidence is reliable;
- assign each independent old supporting row `supporting_document:legacy:<document-id>` version 1;
- set `expiration_mode=unknown`, never inferred dates;
- set a current marker only for one reliable approved/private candidate;
- preserve approved-shop legacy business satisfaction through the explicit compatibility rule;
- report but do not guess duplicate current candidates, missing/private-storage failures, or unorderable groups.

An `--apply` rerun is inert. Do not perform data manipulation inside the schema migration.

- [ ] **Step 3: Make deployment reads backward-compatible**

Until reconciliation is complete, current-document/requirement payloads may fall back to the old deterministic latest-row rule only for null lifecycle fields and must label the result legacy/unverified. Fully versioned rows never use fallback. New submissions cannot produce null lifecycle fields.

- [ ] **Step 4: Restrict business-upgrade evidence reuse**

Reuse only the owner's current approved private document for the logical requirement. Accept a current legacy ambiguous business row for an already approved shop under the compatibility rule, preserving its legacy label in the upgrade snapshot; new upgrade uploads must explicitly identify DTI or SEC where business registration is required. Keep `shop_owner_upgrade_request_documents` as immutable request evidence; do not merge it into the version lifecycle.

- [ ] **Step 5: Document rollout inventory and ambiguity handoff**

Run the existing sensitive-document private-storage dry-run/apply before lifecycle promotion where needed. Record totals by safe category, unresolved owner/document IDs, operator remediation, rerun evidence, and the rule that approved shops are not silently invalidated.

- [ ] **Step 6: Verify reconciliation and compatibility**

```powershell
php artisan test tests/Feature/Console/ReconcileLegacyShopDocumentsTest.php tests/Feature/BusinessScaling/ShopOwnerUpgradeSubmissionTest.php tests/Feature/BusinessScaling/ShopSettingsBusinessScalingPayloadTest.php tests/Feature/SuperAdmin/SensitiveDocumentMigrationCommandTest.php
```

## Task 9: Security, Invariant, and Frontend Regression Pass

**Files:**

- Modify: `tests/Feature/SuperAdmin/PrivilegedPhaseThreeBoundaryTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedFailureAuditTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivilegedAuditHistoryTest.php`
- Create: `tests/Feature/ShopDocuments/ShopDocumentInvariantTest.php`
- Modify: `tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php`
- Modify: `resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts`
- Modify: `resources/js/Pages/ShopOwner/Settings/components/__tests__/BusinessDocumentCompliance.test.tsx`
- Modify only where Phase 6 creates an orphan: related imports/helpers/tests

- [ ] **Step 1: Assert immutable history at every public mutation boundary**

Search every `ShopDocument` create/update/delete/file-delete caller. Tests must prove no active registration, resubmission, renewal, review, settings, upgrade-reuse, or reconciliation path overwrites an approved/rejected historical row or deletes a historical file.

- [ ] **Step 2: Assert complete authorization and safe errors**

Cover backend capability independent of UI, nested ownership, private access, CSRF, upload MIME/extension/size, generated filenames, path secrecy, generic failure/correlation responses, no raw request/file metadata in audit, and validation without failure-audit noise.

- [ ] **Step 3: Assert audit boundaries**

Privileged registration/renewal decisions and reviewer corrections include actor/role, target document/owner, previous/resulting status, version/slot, submitted versus verified expiration metadata, reason, IP, and correlation ID. They exclude path, filename, checksum, bytes, and raw request. Routine owner reminders remain notifications, not privileged audit entries.

- [ ] **Step 4: Assert core concurrency/data invariants**

At all times:

```text
one true current marker per owner/logical slot
current implies approved
version unique per owner/logical slot
pending/rejected never replaces current
stale predecessor cannot promote
exactly one DTI-or-SEC current business registration
legacy ambiguous evidence remains explicit
expiration never changes shop status
one reminder per expiration identity/recipient/threshold
```

- [ ] **Step 5: Run the focused Phase 6 suite**

```powershell
php artisan test tests/Feature/ShopDocuments tests/Feature/ShopOwner/ShopDocumentRenewalSubmissionTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalReviewTest.php tests/Feature/SuperAdmin/ShopDocumentRenewalConcurrencyTest.php tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/Console/ReconcileLegacyShopDocumentsTest.php tests/Feature/Console/SendShopDocumentExpiryRemindersTest.php
pnpm exec vitest run resources/js/Pages/UserSide/Auth/__tests__/ShopOwnerRegistrationDocuments.test.tsx resources/js/Pages/ShopOwner/Settings/components/__tests__/BusinessDocumentCompliance.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/DocumentRenewalQueue.test.tsx resources/js/Pages/superAdmin/Shops/__tests__/ShopOwnerRegistrationView.test.ts
```

## Task 10: Required Review Stack, Deployment Verification, and Handoff

**Files:**

- Modify: `docs/runbooks/super-admin-operations.md`
- Modify: `docs/superpowers/plans/2026-08-13-super-admin-phase-6-business-document-expiration-renewal.md`
- Create only if Phase 6 produces a durable reusable lesson: `docs/ai-learning-log.md`

- [ ] **Step 1: Perform the required sequential review stack**

Record:

1. `ponytail` simplification—keep one table, one lifecycle service, one fixed rule map, no generic compliance engine, no OCR/regulatory rules, and no new dependency;
2. Standards review—Laravel conventions, generated focused migrations, Form Requests, explicit casts/relationships, lock order, bounded queries, deterministic schedule, private storage, and focused controllers;
3. Spec review—every frozen Phase 6 invariant and no Phase 7/8 scope leakage;
4. TypeScript/React review—focused types/components, safe narrowing, accessible date/radio/upload/review controls, no `any`, and no duplicated backend authority;
5. Karpathy review—surface deployment assumptions, keep the diff surgical, and remove only orphans created by immutable lifecycle conversion;
6. security review—object scope, capability, CSRF, upload validation, private files, access audit, mass assignment, sanitized responses/logs/audit, and reminder URLs;
7. reuse/dead-code review—reuse existing storage/audit/notification/route patterns and confirm the HR command remains separate;
8. improvement evidence—before/after destructive replacement, DTI/SEC ambiguity, reminder deduplication, route truthfulness, and query bounds; report unmeasured performance as `not measured`.

- [ ] **Step 2: Run structural inspection**

```powershell
php artisan route:list --path=shop-owner/compliance-documents
php artisan route:list --path=admin/document-renewals
php artisan schedule:list
php artisan migrate:status
rg -n "documents\(\)->delete|ShopDocument::.*delete|deleteDocumentFiles|file_path.*save|other_supporting_document|Business Registration \(DTI/SEC\)" app resources/js routes tests
```

Explain every remaining match. No current mutation may delete/overwrite history or create an ambiguous new DTI/SEC row.

- [ ] **Step 3: Run broader regression gates**

```powershell
php artisan test tests/Feature/LocationPolicy tests/Feature/BusinessScaling tests/Feature/SuperAdmin/RegistrationDecisionWorkflowTest.php tests/Feature/SuperAdmin/PrivateSensitiveDocumentAccessTest.php tests/Feature/Notifications
pnpm run test:frontend
composer test
pnpm run build
git diff --check
```

The repository has no committed TypeScript compiler configuration or frontend lint script; do not report type-checking/linting as passing unless that tooling is actually added and run. If an existing environment prerequisite blocks a broad suite, record exact command/output and focused passing evidence.

- [ ] **Step 4: Run reconciliation and scheduler dry-runs against a safe database snapshot**

Record:

- private-storage inventory and unresolved public/missing files;
- `shop-documents:reconcile-legacy` dry-run totals and ambiguity categories;
- reviewed bounded `--apply` result and inert rerun in staging/test data;
- deterministic reminder command for safe dates and deduplication rerun;
- verification that no Shop Owner status changed.

Never run destructive database commands or production reminders without explicit deployment authority.

- [ ] **Step 5: Browser-verify both roles and the Shop Owner flow**

```text
new owner -> chooses DTI or SEC + explicit expiration modes
reviewer -> views every private document + verifies metadata
approved owner -> sees current validity/history and submits renewal
old current -> remains active while renewal pending/rejected
Admin -> reviews approved-shop renewal within scoped access
approval -> exactly one promoted current version
expiry date -> derived state/reminder only; shop remains approved
legacy DTI/SEC -> visibly ambiguous but still satisfies approved shop
```

Check mobile/desktop forms, keyboard labels/focus, browser console, network errors, private response headers, and negative `403`/`409`/`422` behavior.

- [ ] **Step 6: Complete execution evidence and hand off Phase 7**

Update this plan to `EXECUTED` only after migrations, reconciliation evidence, focused/broad tests, build, route/schedule/storage inspection, security review, and browser flows are recorded. Include unresolved legacy counts, scheduler topology decision, whether `onOneServer()` remains intentionally absent, and any Phase 7 cleanup candidates. Phase 7 may then remove destructive replacement compatibility and consolidate ownership only after current lifecycle paths are authoritative.

---

## Acceptance Checklist

- [ ] `shop_documents` remains the only shop-document version table.
- [ ] Every current upload creates a new immutable row; no historical row/file is overwritten or deleted.
- [ ] DTI and SEC are distinct types sharing one `business_registration` slot.
- [ ] New submissions contain exactly one owner-selected, reviewer-confirmed DTI or SEC.
- [ ] Existing ambiguous DTI/SEC is never auto-classified and remains compatible for approved shops.
- [ ] Supporting slots use stable UUID/legacy-ID identity independent of order, filename, and version.
- [ ] Mayor's Permit requires a date; every other current type requires date or explicit no-expiration.
- [ ] `unknown` is legacy-only; no regulatory dates/durations are inferred.
- [ ] Only approved reviewer-verified rows can be current or participate in ordinary validity/reminders.
- [ ] Pending/rejected renewal never replaces current evidence.
- [ ] Approval promotion, supersession, reviewer metadata, and privileged audit commit atomically.
- [ ] Exact duplicate submission/review is idempotent; stale/conflicting outcomes return `409`.
- [ ] Validity uses `Asia/Manila`, remains valid through expiration day, and never mutates shop status.
- [ ] Reminders occur at fixed 30/7/0 thresholds and deduplicate by document/version/date/threshold/recipient.
- [ ] Shop files remain private and cannot be served if access audit fails.
- [ ] Admin can inspect only renewal cases in operational scope; Super Admin retains full authorized history.
- [ ] Registration, settings, renewal queue, business-upgrade reuse, and notifications use lifecycle-aware evidence.
- [ ] Legacy reconciliation is bounded, dry-run-first, idempotent, and reports rather than guesses.
- [ ] Focused tests, concurrency/failure paths, broad suites, build, structural inspection, reconciliation evidence, and browser flows are recorded before completion.

## Deployment and Rollback Notes

Deploy additively:

1. commit/verify the completed Phase 5 baseline and preserve a database/storage backup;
2. deploy nullable lifecycle and reminder-delivery schema while old reads still work;
3. deploy lifecycle-aware code that writes complete new rows and supports null-field legacy fallback;
4. run the existing private-storage migration dry-run/apply for unresolved approved documents;
5. run `shop-documents:reconcile-legacy` dry-run, review ambiguity categories, then bounded `--apply`;
6. rerun reconciliation to prove idempotency and verify approved-shop legacy business continuity;
7. enable owner renewal and privileged review routes/UI;
8. run deterministic reminder tests, verify one scheduler and overlap locking, then enable the daily schedule;
9. add `onOneServer()` only if deployment evidence proves multiple schedulers and a shared atomic cache;
10. retain null-field fallback and legacy type until Phase 7/8 evidence supports cleanup.

Rollback application code before schema. New nullable columns/tables remain compatible with pre-Phase-6 reads, but approved versions, review history, reminders, and private files are historical evidence and must not be deleted to imitate rollback. If an application rollback occurs after new version rows exist, disable new mutation routes/schedule, preserve all rows/files, and forward-fix lifecycle compatibility before re-enabling writes. Never restore destructive document replacement or reclassify legacy DTI/SEC to make rollback appear clean.
