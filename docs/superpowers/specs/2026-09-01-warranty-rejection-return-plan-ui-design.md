---
title: Warranty rejection and return-plan UI polish
date: 2026-09-01
---

# Warranty rejection and return-plan UI polish

## Goal

Make the two reported UI states clearer without changing the existing API
contracts, payment calculations, or delivery decision rules.

## Warranty Queue

- Keep the claim details dialog focused on evidence and claim information.
- Remove the rejection textarea from the details content.
- Open a second, centered rejection dialog when the repairer clicks `Reject`.
- Keep the selected claim context visible behind the rejection dialog.
- Provide a labeled textarea, Cancel action, and destructive confirmation action.
- Trim and validate the reason before calling the existing reject endpoint with
  the existing `rejection_reason` payload.
- Preserve the existing success/error handling and prevent duplicate submission
  while the request is processing.

## Customer return delivery plan

- Convert internal coverage reason codes such as `outside_coverage` into
  customer-facing labels such as `Outside coverage`.
- Explain that shop-rider delivery is unavailable when the selected address is
  outside coverage, while keeping customer pickup and customer-arranged courier
  options clear.
- Present the delivery fee and final payable amount as distinct, aligned summary
  rows with method-aware labels. Do not change the values used in the existing
  calculation.
- Keep the existing coverage loading/error states and confirmation guards.

## Verification

- Add a Warranty Queue regression test proving Reject opens the reason dialog,
  Cancel does not post, and a valid reason posts the unchanged payload.
- Extend return-logistics tests for the humanized outside-coverage state and the
  method-aware summary.
- Run the focused Vitest files, the frontend suite, the relevant Laravel
  logistics tests, `git diff --check`, and a fresh production build after the
  required rebase.
