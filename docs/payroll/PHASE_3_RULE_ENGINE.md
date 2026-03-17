# Phase 3 (Weeks 3-4) Payroll Rule Engine

Implemented Date: 2026-03-17
Coverage: Payroll computation engine

## Core Principle

Monthly base pay is the source of truth. The engine derives rates consistently:

- Daily rate = Monthly base / Work days basis
- Hourly rate = Daily rate / Work hours basis

Default basis values are sourced from branch payroll settings when available, with fallback to:

- Work days basis: 26
- Work hours basis: 8

## Rule Components Implemented

Computed from derived hourly rate:

- Overtime pay (`overtime_hours * hourly_rate * overtime_multiplier`)
- Rest day pay (`rest_day_hours * hourly_rate * rest_day_multiplier`)
- Special holiday pay (`special_holiday_hours * hourly_rate * special_holiday_multiplier`)
- Regular holiday pay (`regular_holiday_hours * hourly_rate * regular_holiday_multiplier`)
- Night differential pay (`night_differential_hours * hourly_rate * night_differential_rate`)

Computed deductions from derived rates:

- Absent day deduction (`absent_days * daily_rate`)
- Undertime deduction (`undertime_hours * hourly_rate`)

## Integration Points

- `PayrollService` now computes and applies rule-engine amounts during payroll generation.
- `PayrollController@store` now accepts Phase 3 hour inputs:
  - `rest_day_hours`
  - `special_holiday_hours`
  - `regular_holiday_hours`
  - `night_differential_hours`
  - `undertime_hours`
- `PayrollBatchController` now uses `PayrollService` for generation to keep rules consistent with single-payroll generation.
- Batch preview now reports derived rates and Phase 3 pay buckets.

## Notes

- Attendance schema currently does not persist explicit rest-day/holiday/night-differential hours.
- Batch preview/generation defaults these to `0` unless supplied through overrides in future endpoint enhancements.
