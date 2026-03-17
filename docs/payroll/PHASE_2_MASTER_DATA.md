# Phase 2 (Week 2) Master Data Setup

Effective Date: 2026-03-17
Coverage: Cavite shops on SoleSpace platform

## Implemented Master Data Tables

- `hr_position_base_rates`
  - Position and department
  - Monthly base rate
  - Effectivity window (`effective_from`, `effective_to`)
  - Active flag

- `hr_holiday_calendars`
  - Holiday date and name
  - Holiday classification (`regular`, `special_non_working`, `special_working`, `local`)
  - Payroll multiplier
  - Active flag

- `hr_branch_payroll_settings`
  - Branch-level semi-monthly schedule (`pay_day_first`, `pay_day_second`)
  - Workday/hour standards
  - Holiday/overtime/night differential multipliers
  - Non-business-day fallback rule

## Seeder

`PayrollMasterDataSeeder` provisions:

- Core positions including Cashier, Repairer, and Inventory roles
- Base monthly rates aligned to `config/payroll_governance.php` where available
- Philippine holiday calendar entries for the current year
- Branch-specific payroll settings per shop owner

## Runbook

1. Run migrations.
2. Run seeder (`PayrollMasterDataSeeder`) or full database seeder.
3. Validate records per shop owner for positions, holidays, and branch settings.
