# Customer dispute UI, proof preview, and investigation gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Limit new dispute resolutions to supported workflows, require investigation before resolution, and let staff preview delivery proof images from Job Orders.

**Architecture:** Preserve the existing resolution enum and refund request flow for compatibility. Enforce the investigation prerequisite in `DeliveryDisputeService`, mirror it in the Shipment UI, and reuse the existing proof URL plus modal interaction pattern in Job Orders.

**Tech Stack:** Laravel 12/PHP 8.2, PHPUnit, React 18, TypeScript, Inertia, Vitest, Tailwind, Vite.

---

## Task 1: Add failing regression coverage

- [x] Add a feature test proving an open dispute cannot be resolved directly.
- [x] Add Shipment UI coverage for the reduced resolution choices and investigation gate.
- [x] Add Job Orders UI coverage for opening delivery proof in a modal.
- [x] Run the focused tests and confirm they fail for the current implementation.

## Task 2: Implement the minimal behavior changes

- [x] Restrict service resolution to disputes in `investigating` state.
- [x] Hide unsupported resolution choices while retaining legacy backend values.
- [x] Show Resolve only after Start investigation is completed.
- [x] Convert Staff Job Orders proof links into accessible modal triggers and add the proof preview modal.

## Task 3: Verify, review, and prepare the branch

- [x] Run focused backend/frontend tests, PHP syntax checks, `git diff --check`, and the frontend build.
- [x] Review the diff for compatibility, authorization/data-integrity regressions, dead code, and unnecessary complexity.
- [x] Include the generated `public/build` output requested for the deployed preview.
- [x] Commit and push the feature branch for PR creation.
