# Phase 1 (Week 1) Payroll Policy and Governance

Effective Date: 2026-03-17
Jurisdiction: Philippines
Coverage: Cavite shops on SoleSpace platform

## 1) Policy Scope and Payroll Calendar

### Scope
- Applies to all Cavite operations in this platform.
- Payroll frequency is semi-monthly.
- Standard payroll release dates: 15th and 30th.

### Cutoff and Release
- Cutoff A: 1st to 15th, released on 30th.
- Cutoff B: 16th to end of month, released on 15th of next month.

### Non-Business Day Rule
- If release date falls on weekend/holiday, release on previous business day.
- Exceptions require Shop Owner approval and audit trail.

## 2) Job-Based Base Pay Table (Governance)

The approved monthly base pay per position is controlled by config and seed defaults.

### Required Columns
- Position
- Grade/Level
- Monthly Base Pay
- Effectivity Date
- Branch Coverage
- Approving Authority

Initial position rates are stored in:
- `config/payroll_governance.php` (`base_pay_table`)

Seeder alignment is implemented in:
- `database/seeders/EmployeeSeeder.php`

## 3) Maker-Checker Workflow

- Maker: HR prepares payroll.
- Checker: Finance validates computation and deductions.
- Final Approver: Shop Owner authorizes release.
- No payroll release without checker and final approver.

## 4) Salary Change Approval Matrix

### New Hire Rate Setup
- Proposed by: HR
- Approved by: Shop Owner

### Minor Adjustment (<= 5%)
- Proposed by: HR
- Checked by: Finance
- Approved by: Shop Owner

### Major Adjustment (> 5%)
- Recommended by: HR and Finance
- Approved by: Shop Owner (mandatory)

## 5) Salary Change Controls (System Enforced)

When salary is changed in employee update flows, the following fields are required:
- effective date
- reason
- approver

Salary change events are recorded with:
- previous and new salary
- effective date
- reason
- approver identity
- percentage change
- audit timestamp

Implemented in:
- `app/Http/Controllers/Erp/HR/EmployeeController.php`
- `app/Http/Controllers/EmployeeController.php`

## 6) Required End-of-Week Outputs

- Signed payroll governance policy
- Signed base pay table by position
- Signed maker-checker approval matrix
- Signed salary change control matrix and request template
- Go-live checklist for Phase 2 data setup

## 7) Sign-Off Sheet

- HR Lead: ____________________  Date: __________
- Finance Lead: ________________ Date: __________
- Shop Owner: _________________  Date: __________

## 8) Done Definition

- No salary change can be applied without effective date, reason, and approver.
- No payroll can be released without checker and final approver.
- All active positions have approved monthly base pay values.
