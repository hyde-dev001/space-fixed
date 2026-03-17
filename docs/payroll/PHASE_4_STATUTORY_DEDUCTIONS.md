# Phase 4 (Week 5): Statutory Deductions per Payroll Run

## Scope
Implement per-run statutory deductions in payroll generation and recalculation flows:
- SSS (employee share)
- PhilHealth (employee share)
- Pag-IBIG (employee share)
- Withholding Tax (TRAIN/BIR)

All statutory rates are effective-dated and configurable through data.

## Implemented

### 1) Centralized statutory computation in `PayrollService`
- Added `calculateStatutoryDeductions()` for a single entry point to compute all statutory deductions.
- Uses payroll run date (`pay_period_end` fallback-aware) to resolve effective rates.
- Persists statutory values into:
  - `sss_contributions`
  - `philhealth`
  - `pag_ibig`
  - `tax_amount`
  - `total_deductions`
  - legacy compatibility fields: `deductions`, `tax_deductions`

### 2) Effective-date rate resolution
- Added effective-dated lookup support from `finance_tax_rates` (`effective_from`, `effective_to`).
- Added methods for:
  - SSS bracket-based employee share
  - PhilHealth salary floor/ceiling handling
  - Pag-IBIG tiered rates with contribution cap
  - TRAIN monthly withholding bracket handling

### 3) Batch preview alignment
- Updated payroll batch preview to use `PayrollService::calculateStatutoryDeductions()`.
- Ensures preview values match final generated payroll computation.

### 4) Payroll component recalculation alignment
- Updated payroll component total recalculation to include statutory deductions and withholding tax.
- Keeps canonical and legacy deduction columns synchronized.

### 5) Configurable statutory seed data
Added `PayrollStatutoryTaxRateSeeder`:
- Seeds effective-dated `TaxRate` records per shop owner:
  - `PAYROLL_SSS_EE`
  - `PAYROLL_PHILHEALTH_EE`
  - `PAYROLL_PAGIBIG_EE`
  - `PAYROLL_WHT_TRAIN`
- Registered in `DatabaseSeeder`.

## Operational Notes
- To apply seeded defaults:
  - Run database seeders in your environment.
- Future statutory updates should be done by creating new records (or updating records) with new effective dates instead of changing computation code.

## Result
Phase 4 statutory deductions are integrated into payroll run paths with effective-date configurability and cross-flow consistency (single run, batch preview, component recalculation).
