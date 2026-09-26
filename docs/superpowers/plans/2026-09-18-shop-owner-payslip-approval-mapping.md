# Shop Owner Payslip Approval Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow a seeded Shop Owner to final-approve payslips even when no ERP `users` row exists yet.

**Architecture:** Keep the existing `ShopOwnerActorUserResolver` self-healing flow. Make its proxy user use the MySQL-compatible legacy `STAFF` role, and cover the missing-mapping approval path with a regression test. Preserve the existing shop-owner guard authorization; employee accounts remain separate actors.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit, SQLite test database, MySQL-compatible `users.role` enum.

---

### Task 1: Add the failing regression test

**Files:**
- Modify: `tests/Feature/Finance/PayslipApprovalWorkflowTest.php`

- [x] Delete the setup-only ERP mapping, approve a legacy payslip through Finance, then final-approve through the Shop Owner guard.
- [x] Assert the resolver creates a linked actor with `role = STAFF`.
- [x] Run the focused test and confirm it fails because the current resolver creates `Shop Owner`.

### Task 2: Make proxy mapping schema-compatible

**Files:**
- Modify: `app/Services/ShopOwnerActorUserResolver.php`

- [x] Use `STAFF` when filling or creating the canonical ERP actor, keeping Shop Owner authorization on the dedicated `shop_owner` guard.
- [x] Run the focused regression and existing payslip workflow tests.

### Task 3: Review and verify

**Files:**
- Review: changed files and branch diff

- [x] Run `git diff --check`.
- [x] Run the full `PayslipApprovalWorkflowTest` file.
- [x] Re-read the diff for authorization, tenant scoping, and unrelated changes.
