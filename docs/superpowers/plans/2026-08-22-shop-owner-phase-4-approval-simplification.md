# Shop Owner Phase 4 Centralized Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Repository policy requires one main agent and sequential execution; do not dispatch implementation subagents.

**Goal:** Add safe per-family Shop Owner approval toggles and consolidate every owner approval into one clear, accessible Action Center without replacing the seven authoritative domain workflows.

**Architecture:** Continue directly in the existing Phase 3 worktree at `C:\xampp\htdocs\solespace-master\.worktrees\shop-owner-phase-3-action-center` on `feat/shop-owner-phase-3-action-center`, whose completed Phase 3C baseline is `0124f7228`. Prove every proposed toggle-OFF destination before changing production code; a family with no existing authoritative non-owner decision stage blocks execution and returns to focused design review. Snapshot the approved policy when a workflow starts, extend the Phase 3 adapter registry for owner-pending projections, and use one Action Center master-detail UI that reads domain details and calls existing domain mutations.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Vite 7, Tailwind CSS 4, PHPUnit, Vitest, pnpm.

---

## Execution contract

### Approved outcomes

- Seven binary Settings toggles: Refund, Price, Payslip, Salary Adjustment, Purchase Request, Expense, and Repair Reject.
- ON includes the existing Shop Owner decision stage.
- OFF skips only that stage and only when characterization proves an already-authoritative downstream decision stage.
- The effective choice is immutable for an in-flight record.
- One `Action Center` sidebar entry replaces the seven-link `Approval Pages` group.
- All owner review, details, Approve, and Reject interactions occur inside the Action Center.
- Old page and notification URLs redirect to the typed Action Center deep link.

### Mandatory stop rule

For each family, the characterization matrix must cite existing source for the OFF actor, authorization, state transition, audit, notification, and downstream effect. If any cell cannot be proven:

1. mark the family `BLOCKED`;
2. do not expose or implement that family's OFF toggle;
3. do not add a fallback role, permission, status, or transition;
4. update a focused design under `docs/superpowers/specs/`;
5. stop Phase 4 execution and request explicit user approval.

### Safety constraints

- Execute only in the existing Phase 3 worktree; do not create another Phase 4 worktree or switch the root worktree's older branch.
- Preserve the Phase 3 worktree's existing untracked generated `public/build/assets/` and `storage/framework/cache/` files. Never delete, clean, or stage them as part of Phase 4.
- Require no tracked Phase 3 worktree changes before execution. If tracked changes appear, stop and inspect ownership before proceeding.
- OFF is not auto-approval and cannot execute payout, apply price, disburse payroll, mutate salary, release purchasing, settle expense, or terminally reject a repair by itself.
- Missing/malformed policy fails safe to owner-required.
- Legacy amount limits may remain stored but cannot override the binary toggle.
- No universal approval table, generic mutation endpoint, new design system, or dependency.
- Domain services remain the only mutation authority.

## File responsibility map

### Workflow policy and snapshots

- `app/Services/ShopOwnerApprovalPolicyService.php` - normalized seven-family policy read.
- `app/Models/ProcurementSettings.php` - default seven-key settings payload.
- `app/Http/Controllers/ShopOwner/ShopSettingsController.php` - settings validation and persistence.
- `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx` - seven binary controls.
- `Approval.approval_roles` - immutable Price, Payslip, and manual Expense role sequence.
- `RepairRequest.requires_owner_approval` - immutable Repair Reject decision.
- Minimal `requires_owner_approval` columns - Refund, Purchase Request, and Salary Adjustment only where characterization confirms no existing immutable equivalent.

### Centralized Action Center

- Existing `app/Services/OwnerActionCenter/` registry/service - count and list projection.
- Existing `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php` - page query and typed item selection validation.
- Existing `resources/js/Pages/ShopOwner/ActionCenter.tsx` - page composition and URL state.
- Existing `resources/js/components/owner-action-center/OwnerAttentionList.tsx` - scannable queue.
- New shared detail shell under `resources/js/components/owner-action-center/` - focus, loading, error, confirmation, and refresh behavior.
- Seven small domain renderers under `resources/js/components/owner-action-center/approvals/` - domain fields and existing endpoints only.

### Compatibility

- `resources/js/layout/AppSidebar_shopOwner.tsx` - one Action Center entry and pending count.
- `routes/web.php` - canonical Action Center, summary endpoint, and legacy redirects.
- Existing domain notification producers - typed Action Center destinations.
- Legacy approval pages - retained until parity passes, then removed while route names remain as redirects.

## Task 0: Continue from the existing Phase 3C worktree

**Files:**
- Read: `docs/shop-owner-phase-3c-rollout-guide.md`
- Read: `docs/superpowers/specs/2026-08-15-shop-owner-phase-3-action-center-master-design.md`
- Read: `docs/superpowers/specs/2026-08-22-shop-owner-phase-4-approval-simplification-design.md`
- Modify: `docs/superpowers/specs/2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md`

- [ ] **Step 1: Enter and verify the existing Phase 3 worktree**

Run:

```powershell
Set-Location 'C:\xampp\htdocs\solespace-master\.worktrees\shop-owner-phase-3-action-center'
git rev-parse --abbrev-ref HEAD
git merge-base --is-ancestor 0124f7228 HEAD
```

Expected: branch is `feat/shop-owner-phase-3-action-center`; ancestry command exits `0`. Do not create or switch to another branch/worktree.

- [ ] **Step 2: Verify tracked cleanliness and record existing untracked artifacts**

Run:

```powershell
git status --short --untracked-files=no
git status --short -- public/build/assets storage/framework/cache
```

Expected: the first command is empty. The second may list the already-generated untracked assets/cache; preserve them. If the first command reports tracked changes, stop before copying or editing anything.

- [ ] **Step 3: Copy the approved planning documents into the Phase 3 worktree**

The spec and plan are currently untracked in the root workspace, so the existing Phase 3 worktree does not contain them. Copy only these two files:

```powershell
Copy-Item -LiteralPath 'C:\xampp\htdocs\solespace-master\docs\superpowers\specs\2026-08-22-shop-owner-phase-4-approval-simplification-design.md' -Destination '.\docs\superpowers\specs\2026-08-22-shop-owner-phase-4-approval-simplification-design.md'
Copy-Item -LiteralPath 'C:\xampp\htdocs\solespace-master\docs\superpowers\plans\2026-08-22-shop-owner-phase-4-approval-simplification.md' -Destination '.\docs\superpowers\plans\2026-08-22-shop-owner-phase-4-approval-simplification.md'
```

Expected: the two approved documents appear as untracked alongside the pre-existing generated artifacts.

- [ ] **Step 4: Verify the expected planning-document status**

Run:

```powershell
git status --short -- docs/superpowers/specs/2026-08-22-shop-owner-phase-4-approval-simplification-design.md docs/superpowers/plans/2026-08-22-shop-owner-phase-4-approval-simplification.md
```

Expected: status lists only the copied spec and plan for these paths.

- [ ] **Step 5: Run the Phase 3C baseline suites**

Run:

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx
```

Expected: PASS. Record any pre-existing failure before changing code.

- [ ] **Step 6: Update the master design reference**

Link the approved Phase 4 spec from the canonical shell master design. Do not change runtime behavior.

- [ ] **Step 7: Commit the baseline authority**

```powershell
git add -- docs/superpowers/specs/2026-08-14-shop-owner-canonical-adaptive-shell-master-design.md docs/superpowers/specs/2026-08-22-shop-owner-phase-4-approval-simplification-design.md docs/superpowers/plans/2026-08-22-shop-owner-phase-4-approval-simplification.md
git commit -m "docs: approve phase 4 centralized approval contract"
```

## Task 1: Prove every OFF path before implementation

**Files:**
- Create: `docs/architecture/shop-owner-phase-4-approval-matrix.md`
- Create only if existing tests cannot prove a transition: `tests/Feature/ShopOwner/ApprovalAuthorityCharacterizationTest.php`
- Read: all family services, controllers, models, policies, routes, migrations, notifications, and focused tests named in Tasks 4-10

- [ ] **Step 1: Create the evidence matrix**

Use this exact row contract for every family:

```markdown
| Family | Entry state | ON owner state/actor | Existing OFF next state | Existing OFF actor | Authorization source | Audit source | Downstream effect | Evidence files/tests | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Refund | ... | ... | ... | ... | ... | ... | ... | ... | PROVEN/BLOCKED |
```

Do not use role names inferred from UI labels. Cite method names and state guards.

- [ ] **Step 2: Trace the Refund and Price candidates**

Use CodeGraph first, then inspect the exact transition methods and tests. Prove that the proposed Finance stage already decides the same request and does not merely execute an owner-approved result.

- [ ] **Step 3: Trace the Payslip and Salary Adjustment candidates**

For Salary Adjustment, a generic `approve-salary-change` permission or HR apply capability is insufficient. Prove an existing non-owner decision method with self-approval protection, or mark `BLOCKED`.

- [ ] **Step 4: Trace Purchase Request, Expense, and Repair Reject candidates**

For each, distinguish an approval decision from operational release/settlement/finalization. Prove the existing actor already owns the decision.

- [ ] **Step 5: Run characterization tests without modifying production code**

Run the nearest existing suites listed in Tasks 4-10. Add characterization-only tests only when current behavior cannot be proven from an existing test.

- [ ] **Step 6: Apply the blocking decision**

Expected: all seven verdicts are `PROVEN`. If any verdict is `BLOCKED`, stop here and create `docs/superpowers/specs/2026-08-22-<family>-off-path-design.md`; do not continue to Task 2.

- [ ] **Step 7: Commit evidence only**

```powershell
git add -- docs/architecture/shop-owner-phase-4-approval-matrix.md
git add -- tests/Feature/ShopOwner/ApprovalAuthorityCharacterizationTest.php
git commit -m "test: characterize phase 4 approval authority"
```

Skip the second `git add` when no new characterization test was needed.

## Task 2: Expand the seven-toggle Settings contract

**Files:**
- Create: `tests/Unit/Services/ShopOwnerApprovalPolicyServiceTest.php`
- Create: `tests/Feature/ShopOwner/ShopOwnerApprovalSettingsTest.php`
- Modify: `tests/Feature/BusinessScaling/ShopSettingsBusinessScalingPayloadTest.php`
- Modify: `app/Models/ProcurementSettings.php`
- Modify: `app/Services/ShopOwnerApprovalPolicyService.php`
- Modify: `app/Http/Controllers/ShopOwner/ShopSettingsController.php`
- Modify: `resources/js/Pages/ShopOwner/Settings/shopSetting.tsx`
- Create: `resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx`

- [ ] **Step 1: Write failing policy tests**

Cover all seven keys with this behavior:

```php
$this->assertTrue($policy->requiresOwnerApprovalForRefund($shopId, 100.00)); // missing/malformed
$this->assertTrue($policy->requiresOwnerApprovalForRefund($shopId, 100.00)); // enabled=true
$this->assertFalse($policy->requiresOwnerApprovalForRefund($shopId, 100.00)); // enabled=false
```

Also assert stored `limit` values do not alter either boolean result.

- [ ] **Step 2: Run the policy test and verify RED**

```powershell
php artisan test tests/Unit/Services/ShopOwnerApprovalPolicyServiceTest.php
```

Expected: FAIL because three keys and the shared binary reader do not exist.

- [ ] **Step 3: Implement the minimal shared binary reader**

Keep public family compatibility methods, but route them through one private whitelist-backed reader:

```php
private const APPROVAL_KEYS = [
    'refund_approval', 'price_approval', 'payslip_approval',
    'salary_adjustment_approval', 'purchase_request_approval',
    'expense_approval', 'repair_reject_approval',
];
```

Unknown internal key: throw `InvalidArgumentException`. Missing/malformed record: return `true`. Valid record: return its boolean `enabled`. New/default keys use `enabled=true`. Do not evaluate `limit`.

- [ ] **Step 4: Write failing Settings feature tests**

Assert seven complete keys are returned, an owner can update only its own settings, unrelated `settings_json` keys survive, invalid booleans return 422, and legacy limits remain stored but are not returned as active controls.

- [ ] **Step 5: Implement backend normalization and validation**

Expand the settings payload and request rules to seven `enabled` booleans. When saving, merge booleans into the existing records so unrelated JSON and stored legacy `limit` values survive; when rendering, expose only active boolean controls. Do not reuse a lossy normalizer for persistence.

- [ ] **Step 6: Write failing Settings UI tests**

Assert seven visible labels, each toggle's accessible name, no amount inputs, and helper text stating: `Changes apply to newly submitted requests. In-progress approvals keep their current workflow.`

- [ ] **Step 7: Implement the seven-control UI**

Reuse the existing `ToggleSwitch`, card, spacing, typography, and dark-mode classes. Do not add a component library or new tokens.

- [ ] **Step 8: Run focused tests**

```powershell
php artisan test tests/Unit/Services/ShopOwnerApprovalPolicyServiceTest.php tests/Feature/ShopOwner/ShopOwnerApprovalSettingsTest.php tests/Feature/BusinessScaling/ShopSettingsBusinessScalingPayloadTest.php
pnpm run test:frontend -- resources/js/Pages/ShopOwner/Settings/__tests__/ApprovalWorkflowSettings.test.tsx
```

Expected: PASS.

- [ ] **Step 9: Commit**

```powershell
git add -- app/Models/ProcurementSettings.php app/Services/ShopOwnerApprovalPolicyService.php app/Http/Controllers/ShopOwner/ShopSettingsController.php resources/js/Pages/ShopOwner/Settings tests/Unit/Services/ShopOwnerApprovalPolicyServiceTest.php tests/Feature/ShopOwner/ShopOwnerApprovalSettingsTest.php tests/Feature/BusinessScaling/ShopSettingsBusinessScalingPayloadTest.php
git commit -m "feat: add seven owner approval toggles"
```

## Task 3: Persist immutable owner-stage decisions

**Files:**
- Create: `database/migrations/<timestamp>_add_owner_approval_snapshots_to_phase_four_workflows.php`
- Modify only if confirmed by Task 1: `app/Models/OrderRefund.php`, `app/Models/PosRefund.php`, `app/Models/PurchaseRequest.php`, `app/Models/HR/SalaryChange.php`
- Verify without schema duplication: `app/Models/Approval.php`, `app/Models/RepairRequest.php`
- Create: `tests/Feature/ShopOwner/ApprovalPolicySnapshotMigrationTest.php`

- [ ] **Step 1: Write a failing schema/backfill test**

Assert only Task 1-confirmed tables gain nullable `requires_owner_approval`; `approvals` and `repair_requests` gain no duplicate column. Seed one record before, at, and after an owner stage and assert the documented conservative backfill.

- [ ] **Step 2: Run the migration test and verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ApprovalPolicySnapshotMigrationTest.php
```

Expected: FAIL because confirmed snapshot columns are absent.

- [ ] **Step 3: Add the minimal migration**

Add nullable booleans first. Backfill from existing states and approval history exactly as recorded in the matrix; do not consult today's Settings for in-flight records. Make `down()` drop only the new columns and never reverse completed effects.

- [ ] **Step 4: Add model fillable/casts**

For each confirmed model:

```php
'requires_owner_approval' => 'boolean',
```

Do not mass-assign this field from a request; services set it server-side.

- [ ] **Step 5: Add ON->OFF and OFF->ON snapshot tests**

Create a workflow under one setting, toggle Settings, then assert the record keeps its original owner-stage decision through later transitions and list queries.

- [ ] **Step 6: Run focused migration/model tests**

```powershell
php artisan test tests/Feature/ShopOwner/ApprovalPolicySnapshotMigrationTest.php tests/Unit/Models/PurchaseRequestTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```powershell
git add -- database/migrations app/Models tests/Feature/ShopOwner/ApprovalPolicySnapshotMigrationTest.php tests/Unit/Models/PurchaseRequestTest.php
git commit -m "feat: snapshot owner approval policy"
```

## Task 4: Implement the proven Refund ON/OFF path

**Files:**
- Modify: `tests/Feature/OrderRefundApprovalWorkflowTest.php`
- Modify: `tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php`
- Modify: `tests/Feature/RepairOnlineRefundWorkflowTest.php`
- Modify: `tests/Feature/RepairPosRefundFlowTest.php`
- Modify only as proven: `app/Services/OrderRefundService.php`, `app/Services/RepairPosRefundService.php`, `app/Services/RepairOnlineRefundWorkflowService.php`, `app/Http/Controllers/Api/RefundApprovalController.php`, `app/Http/Controllers/Api/RepairRefundWorkflowController.php`

- [ ] **Step 1: Re-check the matrix verdict**

If Refund is not `PROVEN`, stop Phase 4. Do not interpret Finance execution as approval authority.

- [ ] **Step 2: Write failing ON/OFF tests**

ON must enter the existing owner-pending state. OFF must enter only the proven existing downstream decision state. Both paths must retain return receipt, refundable amount, payment method, and payout execution gates.

- [ ] **Step 3: Run tests and verify RED only on policy routing**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/RepairPosRefundFlowTest.php
```

- [ ] **Step 4: Persist policy at refund submission**

Resolve Settings once inside the existing creation transaction. Later controller transforms and actions read the stored boolean, never live Settings.

- [ ] **Step 5: Route OFF through the proven transition**

Reuse the exact existing service method and state from the matrix. Do not execute or fake payout. Keep locks and idempotency keys.

- [ ] **Step 6: Add security and replay coverage**

Test wrong shop, generic employee, wrong stage, duplicate action, already-refunded, adjusted amount, and gateway replay.

- [ ] **Step 7: Run and commit**

```powershell
php artisan test tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/RepairPosRefundFlowTest.php
git add -- app/Services/OrderRefundService.php app/Services/RepairPosRefundService.php app/Services/RepairOnlineRefundWorkflowService.php app/Http/Controllers/Api/RefundApprovalController.php app/Http/Controllers/Api/RepairRefundWorkflowController.php app/Models/OrderRefund.php app/Models/PosRefund.php tests/Unit/Refund tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/RepairPosRefundFlowTest.php
git commit -m "feat: apply refund owner approval policy"
```

## Task 5: Implement the proven Price ON/OFF path

**Files:**
- Modify: `tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php`
- Modify: `tests/Feature/Finance/RepairPriceApprovalSmokeTest.php`
- Modify: `tests/Unit/Services/ApprovalWorkflowServicesTest.php`
- Modify only as proven: `app/Services/PriceChangeApprovalService.php`, `app/Http/Controllers/Api/PriceChangeRequestController.php`, `app/Http/Controllers/Api/RepairServiceController.php`, `app/Http/Controllers/Api/RepairPackageController.php`

- [ ] **Step 1: Re-check the Price matrix verdict**

The Finance-only map must already be an authoritative decision/apply path. If not `PROVEN`, stop.

- [ ] **Step 2: Write failing role-map tests**

Assert ON constructs `finance -> shop_owner -> finance_final`; OFF constructs only the proven Finance sequence. Cover product, repair-service, and repair-package requests.

- [ ] **Step 3: Run and confirm RED**

```powershell
php artisan test tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/RepairPriceApprovalSmokeTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
```

- [ ] **Step 4: Build `approval_roles` once at creation**

Use the policy result only when creating `Approval`. Later actions use the stored role map. Remove only shortcuts proven to bypass that map.

- [ ] **Step 5: Protect value integrity**

Test unchanged current price before final Finance action, authoritative proposed value recovery, clean rejection restoration, and duplicate-final-apply prevention.

- [ ] **Step 6: Run and commit**

```powershell
php artisan test tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/RepairPriceApprovalSmokeTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git add -- app/Services/PriceChangeApprovalService.php app/Http/Controllers/Api/PriceChangeRequestController.php app/Http/Controllers/Api/RepairServiceController.php app/Http/Controllers/Api/RepairPackageController.php tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/RepairPriceApprovalSmokeTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git commit -m "feat: apply price owner approval policy"
```

## Task 6: Implement the proven Payslip ON/OFF path

**Files:**
- Modify: `tests/Feature/Finance/PayslipApprovalWorkflowTest.php`
- Modify: `tests/Unit/Services/ApprovalWorkflowServicesTest.php`
- Modify only as proven: `app/Services/PayslipApprovalService.php`, `app/Http/Controllers/Api/Finance/PayslipApprovalController.php`

- [ ] **Step 1: Re-check the Payslip matrix verdict**

Prove the remaining Finance checks are existing decision stages, not merely post-owner disbursement. If not `PROVEN`, stop.

- [ ] **Step 2: Write failing role-order tests**

ON: `finance -> shop_owner -> finance -> finance_final`. OFF: the matrix-proven sequence after removing only `shop_owner`. Assert exact `total_levels`, `current_level`, and role at each action.

- [ ] **Step 3: Run and confirm RED**

```powershell
php artisan test tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
```

- [ ] **Step 4: Construct and persist the role map once**

Do not add payroll schema. Keep `Approval.approval_roles` and `ApprovalHistory` authoritative.

- [ ] **Step 5: Cover identity and finalization**

Test exact owner identity, linked owner identity only if already supported, generic Finance/HR denial at owner level, cross-shop denial, legacy payslips, single/batch behavior, mixed eligibility, stale actions, and disbursement denial before final approval.

- [ ] **Step 6: Run and commit**

```powershell
php artisan test tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git add -- app/Services/PayslipApprovalService.php app/Http/Controllers/Api/Finance/PayslipApprovalController.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git commit -m "feat: apply payslip owner approval policy"
```

## Task 7: Implement the proven Salary Adjustment ON/OFF path

**Files:**
- Create: `tests/Feature/HR/SalaryChangeOwnerApprovalTest.php`
- Modify: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Modify only as proven: `app/Services/HR/SalaryChangeApprovalService.php`, `app/Http/Controllers/Erp/HR/SalaryChangeController.php`, `app/Models/HR/SalaryChange.php`

- [ ] **Step 1: Enforce the strongest stop checkpoint**

Salary Adjustment must be `PROVEN` with an existing non-owner decision method, exact actor authorization, self-approval guard, and audit trail. `approve-salary-change`, HR list access, or HR apply authority alone do not qualify. If any proof is missing, stop Phase 4 and return to focused design.

- [ ] **Step 2: Write ON behavior tests**

Assert only the exact Shop Owner identity can perform the included owner stage within its shop; approval records the actor but does not mutate salary.

- [ ] **Step 3: Write OFF behavior tests from matrix evidence**

Name the already-authoritative method and role in the test. Assert proposer self-review and broader permission-bearing users remain denied.

- [ ] **Step 4: Run and verify RED**

```powershell
php artisan test tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
```

- [ ] **Step 5: Snapshot policy at proposal submission**

Persist server-resolved `requires_owner_approval`. Route ON/OFF only through methods proven by Task 1. Keep HR apply as a separate effective-dated action.

- [ ] **Step 6: Test rejection, replay, and dates**

Cover required reason, cross-shop access, wrong stage, duplicate action, effective date, retroactive controls, and audit actor accuracy.

- [ ] **Step 7: Run and commit**

```powershell
php artisan test tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
git add -- app/Services/HR/SalaryChangeApprovalService.php app/Http/Controllers/Erp/HR/SalaryChangeController.php app/Models/HR/SalaryChange.php tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
git commit -m "feat: apply salary change owner approval policy"
```

## Task 8: Implement the proven Purchase Request ON/OFF path

**Files:**
- Modify: `tests/Unit/Services/PurchaseRequestServiceTest.php`
- Modify: `tests/Unit/Models/PurchaseRequestTest.php`
- Modify: `tests/Feature/Procurement/PurchaseRequestWorkflowTest.php`
- Modify only as proven: `app/Services/PurchaseRequestService.php`, `app/Models/PurchaseRequest.php`, `app/Http/Controllers/ShopOwner/PurchaseRequestController.php`

- [ ] **Step 1: Re-check the Purchase Request verdict**

Prove `pending_finance_final` is an existing decision/release stage and not a synthetic replacement. If not `PROVEN`, stop.

- [ ] **Step 2: Write failing sequence tests**

ON candidate: `pending_finance -> pending_shop_owner -> pending_finance_final -> approved`. OFF candidate: `pending_finance -> pending_finance_final -> approved`. Use only the matrix-approved sequence.

- [ ] **Step 3: Run and verify RED**

```powershell
php artisan test tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php
```

- [ ] **Step 4: Snapshot on submission and branch after Finance initial**

Store the boolean in the existing submit/review transaction. Do not consult live Settings in owner/final routes.

- [ ] **Step 5: Cover bypass and purchasing safety**

Test requester self-review, retired auto-approval behavior, cross-shop access, wrong stage, rejection, replay, and Purchase Order creation restricted to `approved`.

- [ ] **Step 6: Run and commit**

```powershell
php artisan test tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php
git add -- app/Services/PurchaseRequestService.php app/Models/PurchaseRequest.php app/Http/Controllers/ShopOwner/PurchaseRequestController.php tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Models/PurchaseRequestTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php
git commit -m "feat: apply purchase request owner policy"
```

## Task 9: Implement the proven manual Expense ON/OFF path

**Files:**
- Modify: `tests/Feature/Finance/ExpenseApprovalWorkflowTest.php`
- Modify: `tests/Feature/Finance/ExpenseSettlementTest.php`
- Modify: `tests/Unit/Services/ApprovalWorkflowServicesTest.php`
- Modify only as proven: `app/Services/ExpenseApprovalService.php`, `app/Services/ApprovalService.php`, `app/Http/Controllers/Api/Finance/ExpenseController.php`, `app/Http/Controllers/ShopOwner/ExpenseController.php`

- [ ] **Step 1: Re-check the Expense verdict**

Prove Finance-only approval already authoritatively decides manual expenses. Settlement authority alone is insufficient. If not `PROVEN`, stop.

- [ ] **Step 2: Write failing role-map tests**

ON candidate: `finance -> shop_owner`. OFF candidate: matrix-proven Finance sequence. Assert `approval_roles` is frozen at creation.

- [ ] **Step 3: Run and verify RED**

```powershell
php artisan test tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
```

- [ ] **Step 4: Replace live threshold selection only for manual approvals**

Use the binary owner policy when constructing manual Expense roles. Do not change repair high-value behavior or generated procurement/payroll expenses.

- [ ] **Step 5: Cover accounting and settlement safety**

Test wrong tenant/role/stage, rejection, replay, operational source exclusions, and settlement refusal while approval remains pending.

- [ ] **Step 6: Run and commit**

```powershell
php artisan test tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git add -- app/Services/ExpenseApprovalService.php app/Services/ApprovalService.php app/Http/Controllers/Api/Finance/ExpenseController.php app/Http/Controllers/ShopOwner/ExpenseController.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php
git commit -m "feat: apply expense owner approval policy"
```

## Task 10: Implement the proven Repair Reject ON/OFF path

**Files:**
- Create: `tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php`
- Modify: `tests/Feature/Manager/ManagerRepairRejectionTest.php`
- Modify: `tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php`
- Modify only as proven: `app/Http/Controllers/Api/RepairWorkflowController.php`, `app/Http/Controllers/Api/RepairRequestController.php`, `app/Models/RepairRequest.php`

- [ ] **Step 1: Re-check the Repair Reject verdict**

Prove Manager final review already owns the non-owner decision. A terminal finalization endpoint alone is insufficient. If not `PROVEN`, stop.

- [ ] **Step 2: Write failing ON/OFF tests**

ON candidate: repairer rejection -> owner decision -> Manager final review. OFF candidate: repairer rejection -> proven Manager decision. Assert no premature terminal rejection.

- [ ] **Step 3: Run and verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php
```

- [ ] **Step 4: Resolve and persist policy at rejection request**

Continue using `RepairRequest.requires_owner_approval`. Later owner/Manager actions read the snapshot. Reconcile `require_two_way_approval` only according to Task 1 evidence; do not invent precedence.

- [ ] **Step 5: Preserve owner rejection and Manager behavior**

Test owner rejection/reassignment when ON, Manager decision when OFF, required notes, wrong tenant/role/stage, replay, and stale records.

- [ ] **Step 6: Run and commit**

```powershell
php artisan test tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php
git add -- app/Http/Controllers/Api/RepairWorkflowController.php app/Http/Controllers/Api/RepairRequestController.php app/Models/RepairRequest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php tests/Feature/Notifications/RepairRejectForwardToOwnerNotificationTest.php
git commit -m "feat: apply repair rejection owner policy"
```

## Task 11: Project all seven owner-pending families into Action Center

**Files:**
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionItem.php`
- Modify: `app/Support/OwnerActionCenter/OwnerAttentionQuery.php`
- Modify: `resources/js/types/ownerActionCenter.ts`
- Modify: `app/Services/OwnerActionCenter/Adapters/OrderRefundAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/RepairRefundAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/ExpenseAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/Adapters/PurchaseRequestAttentionAdapter.php`
- Create: `app/Services/OwnerActionCenter/Adapters/PriceApprovalAttentionAdapter.php`
- Create: `app/Services/OwnerActionCenter/Adapters/PayslipAttentionAdapter.php`
- Create: `app/Services/OwnerActionCenter/Adapters/SalaryChangeAttentionAdapter.php`
- Create: `app/Services/OwnerActionCenter/Adapters/RepairRejectAttentionAdapter.php`
- Modify: `app/Services/OwnerActionCenter/OwnerAttentionAdapterRegistry.php`
- Modify: `config/owner_action_center.php`
- Create/modify corresponding tests under `tests/Feature/ShopOwner/ActionCenter/`
- Modify: `tests/Unit/Services/OwnerActionCenter/OwnerActionCenterServiceTest.php`
- Modify: `tests/Unit/Support/OwnerActionCenter/OwnerAttentionContractsTest.php`

- [ ] **Step 1: Write failing contract tests for new source types**

Add exact typed sources needed to distinguish detail renderers, including separate product/repair price sources and order/repair refund sources. Map them to seven user-facing coverages: `refunds`, `prices`, `payslips`, `salary_changes`, `purchase_requests`, `expenses`, `repair_rejections`.

- [ ] **Step 2: Define the canonical destination**

Every actionable item must emit:

```text
/shop-owner/action-center?bucket=needs_my_decision&approval=<source_type>:<positive_id>
```

Keep local-path validation and reject unknown source types.

- [ ] **Step 3: Write one failing adapter test per family**

Each test asserts: exact owner-pending state, snapshotted owner requirement, tenant scope, rendered fields, deterministic order, count/list parity, bounded query, and canonical deep link. Add a paired OFF record that is excluded.

- [ ] **Step 4: Run and verify RED**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter
```

- [ ] **Step 5: Implement minimal projections**

Each adapter queries only its domain model and selects only fields used by `OwnerAttentionItem`. Reuse existing adapter helpers/patterns; do not query a universal table or call live Settings.

- [ ] **Step 6: Register all coverage explicitly**

Extend registry/config fail-closed validation. Keep Phase 3 urgent/waiting adapters unchanged.

- [ ] **Step 7: Run security and performance suites**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterPerformanceTest.php
```

Expected: PASS with bounded Home results, paginated full results, and no per-row N+1.

- [ ] **Step 8: Commit**

```powershell
git add -- app/Support/OwnerActionCenter app/Services/OwnerActionCenter config/owner_action_center.php resources/js/types/ownerActionCenter.ts tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter
git commit -m "feat: add all approvals to owner action center"
```

## Task 12: Add typed Action Center selection and safe detail loading

**Files:**
- Modify: `app/Http/Controllers/ShopOwner/OwnerActionCenterController.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Modify only when an existing read endpoint is absent: the corresponding existing domain controller and `routes/web.php`
- Modify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Create: `resources/js/components/owner-action-center/approvalSelection.ts`
- Create: `resources/js/components/owner-action-center/__tests__/approvalSelection.test.ts`

- [ ] **Step 1: Write failing URL parser tests**

Use a small typed parser with these cases:

```ts
parseApprovalSelection("order_refund:123") // { sourceType: "order_refund", sourceId: 123 }
parseApprovalSelection("order_refund:0")   // null
parseApprovalSelection("unknown:123")      // null
parseApprovalSelection("1 OR 1=1")         // null
```

- [ ] **Step 2: Run and verify RED**

```powershell
pnpm run test:frontend -- resources/js/components/owner-action-center/__tests__/approvalSelection.test.ts
```

- [ ] **Step 3: Implement strict parsing on server and client**

Whitelist source types, require a positive integer ID, and keep invalid selection from reaching a query. The page should still render the queue with a clear `This approval link is invalid` message.

- [ ] **Step 4: Inventory existing domain detail reads**

For each source type, reuse an existing tenant-scoped detail endpoint. If a family only exposes a list endpoint, add the smallest `show` method to its existing controller with the same shop/state scope; do not add approval mutation logic to `OwnerActionCenterController`.

- [ ] **Step 5: Add route security tests**

Assert valid own-shop detail, cross-shop 404/403 without record disclosure, unknown type, missing record, completed/stale record, and toggle-OFF record. A completed item may show non-actionable context but never decision controls.

- [ ] **Step 6: Run focused backend/frontend tests**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterSecurityTest.php
pnpm run test:frontend -- resources/js/components/owner-action-center/__tests__/approvalSelection.test.ts
```

- [ ] **Step 7: Commit**

```powershell
git add -- app/Http/Controllers/ShopOwner/OwnerActionCenterController.php routes/web.php resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/components/owner-action-center/approvalSelection.ts resources/js/components/owner-action-center/__tests__/approvalSelection.test.ts tests/Feature/ShopOwner/ActionCenter
git commit -m "feat: add typed action center approval selection"
```

## Task 13: Build the understandable approval workspace

**Files:**
- Modify: `resources/js/Pages/ShopOwner/ActionCenter.tsx`
- Modify: `resources/js/components/owner-action-center/OwnerAttentionList.tsx`
- Create: `resources/js/components/owner-action-center/OwnerApprovalFilters.tsx`
- Create: `resources/js/components/owner-action-center/OwnerApprovalDetailPanel.tsx`
- Create: `resources/js/components/owner-action-center/ApprovalDecisionFooter.tsx`
- Create: `resources/js/components/owner-action-center/approvals/RefundApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvals/PriceApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvals/PayslipApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvals/SalaryAdjustmentApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvals/PurchaseRequestApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvals/ExpenseApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvals/RepairRejectApprovalDetails.tsx`
- Create: `resources/js/components/owner-action-center/approvalPanelRegistry.tsx`
- Modify: `resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx`
- Create: `resources/js/components/owner-action-center/__tests__/OwnerApprovalDetailPanel.test.tsx`
- Create: `resources/js/components/owner-action-center/__tests__/ApprovalDecisionFooter.test.tsx`

- [ ] **Step 1: Write failing filter and list tests**

Assert eligible filters and counts for All plus seven families. Each row must show type/status, subject, amount/impact when relevant, requester/time, urgency, and a labelled `Review` button. Assert no row-level Approve/Reject.

Also assert the existing `Urgent Exceptions` and `Waiting on Others` buckets remain available and unchanged by approval filtering.

- [ ] **Step 2: Write failing responsive shell tests**

Desktop: queue remains visible with a right-side detail region. Mobile: one full-screen dialog/sheet with heading, close control, logical reading order, and unobscured footer.

- [ ] **Step 3: Implement the shared shell using existing styles**

Reuse SoleSpace Tailwind classes, semantic HTML, current icon library, dark mode, spacing, radii, and focus styles. No new theme or dependency.

- [ ] **Step 4: Write failing decision-summary tests**

Every renderer must use the same section order: Decision summary, Request details, Evidence/notes, Workflow/history, Decision footer. Domain renderers omit irrelevant empty sections rather than showing placeholders.

- [ ] **Step 5: Implement seven focused renderers**

Each renderer owns only domain field formatting and calls its already-existing detail/approve/reject endpoints. Shared panel owns loading, stale/error state, focus, and refresh. Do not embed the seven legacy pages.

- [ ] **Step 6: Write failing confirmation/rejection tests**

Approve confirmation restates type, record, and consequence. Reject exposes a visible required label, inline validation, and the domain's maximum reason length. Submitting disables both actions and prevents duplicate requests.

Do not introduce generic cross-family bulk approval. Preserve a domain batch action only if it already applies to the owner stage and passes the same tenant, stage, snapshot, and confirmation checks.

- [ ] **Step 7: Implement safe decision feedback**

On success: announce through `aria-live="polite"`, refresh queue/counts from server, remove the selected item, close the panel, and return focus to the originating row/list heading. Do not auto-open or auto-act on the next record. On 409/422/stale: preserve context and show cause plus `Refresh`.

- [ ] **Step 8: Add accessibility tests**

Assert keyboard open/close, Escape, focus trap/return, visible labels, status text plus icon, 44px targets, unsaved rejection dismissal warning, reduced-motion classes, and no action when owner responsibility is false.

- [ ] **Step 9: Add responsive/browser tests**

Use `webapp-testing` during execution to verify 320, 375, 768, 1024, and 1440 widths, 200% zoom, light/dark mode, no horizontal page scroll, and no covered sticky footer. Save screenshots only as QA evidence.

- [ ] **Step 10: Measure before code splitting**

Run `pnpm run build` and record the Action Center chunk. Lazy-load family renderers only if the seven direct imports materially increase the initial chunk; otherwise keep direct imports.

- [ ] **Step 11: Run frontend gates**

```powershell
pnpm run test:frontend -- resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/components/owner-action-center/__tests__
pnpm run test:frontend
pnpm run build
```

Expected: PASS; build records before/after size rather than claiming an unmeasured improvement.

- [ ] **Step 12: Commit**

```powershell
git add -- resources/js/Pages/ShopOwner/ActionCenter.tsx resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/components/owner-action-center resources/js/types/ownerActionCenter.ts
git commit -m "feat: centralize owner approval decisions"
```

## Task 14: Consolidate navigation, count, notifications, and legacy URLs

**Files:**
- Modify: `resources/js/layout/AppSidebar_shopOwner.tsx`
- Modify: `resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx`
- Create: `app/Http/Controllers/ShopOwner/OwnerActionCenterSummaryController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php`
- Modify: `tests/Feature/BusinessScaling/OwnerErpPageContractTest.php`
- Modify: `tests/Feature/Notifications/NotificationCriticalFlowsTest.php`
- Modify only where needed: existing notification-producing services/controllers for the seven families
- Modify: `resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx`

- [ ] **Step 1: Write failing sidebar tests**

Assert one `Action Center` link, no `Approval Pages` button, no seven approval links, and an accessible pending-count badge when count is positive.

- [ ] **Step 2: Add a bounded summary endpoint**

Return only the existing `summaryForHome(..., 'needs_my_decision')` total under the same owner, rollout, and canonical-shell guards. Do not query seven domains from React or duplicate counting logic.

- [ ] **Step 3: Implement the one-link sidebar**

Fetch the bounded count once per mounted owner layout, show no badge for zero/unavailable, and retain the Action Center link when at least one eligible Action Center capability exists.

- [ ] **Step 4: Write failing notification destination tests**

For each ON family, assert notification URL is the typed Action Center destination. OFF paths send no owner notification and notify only the matrix-proven next actor through existing behavior.

- [ ] **Step 5: Write failing legacy redirect tests**

Cover all seven named routes and old query parameters. Each redirect preserves exact source type/id. Malformed, cross-shop, completed, and OFF links land on a safe queue state without disclosure or action controls.

- [ ] **Step 6: Implement redirects while preserving route names**

Replace legacy owner page GET handlers with tenant-safe redirects. Keep mutation routes unchanged. Do not remove a page component yet.

- [ ] **Step 7: Run route/navigation/notification tests**

```powershell
php artisan test tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php
pnpm run test:frontend -- resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx
```

- [ ] **Step 8: Commit**

```powershell
git add -- app/Http/Controllers/ShopOwner/OwnerActionCenterSummaryController.php routes/web.php resources/js/layout/AppSidebar_shopOwner.tsx resources/js/layout/__tests__/AppSidebar_shopOwner.test.tsx app/Services/NotificationService.php tests/Feature/ShopOwner/ActionCenter/OwnerActionCenterRouteTest.php tests/Feature/BusinessScaling/OwnerErpPageContractTest.php tests/Feature/Notifications/NotificationCriticalFlowsTest.php resources/js/Pages/ShopOwner/Approvals/__tests__/ActionCenterDeepLinks.test.tsx
git commit -m "feat: make action center the approval entry point"
```

If notification URLs are emitted directly from a domain service/controller, stage only those exact files identified by `git diff --name-only`; never stage whole `app/Services` or `app/Http/Controllers` directories.

## Task 15: Retire superseded approval pages only after parity

**Files:**
- Delete only after verification: `resources/js/Pages/ShopOwner/Approvals/refundApproval.tsx`
- Delete only after verification: `resources/js/Pages/ShopOwner/Approvals/PriceApprovals.tsx`
- Delete only after verification: `resources/js/Pages/ShopOwner/Approvals/PayslipApproval.tsx`
- Delete only after verification: `resources/js/Pages/ShopOwner/Approvals/SalaryChangesApproval.tsx`
- Delete only after verification: `resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx`
- Delete only after verification: `resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx`
- Delete only after verification: `resources/js/Pages/ShopOwner/Repairs/repairRejectReview.tsx`
- Modify/delete obsolete page-specific tests and imports only after equivalent centralized coverage exists

- [ ] **Step 1: Complete a seven-family parity checklist**

For every page, compare fields, evidence, notes, history, approve/reject payload, validation, loading, empty, error, stale behavior, and deep link against the centralized renderer.

- [ ] **Step 2: Browser-smoke every family**

With toggle ON, open one owner-pending record by queue and deep link, then exercise confirmation and a non-production-safe test decision. Verify count/list refresh. Do not delete a page with any missing behavior.

- [ ] **Step 3: Remove only proven superseded components**

Keep route names as redirects. Confirm no runtime import or notification references the deleted files before deletion.

- [ ] **Step 4: Run dead-reference scans**

```powershell
rg -n "refundApproval|PriceApprovals|PayslipApproval|SalaryChangesApproval|PurchaseRequestApproval|ExpenseApproval|repairRejectReview" resources/js routes tests
```

Expected: only intentional compatibility test labels remain; no runtime component imports.

- [ ] **Step 5: Run frontend and route suites**

```powershell
pnpm run test:frontend
pnpm run build
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Feature/BusinessScaling/OwnerErpPageContractTest.php
```

- [ ] **Step 6: Commit**

```powershell
git add -- resources/js/Pages/ShopOwner/Approvals/refundApproval.tsx resources/js/Pages/ShopOwner/Approvals/PriceApprovals.tsx resources/js/Pages/ShopOwner/Approvals/PayslipApproval.tsx resources/js/Pages/ShopOwner/Approvals/SalaryChangesApproval.tsx resources/js/Pages/ShopOwner/Approvals/PurchaseRequestApproval.tsx resources/js/Pages/ShopOwner/Approvals/ExpenseApproval.tsx resources/js/Pages/ShopOwner/Repairs/repairRejectReview.tsx
git add -- routes/web.php resources/js/Pages/ShopOwner/Approvals/__tests__ resources/js/Pages/ShopOwner/__tests__/ActionCenter.test.tsx resources/js/components/owner-action-center
git commit -m "refactor: retire separate owner approval pages"
```

## Task 16: Sequential review, verification, and rollout documentation

**Files:**
- Review: all Phase 4 changes since `0124f7228`
- Update: `docs/architecture/shop-owner-phase-4-approval-matrix.md`
- Create: `docs/shop-owner-phase-4-rollout-guide.md`
- Update: `docs/ai-learning-log.md` only for durable, non-sensitive lessons

- [ ] **Step 1: Record the Required Review Stack**

Create a checklist in the rollout guide with one result per applicable item: pass, finding/resolved, N/A, or not run with reason.

- [ ] **Step 2: Apply Ponytail simplification**

Remove duplicate policy reads, obsolete amount-limit UI, superseded page code, unnecessary wrappers, and only orphans created by Phase 4. Keep authorization, validation, locking, accessibility, and recovery behavior.

- [ ] **Step 3: Perform sequential Standards review**

Check Laravel conventions, transactions, query bounds/eager loading, controller/service boundaries, React responsibilities, typed narrowing, readable control flow, and existing Tailwind/component conventions.

- [ ] **Step 4: Perform sequential Spec review**

Walk all 15 approved acceptance criteria and every matrix row. Reconfirm that each OFF implementation points to the exact pre-existing authority cited in Task 1.

- [ ] **Step 5: Perform security and integrity review**

Use `security-review` and relevant `laravel-best-practices` guidance during execution. Verify guard identity, tenant scope, mass-assignment boundaries, request validation, stale/replay handling, row locks, refund/payment idempotency, salary/payroll separation, and audit actors.

- [ ] **Step 6: Perform UI/UX review**

Verify one obvious next action, consistent information hierarchy, consequence copy, no list-row decisions, keyboard/focus flow, 44px targets, screen-reader announcements, contrast, 200% zoom, reduced motion, responsive layout, and recoverable loading/error/stale states.

- [ ] **Step 7: Perform reuse and dead-code audits**

Confirm reuse of all seven domain services, existing mutation routes, Phase 3 adapters/service/result contracts, current icons/styles, and framework features. Scan for live Settings reads after submission, legacy approval destinations, unused imports, stale route component imports, and abandoned TODOs.

- [ ] **Step 8: Run diff hygiene**

```powershell
git diff --check 0124f7228
```

Expected: no whitespace errors.

- [ ] **Step 9: Run focused backend gates**

```powershell
php artisan test tests/Unit/Services/ShopOwnerApprovalPolicyServiceTest.php tests/Unit/Refund/OrderRefundServiceStageWorkflowTest.php tests/Unit/Services/ApprovalWorkflowServicesTest.php tests/Unit/Services/PurchaseRequestServiceTest.php tests/Unit/Models/PurchaseRequestTest.php
php artisan test tests/Feature/ShopOwner/ShopOwnerApprovalSettingsTest.php tests/Feature/ShopOwner/ApprovalPolicySnapshotMigrationTest.php tests/Feature/OrderRefundApprovalWorkflowTest.php tests/Feature/RepairOnlineRefundWorkflowTest.php tests/Feature/Finance/PriceChangeApprovalWorkflowTest.php tests/Feature/Finance/RepairPriceApprovalSmokeTest.php tests/Feature/Finance/PayslipApprovalWorkflowTest.php tests/Feature/HR/SalaryChangeOwnerApprovalTest.php tests/Feature/Procurement/PurchaseRequestWorkflowTest.php tests/Feature/Finance/ExpenseApprovalWorkflowTest.php tests/Feature/Finance/ExpenseSettlementTest.php tests/Feature/ShopOwner/RepairRejectOwnerApprovalTest.php tests/Feature/Manager/ManagerRepairRejectionTest.php
php artisan test tests/Feature/ShopOwner/ActionCenter tests/Unit/Services/OwnerActionCenter tests/Unit/Support/OwnerActionCenter
```

Expected: PASS. Report any baseline failure separately; do not conceal it.

- [ ] **Step 10: Run frontend/build gates**

```powershell
pnpm run test:frontend
pnpm run build
```

Expected: PASS. The repository has no committed standalone TypeScript/lint command; do not claim either passed.

- [ ] **Step 11: Run broader Laravel tests when practical**

```powershell
composer test
```

Expected: PASS or documented unrelated baseline failures with exact test names.

- [ ] **Step 12: Write rollout and rollback guidance**

Document Phase 3C prerequisite, migration order, conservative backfill, cache/config deployment, notification workers, seven-family ON/OFF smoke matrix, Action Center deep links, legacy redirects, monitoring, rollback boundaries, and forward reconciliation for completed money/state effects.

- [ ] **Step 13: Record improvement evidence**

Capture before/after sidebar approval-link count, Action Center bundle size, adapter query assertions, test counts, and browser matrix. Use `not measured` where no baseline exists.

- [ ] **Step 14: Commit documentation**

```powershell
git add -- docs/architecture/shop-owner-phase-4-approval-matrix.md docs/shop-owner-phase-4-rollout-guide.md docs/ai-learning-log.md
git commit -m "docs: add phase 4 approval rollout guidance"
```

- [ ] **Step 15: Stop before integration**

Do not merge, push, deploy, or call the wider Shop Owner program complete without separate user authorization.

## Plan completion criteria

The implementation is complete only when:

1. Task 1 proves every implemented OFF path, with no inferred fallback authority;
2. all seven toggles snapshot safely and in-flight records do not reroute;
3. all seven owner-pending families are actionable in one Action Center;
4. the seven-link Approval Pages sidebar group is gone;
5. legacy URLs redirect safely;
6. domain effects remain protected by their existing authoritative services;
7. accessibility, responsive, security, and verification evidence is recorded;
8. no required review or test result is implied without fresh output.
