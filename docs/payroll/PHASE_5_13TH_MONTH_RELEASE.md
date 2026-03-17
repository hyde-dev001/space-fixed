# Phase 5 (Week 6): 13th-Month Accrual + Controlled December Release

## Scope
Implement:
- Monthly 13th-month accrual logic
- Controlled December release process
- Reconciliation report for accrued vs released values

## Implemented

### 1) Monthly accrual logic (service + ledger)
- `PayrollService` now persists monthly 13th-month accrual into a dedicated ledger table.
- Accrual source remains the payroll component:
  - `13th Month Pay (Accrual)`
  - Amount: `basic_salary / 12`
- New table: `hr_thirteenth_month_accruals` (employee/year/month granularity).

### 2) Non-payable accrual treatment
- 13th-month accrual component is now marked `affects_gross = false`.
- This prevents accrual from inflating monthly gross/net pay while still being visible as tracked accrual data.

### 3) Controlled release process (December)
- New service method: `releaseThirteenthMonth(...)`
- Key controls:
  - December-only release by default
  - Optional override with explicit flag (`allow_non_december`)
  - Checker/final-approver governance respected from `config/payroll_governance.php`
- Release output:
  - Creates/updates payroll earning component `THIRTEENTH_RELEASE`
  - Updates payroll totals (`gross_salary`, `bonus`, `net_salary`) by release delta
  - Writes release metadata to the 13th-month ledger

### 4) Reconciliation report
- New service report method: `getThirteenthMonthReconciliationReport(...)`
- Report includes:
  - Per-employee monthly breakdown
  - Year totals: accrued, released, remaining balance
  - December release component check and variance field

### 5) New API endpoints
- `POST /api/hr/payroll/13th-month/release`
- `GET /api/hr/payroll/13th-month/reconciliation?year=YYYY`

## Data model
### New migration
- `2026_03_17_000002_create_hr_thirteenth_month_accruals_table.php`

### New model
- `App\Models\HR\ThirteenthMonthAccrual`

## Operational Notes
- Run migrations before using endpoints.
- Release endpoint requires approval-level authorization.
- Recommended release flow:
  1. Ensure December payroll exists and checker step is complete.
  2. Execute controlled release endpoint.
  3. Validate totals via reconciliation endpoint.
