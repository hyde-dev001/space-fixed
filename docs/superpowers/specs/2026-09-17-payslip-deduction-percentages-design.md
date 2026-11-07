# Payslip Deduction Percentages Design

**Date:** 2026-09-17

## Goal

Show the effective percentage of every deduction—SSS, PhilHealth, Pag-IBIG, tax, attendance deductions, loans, and other deductions—relative to the payslip's gross pay in every payslip breakdown surface.

## Definition

For a deduction line, the displayed indicator is:

`absolute deduction amount / gross pay * 100`

The result is rounded to two decimal places and is zero when gross pay is zero. The label will make the denominator explicit: `% of gross pay`. This is an effective payroll-impact percentage, not a replacement for an official statutory contribution rate.

## Architecture

- Reuse the exact amounts already returned by the payroll calculation, saved payroll record, and approval line items.
- Add one small TypeScript payroll-display utility for the zero-safe percentage calculation and formatting so all four pages use the same rule.
- Do not add a database column or alter SSS, PhilHealth, Pag-IBIG, tax, attendance, or approval calculations; the percentage is derived at presentation time.

## Surfaces

- My Payslips: deduction table shows amount and `% of gross pay`, including the total deduction percentage; print output uses the same columns.
- Generate Payslip: single-payroll preview shows each deduction and percentage, plus total deduction percentage; batch rows show total deductions with the percentage beside the amount.
- Finance Payslip Approval: approval modal shows each deduction percentage and total percentage against the selected payslip gross pay.
- Shop Owner Payslip Approval: owner action-center payslip details show the same per-line percentage and total percentage.

## Edge cases

- Zero or missing gross pay displays `0.00%` rather than dividing by zero.
- Negative/serialized deduction values are displayed using their absolute value for the percentage, while existing negative currency presentation remains unchanged.
- Existing payloads without a line-item array keep their current summary behavior; no fabricated deduction rows are introduced.

## Verification

- Unit-test the shared percentage helper for normal, zero-gross, serialized, and negative values.
- Update the four existing layout/renderer checks to assert the percentage label is present.
- Run focused Vitest suites, the focused payroll Laravel suite, `pnpm run build`, and `git diff --check`.
